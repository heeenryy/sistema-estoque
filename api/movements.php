<?php
/**
 * API de Movimentações (entrada e saída de estoque).
 * Suporta lote de itens via leitor de código de barras.
 * O leitor é tratado como entrada de dados; quem decide é o backend (auth + permissões).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    $current = require_permission('history.view');

    $type = trim($_GET['type'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $where = [];
    $params = [];

    if (in_array($type, ['in', 'out', 'adjust'], true)) {
        $where[] = 'movements.type = :type';
        $params['type'] = $type;
    }
    if ($search !== '') {
        $where[] = '(movements.item_code LIKE :search OR movements.item_name LIKE :search OR movements.ref_code LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM movements $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * FROM movements
        $whereSql
        ORDER BY movements.id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_ok(['movements' => $stmt->fetchAll(), 'total' => $total]);
}

if ($method === 'POST') {
    $d = body_json();
    $type = $d['type'] ?? '';
    $items = $d['items'] ?? [];
    $note = trim($d['note'] ?? '');

    // Permissão conforme o tipo
    $current = require_permission($type === 'in' ? 'movement.in' : 'movement.out');

    if (!in_array($type, ['in', 'out'], true)) {
        json_error('Tipo de movimentação inválido. Use "in" ou "out".');
    }

    if (!is_array($items) || count($items) === 0) {
        json_error('Nenhum item informado. Escaneie ou digite pelo menos um código.');
    }

    $operator = user_display_name($current);

    $refCode = gen_ref_code($type === 'in' ? 'IN' : 'OUT');
    $results = [];
    $errors = [];

    foreach ($items as $entry) {
        if (is_string($entry)) {
            $code = clean_barcode($entry);
            $quantity = 1;
        } elseif (is_array($entry)) {
            $code = clean_barcode($entry['code'] ?? '');
            $quantity = (float) ($entry['quantity'] ?? 1);
        } else {
            continue;
        }

        if ($code === '') {
            continue;
        }

        $item = find_item($code);

        if (!$item) {
            $errors[] = "Código $code não cadastrado.";
            continue;
        }

        if ($quantity <= 0) {
            $errors[] = "Quantidade inválida para {$item['name']}.";
            continue;
        }

        if ($type === 'out') {
            $newQty = (float) $item['quantity'] - $quantity;
            if ($newQty < 0) {
                $errors[] = "Estoque insuficiente para {$item['name']} (disponível: {$item['quantity']}).";
                continue;
            }
        } else {
            $newQty = (float) $item['quantity'] + $quantity;
        }

        $pdo->prepare('UPDATE items SET quantity = :qty, updated_by = :by, updated_at = NOW() WHERE id = :id')
            ->execute(['qty' => $newQty, 'by' => $current['id'], 'id' => $item['id']]);

        register_movement(
            (int) $item['id'], $item['code'], $item['name'], $type, $quantity,
            $refCode, $note, (int) $current['id'], $operator
        );

        $results[] = [
            'id' => (int) $item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'quantity' => $quantity,
            'new_quantity' => $newQty,
        ];
    }

    if (count($results) === 0) {
        json_error('Nenhum item pôde ser processado. ' . implode(' ', $errors));
    }

    json_ok([
        'ref_code' => $refCode,
        'processed' => $results,
        'errors' => $errors,
        'operator' => $operator,
        'message' => count($results) . ' item(ns) processado(s).' . (count($errors) ? ' Avisos: ' . implode(' ', $errors) : ''),
    ], count($errors) ? 207 : 200);
}

json_error('Método não suportado', 405);
