<?php
/**
 * API de Itens (CRUD + busca).
 * Requer autenticação. Criação/edição exigem permissões de perfil.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$method = request_method();
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$segments = array_values(array_filter(explode('/', $path)));

$id = null;
foreach ($segments as $seg) {
    if (ctype_digit($seg)) {
        $id = (int) $seg;
    }
}

$pdo = db();

if ($method === 'GET' && $id !== null) {
    $current = require_permission('items.view');
    $item = find_item((string) $id);
    if (!$item) {
        json_error('Item não encontrado', 404);
    }
    json_ok(['item' => $item]);
}

if ($method === 'GET') {
    $current = require_permission('items.view');

    $search = trim($_GET['search'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $low = isset($_GET['low']) && $_GET['low'] !== '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(items.code LIKE :search OR items.name LIKE :search OR items.location LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $where[] = 'items.category = :category';
        $params['category'] = $category;
    }
    if ($low) {
        $where[] = 'items.quantity <= items.min_stock';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * FROM items
        $whereSql
        ORDER BY items.name ASC
        LIMIT :per OFFSET :off
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('per', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll();

    $cats = $pdo->query('SELECT DISTINCT category FROM items WHERE category != \'\' ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

    json_ok([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'categories' => $cats,
    ]);
}

if ($method === 'POST') {
    $current = require_permission('items.create');
    $d = body_json();

    $code = clean_barcode($d['code'] ?? '');
    $name = trim($d['name'] ?? '');
    $category = trim($d['category'] ?? '');
    $unit = trim($d['unit'] ?? 'un');
    $quantity = (float) ($d['quantity'] ?? 0);
    $minStock = (float) ($d['min_stock'] ?? 0);
    $price = (float) ($d['price'] ?? 0);
    $location = trim($d['location'] ?? '');

    if ($code === '') {
        json_error('O código é obrigatório.');
    }
    if ($name === '') {
        json_error('O nome é obrigatório.');
    }

    if (find_item($code) !== null) {
        json_error('Já existe um item com o código "' . $code . '".');
    }

    $stmt = $pdo->prepare('
        INSERT INTO items (code, name, category, unit, quantity, min_stock, price, location, created_by, updated_by)
        VALUES (:code, :name, :category, :unit, :quantity, :min_stock, :price, :location, :by, :by)
    ');
    $stmt->execute([
        'code' => $code,
        'name' => $name,
        'category' => $category,
        'unit' => $unit,
        'quantity' => $quantity,
        'min_stock' => $minStock,
        'price' => $price,
        'location' => $location,
        'by' => $current['id'],
    ]);

    $newId = (int) $pdo->lastInsertId();

    if ($quantity > 0) {
        register_movement(
            $newId, $code, $name, 'in', $quantity,
            gen_ref_code('IN'), 'Cadastro inicial',
            (int) $current['id'], user_display_name($current)
        );
    }

    $item = find_item((string) $newId);
    json_ok(['item' => $item, 'message' => 'Item adicionado com sucesso!'], 201);
}

if ($method === 'PUT' && $id !== null) {
    $current = require_permission('items.edit');
    $item = find_item((string) $id);
    if (!$item) {
        json_error('Item não encontrado', 404);
    }

    $d = body_json();
    $code = clean_barcode($d['code'] ?? $item['code']);
    $name = trim($d['name'] ?? $item['name']);
    $category = trim($d['category'] ?? $item['category']);
    $unit = trim($d['unit'] ?? $item['unit']);
    $minStock = (float) ($d['min_stock'] ?? $item['min_stock']);
    $price = (float) ($d['price'] ?? $item['price']);
    $location = trim($d['location'] ?? $item['location']);

    if ($name === '') {
        json_error('O nome é obrigatório.');
    }

    $dup = find_item($code);
    if ($dup && (int) $dup['id'] !== $id) {
        json_error('Já existe outro item com o código "' . $code . '".');
    }

    $stmt = $pdo->prepare('
        UPDATE items
        SET code = :code, name = :name, category = :category, unit = :unit,
            min_stock = :min_stock, price = :price, location = :location,
            updated_by = :by, updated_at = NOW()
        WHERE id = :id
    ');
    $stmt->execute([
        'code' => $code,
        'name' => $name,
        'category' => $category,
        'unit' => $unit,
        'min_stock' => $minStock,
        'price' => $price,
        'location' => $location,
        'by' => $current['id'],
        'id' => $id,
    ]);

    json_ok(['item' => find_item((string) $id), 'message' => 'Item atualizado com sucesso!']);
}

if ($method === 'DELETE' && $id !== null) {
    $current = require_permission('items.delete');
    $item = find_item((string) $id);
    if (!$item) {
        json_error('Item não encontrado', 404);
    }

    $pdo->prepare('DELETE FROM items WHERE id = :id')->execute(['id' => $id]);
    json_ok(['message' => 'Item removido com sucesso!']);
}

json_error('Método não suportado', 405);
