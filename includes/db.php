<?php
/**
 * Camada de banco de dados - MySQL via PDO.
 * A conexão é configurada por variáveis de ambiente (ou arquivo .env):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 * O banco e as tabelas são criados automaticamente na primeira execução.
 * Inclui migração incremental e seed do usuário administrador.
 */

declare(strict_types=1);

function db_config(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'estoque',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ];
}

/**
 * Abre uma conexão PDO com o MySQL.
 */
function db_connect(?string $database = null): PDO
{
    $c = db_config();
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c['host'], $c['port']);
    if ($database !== null) {
        $dsn .= ';dbname=' . $database;
    }
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Mantém o fuso do servidor PHP alinhado ao MySQL (NOW())
    $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
    return $pdo;
}

/**
 * Conexão padrão, garantindo que o banco exista.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = db_config();

    // Garante que o banco de dados existe (conexão sem dbname)
    $admin = db_connect();
    $admin->exec(
        'CREATE DATABASE IF NOT EXISTS `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo = db_connect($c['name']);
    return $pdo;
}

/**
 * Lista as colunas existentes de uma tabela.
 */
function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ');
    $stmt->execute(['t' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, table_columns($pdo, $table), true);
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i
    ');
    $stmt->execute(['t' => $table, 'i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function db_init(): void
{
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            code        VARCHAR(50) NOT NULL UNIQUE,
            name        VARCHAR(255) NOT NULL,
            category    VARCHAR(100) NOT NULL DEFAULT '',
            unit        VARCHAR(20) NOT NULL DEFAULT 'un',
            quantity    DOUBLE NOT NULL DEFAULT 0,
            min_stock   DOUBLE NOT NULL DEFAULT 0,
            price       DOUBLE NOT NULL DEFAULT 0,
            location    VARCHAR(255) NOT NULL DEFAULT '',
            created_by  INT NULL,
            updated_by  INT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS movements (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            type        ENUM('in','out','adjust') NOT NULL,
            ref_code    VARCHAR(30) NOT NULL,
            item_id     INT NOT NULL,
            item_code   VARCHAR(50) NOT NULL,
            item_name   VARCHAR(255) NOT NULL,
            quantity    DOUBLE NOT NULL,
            note        VARCHAR(500) NOT NULL DEFAULT '',
            user_id     INT NULL,
            user_name   VARCHAR(255) NOT NULL DEFAULT '',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_movements_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(255) NOT NULL,
            email         VARCHAR(191) NOT NULL UNIQUE,
            username      VARCHAR(100) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role          ENUM('admin','operator') NOT NULL DEFAULT 'operator',
            active        TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            email      VARCHAR(191) NOT NULL,
            ip         VARCHAR(45) NOT NULL DEFAULT '',
            success    TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Índices (evita erro em bancos já existentes)
    if (!index_exists($pdo, 'items', 'idx_items_code')) {
        $pdo->exec('CREATE INDEX idx_items_code ON items(code)');
    }
    if (!index_exists($pdo, 'movements', 'idx_movements_item')) {
        $pdo->exec('CREATE INDEX idx_movements_item ON movements(item_id)');
    }
    if (!index_exists($pdo, 'movements', 'idx_movements_type')) {
        $pdo->exec('CREATE INDEX idx_movements_type ON movements(type)');
    }
    if (!index_exists($pdo, 'movements', 'idx_movements_user')) {
        $pdo->exec('CREATE INDEX idx_movements_user ON movements(user_id)');
    }
    if (!index_exists($pdo, 'login_attempts', 'idx_login_attempts_email')) {
        $pdo->exec('CREATE INDEX idx_login_attempts_email ON login_attempts(email)');
    }

    db_migrate($pdo);
    db_seed($pdo);
}

/**
 * Migrações incrementais (preserva dados existentes).
 */
function db_migrate(PDO $pdo): void
{
    // Atribuição de usuário nos itens
    if (!column_exists($pdo, 'items', 'created_by')) {
        $pdo->exec('ALTER TABLE items ADD COLUMN created_by INT NULL');
    }
    if (!column_exists($pdo, 'items', 'updated_by')) {
        $pdo->exec('ALTER TABLE items ADD COLUMN updated_by INT NULL');
    }

    // Atribuição de usuário nas movimentações
    if (!column_exists($pdo, 'movements', 'user_id')) {
        $pdo->exec('ALTER TABLE movements ADD COLUMN user_id INT NULL');
    }
    if (!column_exists($pdo, 'movements', 'user_name')) {
        $pdo->exec("ALTER TABLE movements ADD COLUMN user_name VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

/**
 * Cria o usuário administrador padrão na primeira execução.
 * E-mail: admin@estoque.com  Senha: admin123
 */
function db_seed(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->prepare('
        INSERT INTO users (name, email, username, password_hash, role, active)
        VALUES (:name, :email, :username, :hash, :role, 1)
    ')->execute([
        'name' => 'Administrador',
        'email' => 'admin@estoque.com',
        'username' => 'admin',
        'hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'admin',
    ]);
}

/**
 * Popula itens de exemplo (uso opcional / testes).
 * Não é chamado automaticamente: o banco real inicia vazio.
 */
function db_seed_items(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $sample = [
        ['7891000100103', 'Arroz Branco 5kg',        'Alimentos', 'un', 120, 20, 26.90, 'Corredor A'],
        ['7891000100202', 'Feijão Carioca 1kg',      'Alimentos', 'un', 85,  15, 8.50,  'Corredor A'],
        ['7891000100309', 'Óleo de Soja 900ml',      'Alimentos', 'un', 60,  12, 7.90,  'Corredor B'],
        ['7891910000197', 'Coca-Cola Lata 350ml',    'Bebidas',   'un', 200, 30, 4.50,  'Corredor C'],
        ['7891910000029', 'Água Mineral 500ml',      'Bebidas',   'un', 0,   24, 2.20,  'Corredor C'],
        ['7896005000012', 'Sabão em Pó 1kg',         'Limpeza',   'un', 40,  8,  15.80, 'Corredor D'],
        ['7896005000029', 'Detergente Neutro 500ml', 'Limpeza',   'un', 55,  10, 3.40,  'Corredor D'],
        ['7896005000036', 'Água Sanitária 1L',       'Limpeza',   'un', 12,  10, 4.20,  'Corredor D'],
    ];

    $stmt = $pdo->prepare('
        INSERT INTO items (code, name, category, unit, quantity, min_stock, price, location)
        VALUES (:code, :name, :category, :unit, :quantity, :min_stock, :price, :location)
    ');

    foreach ($sample as $row) {
        $stmt->execute([
            'code' => $row[0],
            'name' => $row[1],
            'category' => $row[2],
            'unit' => $row[3],
            'quantity' => $row[4],
            'min_stock' => $row[5],
            'price' => $row[6],
            'location' => $row[7],
        ]);
    }
}
