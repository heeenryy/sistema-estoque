# Guia de Instalação e Uso no WSL (Windows Subsystem for Linux)

Este sistema é PHP + MySQL. Não precisa de Node ou Composer.
Roda direto no PHP embutido, que já vem com o PHP CLI.

---

## 1. Instalar o PHP e o MySQL no WSL

Abra o terminal do WSL (Ubuntu) e execute:

```bash
sudo apt update
sudo apt install -y php-cli php-mysql mariadb-server
```

Verifique:

```bash
php --version
php -m | grep -i pdo_mysql
```

Se aparecer `pdo_mysql`, o driver está pronto.

---

## 2. Configurar o banco de dados

Inicie o MariaDB e crie o banco:

```bash
sudo service mariadb start
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS estoque CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

O sistema usa por padrão o usuário `root` sem senha (padrão do MariaDB no WSL).
Para outro usuário/senha, crie o arquivo `.env`:

```bash
cp .env.example .env
```

E edite o `.env` com seus dados:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=estoque
DB_USER=root
DB_PASS=
```

> O banco, as tabelas e o usuário administrador são criados automaticamente na
> primeira execução do sistema.

---

## 3. Rodar o sistema

```bash
cd estoque
./start.sh
```

Ou manualmente:

```bash
php -S 0.0.0.0:8000 router.php
```

Acesse no navegador do Windows:

```
http://localhost:8000
```

Na primeira vez, entre com o usuário administrador padrão:

```
E-mail: admin@estoque.com
Senha:  admin123
```

> Dica: use `PORT=8080 ./start.sh` para mudar a porta.
> Para encerrar, pressione `Ctrl+C`.

---

## 3. Perfis de acesso

O sistema tem dois perfis:

- **Administrador:** acesso total — estoque, movimentações, relatórios e
  **gerenciamento de usuários** (criar, editar, desativar, redefinir senha).
- **Operador:** estoque, entrada/saída, histórico, relatórios, etiquetas e
  troca da própria senha. **Não** gerencia usuários.

> O administrador **não vê senhas** dos usuários. Para dar acesso a um operador,
> crie o usuário com uma senha inicial ou use **Redefinir senha** no modal de
> edição — o sistema gera uma senha temporária para repassar ao usuário.

---

## 4. Usando o leitor de código de barras

A maioria dos leitores USB funciona como um **teclado**: ele digita o código
rapidamente e envia `Enter`. O sistema detecta isso automaticamente.

1. Plugue o leitor USB.
2. Deixe o cursor no campo de escaneamento (a tela de Entrada/Saída mantém o foco).
3. Aponte o leitor para o código de barras. O item é adicionado na hora.
4. Leia o próximo item — o campo continua focado.

**Tela de Saída (F3):** o leitor dá saída nos itens e gera um documento com
código de barras próprio (ex.: `OUT-20260824-0001`), pronto para imprimir.

**Configuração do leitor (opcional):** configure o leitor para terminar com
sufixo CR (Enter). A maioria já vem assim de fábrica.

---

## 6. Impressão de etiquetas com código de barras

1. Abra a aba **Etiquetas**.
2. Escaneie/digite o código do produto e a quantidade de etiquetas.
3. Clique em **Imprimir etiquetas** — as etiquetas com código de barras
   são geradas no navegador (biblioteca JsBarcode, salva localmente).

Para imprimir em impressora de etiquetas, use o diálogo de impressão do
navegador e selecione a impressora térmica (ex.: 50x30mm).

---

## 7. Estrutura de arquivos

```
estoque/
├── index.php              # Frontend (SPA leve)
├── router.php             # Roteador do servidor embutido
├── start.sh               # Script de inicialização
├── api/
│   ├── auth.php           # Login / sessão / logout
│   ├── users.php          # Gestão de usuários (admin)
│   ├── account.php        # Troca da própria senha
│   ├── items.php          # CRUD de itens
│   ├── movements.php      # Entrada/Saída
│   ├── dashboard.php      # Estatísticas
│   └── reports.php        # Relatórios
├── includes/
│   ├── db.php             # Conexão MySQL + migrações + seed
│   ├── auth.php           # Sessão, permissões, bloqueio de login
│   └── functions.php      # Helpers
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── vendor/JsBarcode.all.min.js
├── .env.example           # Modelo de configuração do MySQL
├── tests/seed_test.php    # Gera banco de teste (estoque_test)
├── data/                  # (vazio no MySQL — mantido para compatibilidade)
└── docs/                  # Documentação (guia-wsl.md, PROJETO.md)
```

---

## 8. Banco de dados

O sistema usa **MySQL/MariaDB** (banco `estoque`, criado automaticamente no
primeiro acesso, junto com o usuário administrador padrão).

Para recriar o banco do zero:

```bash
sudo mariadb -e "DROP DATABASE IF EXISTS estoque; CREATE DATABASE estoque CHARACTER SET utf8mb4;"
```

> O banco de teste completo (20 itens, movimentações e 3 usuários) pode ser
> gerado separadamente — **sem tocar no banco real** — com:
> `php tests/seed_test.php` (cria/recria o banco `estoque_test`).

---

## 9. Atalhos de teclado

| Tecla | Ação              |
|-------|-------------------|
| F2    | Tela de Entrada   |
| F3    | Tela de Saída     |
| F4    | Tela de Estoque   |
| F5    | Tela de Histórico |

---

## 10. Solução de problemas

**"php: command not found"** → instale o PHP (passo 1).

**"Driver pdo_mysql não encontrado"** → `sudo apt install php-mysql` e reinicie o PHP.

**"Access denied for user" / não conecta no banco** → verifique o `.env`
(host, porta, usuário, senha) e se o MariaDB está no ar: `sudo service mariadb status`.

**"Unknown database 'estoque'"** → crie o banco (passo 2).

**"Acesso negado no `./start.sh`"** → `chmod +x start.sh`.

**Porta já em uso** → mude a porta: `PORT=9000 ./start.sh`.

**Leitor não adiciona o item** → verifique se o cursor está no campo de
escaneamento e se o leitor está configurado para enviar Enter (CR).
