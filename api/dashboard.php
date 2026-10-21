<?php
/**
 * API de Dashboard (estatísticas).
 * Requer autenticação.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$current = require_permission('dashboard.view');
$pdo = db();

$totalItems = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
$totalUnits = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM items')->fetchColumn();
$lowStock = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= min_stock')->fetchColumn();
$totalValue = (float) $pdo->query('SELECT COALESCE(SUM(quantity * price), 0) FROM items')->fetchColumn();

$today = date('Y-m-d');
$todayIn = (float) $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM movements WHERE type='in' AND DATE(created_at) = '$today'")->fetchColumn();
$todayOut = (float) $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM movements WHERE type='out' AND DATE(created_at) = '$today'")->fetchColumn();

// Itens com estoque baixo
$lowItems = $pdo->query("
    SELECT id, code, name, quantity, min_stock, unit
    FROM items
    WHERE quantity <= min_stock
    ORDER BY (quantity - min_stock) ASC
    LIMIT 10
")->fetchAll();

// Últimas movimentações
$recent = $pdo->query('
    SELECT * FROM movements
    ORDER BY id DESC
    LIMIT 8
')->fetchAll();

// Por categoria
$byCategory = $pdo->query('
    SELECT category, COUNT(*) as items, COALESCE(SUM(quantity),0) as qty
    FROM items
    GROUP BY category
    ORDER BY items DESC
')->fetchAll();

json_ok([
    'stats' => [
        'total_items' => $totalItems,
        'total_units' => round($totalUnits, 3),
        'low_stock' => $lowStock,
        'total_value' => round($totalValue, 2),
        'today_in' => $todayIn,
        'today_out' => $todayOut,
    ],
    'low_items' => $lowItems,
    'recent_movements' => $recent,
    'by_category' => $byCategory,
]);
