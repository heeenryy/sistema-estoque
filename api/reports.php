<?php
/**
 * API de Relatórios.
 * GET /api/reports.php
 *   - period_start (YYYY-MM-DD), period_end, type (in/out), user_id, item_id, search
 * Retorna resumo + movimentações filtradas.
 * GET /api/reports.php?action=by_item&period_start&period_end
 *   - Agregação por produto (entradas, saídas, saldo no período).
 * GET /api/reports.php?action=low_stock
 *   - Produtos com estoque baixo (para relatório de reposição).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$user = require_permission('reports.view');
$pdo = db();
$method = request_method();

if ($method !== 'GET') {
    json_error('Método não suportado', 405);
}

$action = $_GET['action'] ?? 'movements';

/**
 * Monta os filtros WHERE comuns.
 */
function report_filters(array $params): array
{
    $where = [];
    $bind = [];

    if (!empty($params['period_start'])) {
        $where[] = "date(movements.created_at) >= :period_start";
        $bind['period_start'] = $params['period_start'];
    }
    if (!empty($params['period_end'])) {
        $where[] = "date(movements.created_at) <= :period_end";
        $bind['period_end'] = $params['period_end'];
    }
    if (in_array($params['type'] ?? '', ['in', 'out'], true)) {
        $where[] = 'movements.type = :type';
        $bind['type'] = $params['type'];
    }
    if (!empty($params['user_id']) && ctype_digit((string) $params['user_id'])) {
        $where[] = 'movements.user_id = :user_id';
        $bind['user_id'] = (int) $params['user_id'];
    }
    if (!empty($params['item_id']) && ctype_digit((string) $params['item_id'])) {
        $where[] = 'movements.item_id = :item_id';
        $bind['item_id'] = (int) $params['item_id'];
    }
    if (!empty($params['search'])) {
        $where[] = '(movements.item_code LIKE :search OR movements.item_name LIKE :search OR movements.ref_code LIKE :search)';
        $bind['search'] = '%' . $params['search'] . '%';
    }

    return [$where, $bind];
}

if ($action === 'low_stock') {
    $items = $pdo->query('
        SELECT id, code, name, category, unit, quantity, min_stock, price, location
        FROM items
        WHERE quantity <= min_stock
        ORDER BY (quantity - min_stock) ASC
    ')->fetchAll();

    json_ok([
        'title' => 'Produtos com estoque baixo',
        'items' => $items,
        'total_low' => count($items),
    ]);
}

if ($action === 'by_item') {
    [$where, $bind] = report_filters([
        'period_start' => $_GET['period_start'] ?? '',
        'period_end' => $_GET['period_end'] ?? '',
        'type' => '',
        'user_id' => '',
        'item_id' => $_GET['item_id'] ?? '',
        'search' => $_GET['search'] ?? '',
    ]);
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT
            movements.item_id,
            movements.item_code,
            movements.item_name,
            movements.item_id AS id,
            COALESCE(SUM(CASE WHEN movements.type = 'in' THEN movements.quantity ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN movements.type = 'out' THEN movements.quantity ELSE 0 END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN movements.type = 'in' THEN movements.quantity ELSE -movements.quantity END), 0) AS balance,
            COUNT(*) AS movements_count
        FROM movements
        $whereSql
        GROUP BY movements.item_id, movements.item_code, movements.item_name
        ORDER BY movements.item_name ASC
    ");
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();

    json_ok([
        'title' => 'Movimentações por produto',
        'items' => $stmt->fetchAll(),
    ]);
}

// default: movimentações detalhadas + resumo
[$where, $bind] = report_filters([
    'period_start' => $_GET['period_start'] ?? '',
    'period_end' => $_GET['period_end'] ?? '',
    'type' => $_GET['type'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'item_id' => $_GET['item_id'] ?? '',
    'search' => $_GET['search'] ?? '',
]);
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Resumo
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_movements,
        COALESCE(SUM(CASE WHEN movements.type = 'in' THEN movements.quantity ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN movements.type = 'out' THEN movements.quantity ELSE 0 END), 0) AS total_out,
        COUNT(DISTINCT movements.user_id) AS total_users
    FROM movements
    $whereSql
");
foreach ($bind as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$summary = $stmt->fetch();

// Movimentações (limit 1000)
$stmt = $pdo->prepare("
    SELECT movements.id, movements.type, movements.ref_code,
           movements.item_code, movements.item_name, movements.quantity,
           movements.note, movements.created_at,
           movements.user_id, movements.user_name
    FROM movements
    $whereSql
    ORDER BY movements.id DESC
    LIMIT 1000
");
foreach ($bind as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$movements = $stmt->fetchAll();

// Lista de usuários para o filtro
$users = $pdo->query('SELECT id, name, email, active FROM users ORDER BY name ASC')->fetchAll();

json_ok([
    'title' => 'Relatório de movimentações',
    'summary' => $summary,
    'movements' => $movements,
    'users' => array_map('public_user', $users),
]);
