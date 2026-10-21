# Sistema de Estoque (Estoque V3)

Sistema de controle de inventário com **entrada/saída de mercadorias**, **leitor de
código de barras**, **impressão de etiquetas** e **relatórios**, com **autenticação
de usuários** e **controle de permissões por perfil** (admin/operador).

- **Backend:** PHP 8 (puro, sem framework)
- **Banco de dados:** MySQL/MariaDB via PDO (banco `estoque`, criado automaticamente)
- **Frontend:** HTML/CSS/JS puro (SPA leve), JsBarcode (vendor local)
- **Idioma da interface:** Português (pt-BR)
- **Servidor:** PHP embutido (`php -S`) — zero configuração

---

## 1. Recursos

### Autenticação e usuários
- Login com e-mail ou nome de usuário + senha (hash seguro `password_hash`)
- Sessão com cookie `ESTOQUE_SESSID` (HttpOnly, SameSite=Lax, Secure em HTTPS)
- Bloqueio temporário após 5 tentativas de login inválidas em 15 minutos
- Troca da própria senha (aba "Minha conta")
- Admin gerencia usuários: criar, editar, desativar, redefinir senha temporária
- Histórico de última movimentações por usuário no modal de edição
- Desativação preserva o histórico (nunca exclui fisicamente)

### Perfis e permissões
- **Admin:** acesso total, incl. gerenciamento de usuários e permissões
- **Operador:** estoque, entrada/saída, histórico, relatórios, etiquetas e conta
- Sistema preparado para novos perfis: basta estender o mapa `ROLE_PERMISSIONS`
  em `includes/auth.php`

### Estoque
- Cadastro de itens com código de barras único, categoria, unidade, preço,
  estoque mínimo e localização
- Entrada e saída por leitor de código de barras ou digitação
- Saída registra quem executou (usuário) e gera código de referência
  próprio (ex.: `OUT-20260824-0001`) imprimível
- Impressão de etiquetas com código de barras (JsBarcode)

### Relatórios
- Movimentações com filtros (período, tipo, usuário, item, busca)
- Resumo por item (entradas/saídas/saldo)
- Alerta de estoque baixo (abaixo do `min_stock`)
- Exportação CSV gerada no navegador

---

## 2. Estrutura de arquivos

```
estoque/
├── index.php                  # Frontend: login + app (SPA) + modais
├── router.php                 # Roteador do servidor embutido
├── start.sh                   # Inicializa o servidor PHP
├── api/
│   ├── auth.php               # POST login, GET status, DELETE logout
│   ├── users.php              # CRUD de usuários (admin), reset de senha
│   ├── account.php            # Troca da própria senha
│   ├── items.php              # CRUD de itens
│   ├── movements.php          # Entrada/Saída/Ajuste
│   ├── dashboard.php          # Estatísticas do painel
│   └── reports.php            # Relatórios + CSV
├── includes/
│   ├── db.php                 # Conexão MySQL, schema, migrações, seed
│   ├── auth.php               # Sessão, login, rate-limit, permissões
│   └── functions.php          # Helpers (movimentação, códigos, etc.)
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── vendor/JsBarcode.all.min.js
├── .env.example             # Modelo de configuração do MySQL (copie para .env)
├── tests/seed_test.php      # Gera banco de teste (estoque_test)
└── docs/
    ├── guia-wsl.md          # Instalação no WSL
    └── PROJETO.md           # Este documento
```

---

## 3. Banco de dados

MySQL/MariaDB, banco `estoque` (configurável via `.env`). O banco, as tabelas e o
usuário administrador padrão são criados automaticamente no primeiro acesso.

### Configuração (`.env`)

Copie `.env.example` para `.env` e ajuste:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=estoque
DB_USER=root
DB_PASS=
```

Se você rodar com `./start.sh`, o `.env` é carregado automaticamente. Sem ele,
use variáveis de ambiente (`export DB_USER=...`) ou o arquivo `.env`.

### Tabelas

#### `items`
| Coluna       | Tipo    | Descrição                          |
|--------------|---------|------------------------------------|
| id           | INT     | PK autoincrement                    |
| code         | VARCHAR(50) | Código de barras (UNIQUE)       |
| name         | VARCHAR(255) | Nome do produto                 |
| category     | VARCHAR(100) | Categoria (ex.: Alimentos)     |
| unit         | VARCHAR(20) | Unidade (un, kg, cx...)         |
| quantity     | DOUBLE  | Quantidade atual em estoque         |
| min_stock    | DOUBLE  | Estoque mínimo (alerta)             |
| price        | DOUBLE  | Preço                               |
| location     | VARCHAR(255) | Localização (corredor/prateleira) |
| created_by   | INT     | FK users.id (quem criou) — migração |
| updated_by   | INT     | FK users.id (quem alterou) — migração |
| created_at   | DATETIME | Data de criação                   |
| updated_at   | DATETIME | Data de atualização               |

#### `movements`
| Coluna     | Tipo    | Descrição                                    |
|------------|---------|----------------------------------------------|
| id         | INT     | PK autoincrement                              |
| type       | ENUM    | `in`, `out` ou `adjust`                       |
| ref_code   | VARCHAR(30) | Código de referência (ex.: `OUT-20260824-0001`) |
| item_id    | INT     | FK items.id (ON DELETE RESTRICT)              |
| item_code  | VARCHAR(50) | Cópia do código (estabilidade do histórico) |
| item_name  | VARCHAR(255) | Cópia do nome                            |
| quantity   | DOUBLE  | Quantidade movimentada                        |
| note       | VARCHAR(500) | Observação                                 |
| user_id    | INT     | Quem executou (FK users.id) — migração        |
| user_name  | VARCHAR(255) | Cópia do nome do usuário                  |
| created_at | DATETIME | Data da movimentação                       |

#### `users`
| Coluna        | Tipo    | Descrição                                |
|---------------|---------|------------------------------------------|
| id            | INT     | PK autoincrement                          |
| name          | VARCHAR(255) | Nome completo                        |
| email         | VARCHAR(191) | E-mail (UNIQUE, usado no login)       |
| username      | VARCHAR(100) | Nome de usuário alternativo (UNIQUE)  |
| password_hash | VARCHAR(255) | Hash `password_hash()` — senha nunca em texto |
| role          | ENUM    | `admin` ou `operator`                      |
| active        | TINYINT | 1 ativo / 0 desativado                    |
| last_login_at | DATETIME | Último acesso                          |
| created_at    | DATETIME | Data de criação                        |
| updated_at    | DATETIME | Data de atualização                    |

#### `login_attempts`
| Coluna     | Tipo    | Descrição                     |
|------------|---------|-------------------------------|
| id         | INT     | PK autoincrement               |
| email      | VARCHAR(191) | E-mail tentado            |
| ip         | VARCHAR(45) | IP de origem               |
| success    | TINYINT | 0 = falha, 1 = sucesso         |
| created_at | DATETIME | Data                          |

### Migrações

O schema é criado com `CREATE TABLE IF NOT EXISTS` e o `db_migrate()` adiciona
colunas novas incrementalmente (`ALTER TABLE ... ADD COLUMN`) quando ausentes —
**preservando dados de instalações antigas**. A checagem de colunas usa
`INFORMATION_SCHEMA.COLUMNS` (nativo do MySQL). Para adicionar um campo, insira a
checagem em `db_migrate()` em `includes/db.php`.

### Seed automático

- `db_seed()`: se a tabela `users` estiver vazia, cria o administrador padrão:
  `admin@estoque.com` / `admin123`
- O banco real inicia **vazio** (nenhum item/movimentação); `db_seed_items()` é
  uma função opcional para popular itens de exemplo em ambientes de demonstração.

### Banco de teste

```bash
php tests/seed_test.php
```

**Atenção:** o script usa um banco SEPARADO — `estoque_test` (configurável via
`DB_TEST_NAME`) — e **não altera o banco real** do projeto. Ele o recria com
20 itens, 17 movimentações e 3 usuários:

| E-mail             | Senha       | Perfil    |
|--------------------|-------------|-----------|
| admin@estoque.com  | admin123    | Administrador |
| joao@estoque.com   | operador123 | Operador  |
| maria@estoque.com  | operador123 | Operador  |

Para usar o banco de teste na aplicação, aponte `DB_NAME` para `estoque_test`
no `.env` (ex.: em ambiente de desenvolvimento).

---

## 4. Regras de permissões

Mapa em `includes/auth.php`:

```php
const ROLE_PERMISSIONS = [
    'admin' => ['*'],
    'operator' => [
        'dashboard.view',
        'items.view',
        'items.create',
        'items.edit',
        'movement.in',
        'movement.out',
        'history.view',
        'reports.view',
        'labels.create',
        'account.manage',
    ],
];
```

- `admin` tem `*` (todas as permissões)
- Novos perfis: adicione a chave no mapa e as permissões desejadas
- As abas do frontend são filtradas por `data-perm` e o backend valida
  via `require_permission()` — a regra vale nos dois lados

---

## 5. Regras de negócio do estoque

- **Entrada** (`movement.in`): soma `quantity` ao item e grava `movements` com `type='in'`
- **Saída** (`movement.out`): subtrai `quantity` do item e grava `movements` com `type='out'`
- **Estoque negativo:** a saída é recusada se a quantidade exceder o disponível
- **Código único:** o campo `code` de `items` é UNIQUE; duplicatas são rejeitadas
- **Códigos de referência** (`gen_ref_code()` em `includes/functions.php`) seguem
  o padrão `TIPO-AAAAMMDD-NNNN` (ex.: `OUT-20260824-0001`)
- **Histórico imutável:** itens/movimentações guardam cópia de `item_code`/`item_name`
  e `user_id`/`user_name`, então o histórico permanece legível mesmo após
  alterações posteriores no cadastro
- **Usuários desativados** não podem mais logar, mas as movimentações antigas
  continuam com o nome gravado

---

## 6. Segurança

- Senhas apenas como hash (`password_hash` / `password_verify`); nenhum endpoint
  retorna o hash; o admin gera senha temporária nova (nunca vê a atual)
- Sessão: cookie `ESTOQUE_SESSID` com HttpOnly, SameSite=Lax, Secure quando HTTPS;
  `session_regenerate_id(true)` no login
- Rate-limit de login: 5 falhas em 15 minutos → bloqueio temporário (HTTP 429),
  contabilizado por e-mail
- Toda movimentação registra `user_id`/`user_name` (rastreabilidade)
- Validações de negócio no backend (as regras nunca dependem só do frontend)
- Validação de senha mínima (6 caracteres)

---

## 7. Instalação e execução

Ver guia detalhado do WSL em `docs/guia-wsl.md`.

```bash
sudo apt update
sudo apt install -y php-cli php-mysql mariadb-server

sudo mariadb -e "CREATE DATABASE IF NOT EXISTS estoque CHARACTER SET utf8mb4;"

cd estoque
cp .env.example .env   # ajuste DB_USER/DB_PASS se necessário
./start.sh
# ou: php -S 0.0.0.0:8000 router.php
```

Acesse `http://localhost:8000` e entre com `admin@estoque.com` / `admin123`.
O banco e as tabelas são criados automaticamente na primeira execução.

---

## 8. API (resumo)

| Método | Rota                     | Descrição                          | Permissão       |
|--------|--------------------------|------------------------------------|-----------------|
| POST   | `/api/auth.php`          | Login (identifier + password)      | pública         |
| GET    | `/api/auth.php`          | Status da sessão / usuário atual   | autenticado     |
| DELETE | `/api/auth.php`          | Logout                             | autenticado     |
| GET    | `/api/users.php`         | Lista usuários                     | admin           |
| POST   | `/api/users.php`         | Cria usuário                       | admin           |
| GET    | `/api/users.php/{id}`    | Detalhe + últimas movimentações    | admin           |
| PUT    | `/api/users.php/{id}`    | Atualiza usuário                   | admin           |
| POST   | `/api/users.php/{id}/password` | Redefine senha (temporária)   | admin           |
| DELETE | `/api/users.php/{id}`    | Desativa usuário                   | admin           |
| PUT    | `/api/account.php`       | Troca a própria senha              | autenticado     |
| GET/POST/PUT/DELETE | `/api/items.php` | CRUD de itens                | items.view/etc. |
| POST   | `/api/movements.php`     | Registra entrada/saída/ajuste       | movement.in/out |
| GET    | `/api/dashboard.php`     | Estatísticas do painel              | dashboard.view  |
| GET    | `/api/reports.php`       | Movimentações, por item, estoque baixo, CSV | reports.view |

Todas as respostas são JSON `{ "ok": true/false, ... }`. Erros usam códigos HTTP:
`401` (não autenticado), `403` (sem permissão), `429` (bloqueio por tentativas),
`400`/`404` (dados inválidos/ausentes).

---

## 9. Como estender

### Novo perfil (ex.: "supervisor")
1. Em `includes/auth.php`, adicione a chave ao `ROLE_PERMISSIONS`:
   ```php
   'supervisor' => [ 'dashboard.view', 'items.view', 'reports.view', 'history.view' ],
   ```
2. Em `index.php`, o `select` de perfil do modal de usuário aceita a string
   (adicione a opção `<option>`).
3. O frontend filtra abas por `data-perm`; o backend valida com
   `require_permission()` — funcionam automaticamente para o novo perfil.

### Novo campo em itens
1. Adicione `ALTER TABLE items ADD COLUMN ...` em `db_migrate()` (`includes/db.php`).
2. Atualize o modal/cadastro em `index.php` e o `app.js`.
3. Adapte o `api/items.php` para persistir o novo campo.

### Novo relatório
Crie um bloco em `api/reports.php` seguindo o padrão `action=...` existente,
retorne JSON e monte a tabela no frontend dentro da aba "Relatórios".

---

## 10. Testes manuais

- `php -l` em todos os arquivos PHP para checar sintaxe
- `php tests/seed_test.php` para regenerar o banco de teste
- Validação E2E via navegador headless (Playwright) foi utilizada para os
  fluxos: login (erro/correto), abas por perfil, entrada/saída, histórico,
  relatórios, usuários, conta e logout
