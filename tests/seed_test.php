<?php
/**
 * Gera uma base de dados de TESTE com itens, histórico e usuários.
 * Uso: php tests/seed_test.php
 *
 * IMPORTANTE: usa um banco SEPARADO do banco real da aplicação
 * (padrão: estoque_test). O banco real do projeto não é alterado.
 * Configure com DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS conforme necessário.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// Força o banco de teste (nunca o banco real)
$testDb = getenv('DB_TEST_NAME') ?: 'estoque_test';
putenv('DB_NAME=' . $testDb);

$c = db_config();

// Conecta sem dbname para criar o banco de teste
$admin = db_connect();
$admin->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
$admin->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Banco de teste '$testDb' recriado.\n";

// Cria o schema
db_init();
$pdo = db();

// O db_init() já insere o admin padrão; como recriamos o banco, limpamos
// as tabelas antes de inserir os dados de teste.
$pdo->exec('DELETE FROM users');

// --- Usuários ---
$users = [
    ['Administrador', 'admin@estoque.com', 'admin', 'admin123', 'admin'],
    ['João Operador', 'joao@estoque.com', 'joao', 'operador123', 'operator'],
    ['Maria Operadora', 'maria@estoque.com', 'maria', 'operador123', 'operator'],
];

$userIdByName = [];
$stmt = $pdo->prepare('
    INSERT INTO users (name, email, username, password_hash, role, active)
    VALUES (:name, :email, :username, :hash, :role, 1)
');
foreach ($users as $u) {
    $stmt->execute([
        'name' => $u[0], 'email' => $u[1], 'username' => $u[2],
        'hash' => password_hash($u[3], PASSWORD_DEFAULT), 'role' => $u[4],
    ]);
    $userIdByName[$u[1]] = (int) $pdo->lastInsertId();
}

// --- Itens ---
$items = [
    ['7891000100103', 'Arroz Branco 5kg',           'Alimentos', 'un', 120, 20, 26.90,  'Corredor A'],
    ['7891000100202', 'Feijão Carioca 1kg',         'Alimentos', 'un', 85,  15, 8.50,   'Corredor A'],
    ['7891000100309', 'Óleo de Soja 900ml',         'Alimentos', 'un', 60,  12, 7.90,   'Corredor B'],
    ['7891000100406', 'Macarrão Espaguete 500g',    'Alimentos', 'un', 140, 25, 4.30,   'Corredor B'],
    ['7891000100503', 'Açúcar Refinado 1kg',        'Alimentos', 'un', 12,  20, 4.90,   'Corredor A'],
    ['7891910000197', 'Refrigerante Cola Lata 350ml','Bebidas',  'un', 200, 30, 4.50,   'Corredor C'],
    ['7891910000029', 'Água Mineral 500ml',         'Bebidas',   'un', 0,   24, 2.20,   'Corredor C'],
    ['7891910000036', 'Suco de Laranja 1L',         'Bebidas',   'un', 45,  10, 6.90,   'Corredor C'],
    ['7891910000043', 'Cerveja Lata 350ml',         'Bebidas',   'un', 72,  24, 3.80,   'Corredor C'],
    ['7896005000012', 'Sabão em Pó 1kg',            'Limpeza',   'un', 40,  8,  15.80,  'Corredor D'],
    ['7896005000029', 'Detergente Neutro 500ml',    'Limpeza',   'un', 55,  10, 3.40,   'Corredor D'],
    ['7896005000036', 'Água Sanitária 1L',          'Limpeza',   'un', 9,   10, 4.20,   'Corredor D'],
    ['7896005000043', 'Papel Toalha 2un',           'Limpeza',   'un', 30,  6,  8.90,   'Corredor D'],
    ['7896005000050', 'Esponja de Limpeza',         'Limpeza',   'un', 8,   10, 2.10,   'Corredor D'],
    ['7891234560010', 'Caderno 96 Folhas',          'Papelaria', 'un', 50,  10, 12.50,  'Corredor E'],
    ['7891234560027', 'Caneta Azul',                'Papelaria', 'un', 200, 40, 2.00,   'Corredor E'],
    ['7891234560034', 'Lápis Preto',                'Papelaria', 'un', 150, 30, 1.50,   'Corredor E'],
    ['7891234560041', 'Borracha Branca',            'Papelaria', 'un', 0,   20, 1.20,   'Corredor E'],
    ['7899876540011', 'Pilha AA (par)',             'Eletrônicos','par', 5,  15, 12.00, 'Gaveta 2'],
    ['7899876540028', 'Fone de Ouvido',             'Eletrônicos','un', 25, 5,  45.00,  'Gaveta 3'],
];

$itemIds = [];
$stmt = $pdo->prepare('
    INSERT INTO items (code, name, category, unit, quantity, min_stock, price, location, created_by)
    VALUES (:code, :name, :category, :unit, :quantity, :min_stock, :price, :location, :by)
');
foreach ($items as $row) {
    $stmt->execute([
        'code' => $row[0], 'name' => $row[1], 'category' => $row[2],
        'unit' => $row[3], 'quantity' => $row[4], 'min_stock' => $row[5],
        'price' => $row[6], 'location' => $row[7],
        'by' => $userIdByName['admin@estoque.com'],
    ]);
    $itemIds[$row[0]] = (int) $pdo->lastInsertId();
}

// --- Movimentações nos últimos 7 dias ---
function mk(PDO $pdo, array $ids, array $users, string $type, string $code, float $qty, string $note, int $daysAgo, string $email): void
{
    if (!isset($ids[$code])) return;

    $id = (int) $ids[$code];
    $name = $pdo->prepare('SELECT name FROM items WHERE id = :id');
    $name->execute(['id' => $id]);
    $itemName = $name->fetchColumn();

    $prefix = $type === 'in' ? 'IN' : 'OUT';
    $ref = sprintf('%s-%s-%04d', $prefix, date('Ymd', strtotime("-{$daysAgo} days")), random_int(1, 9999));
    $ts = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days") + random_int(0, 36000));

    $stmt = $pdo->prepare('
        INSERT INTO movements (type, ref_code, item_id, item_code, item_name, quantity, note, user_id, user_name, created_at)
        VALUES (:type, :ref, :item_id, :item_code, :item_name, :quantity, :note, :user_id, :user_name, :created_at)
    ');
    $stmt->execute([
        'type' => $type, 'ref' => $ref, 'item_id' => $id,
        'item_code' => $code, 'item_name' => $itemName, 'quantity' => $qty,
        'note' => $note, 'user_id' => $users[$email], 'user_name' => $email,
        'created_at' => $ts,
    ]);
}

$u = $userIdByName;

mk($pdo, $itemIds, $u, 'in',  '7891000100103', 60, 'Reposição fornecedor', 6, 'admin@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891000100103', 15, 'Venda balcão', 6, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'in',  '7891910000197', 100, 'Compra mensal', 5, 'admin@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891910000197', 40, 'Venda atacado', 5, 'maria@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891000100202', 22, 'Venda balcão', 4, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'in',  '7896005000012', 25, 'Reposição', 4, 'admin@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7896005000012', 18, 'Venda balcão', 3, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'in',  '7891910000029', 48, 'Reposição fornecedor', 3, 'maria@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891910000029', 30, 'Venda balcão', 2, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891910000029', 18, 'Consumo interno', 2, 'admin@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891234560010', 10, 'Venda escola', 2, 'maria@estoque.com');
mk($pdo, $itemIds, $u, 'in',  '7891000100406', 80, 'Compra fornecedor', 1, 'admin@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7891000100406', 25, 'Venda balcão', 1, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7899876540011', 7,  'Venda', 1, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7896005000036', 6,  'Venda balcão', 0, 'maria@estoque.com');
mk($pdo, $itemIds, $u, 'out', '7896005000050', 12, 'Venda', 0, 'joao@estoque.com');
mk($pdo, $itemIds, $u, 'in',  '7891000100503', 30, 'Reposição', 0, 'admin@estoque.com');

echo "Base de teste criada: $testDb\n";
echo "Itens: " . count($items) . "\n";
echo "Usuários: " . count($users) . "\n";
echo "Movimentações: " . $pdo->query('SELECT COUNT(*) FROM movements')->fetchColumn() . "\n";
echo "\nAcessos:\n";
echo "  admin@estoque.com / admin123 (Administrador)\n";
echo "  joao@estoque.com / operador123 (Operador)\n";
echo "  maria@estoque.com / operador123 (Operador)\n";
