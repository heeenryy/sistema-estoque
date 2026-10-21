<?php
/**
 * Funções auxiliares do sistema.
 */

declare(strict_types=1);

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['ok' => false, 'message' => $message], $status);
}

function json_ok(array $data = []): void
{
    json_response(array_merge(['ok' => true], $data));
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Gera um código de referência único para documentos de saída/entrada.
 * Ex.: OUT-20260824-0001
 */
function gen_ref_code(string $prefix = 'MOV'): string
{
    $date = date('Ymd');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM movements WHERE ref_code LIKE :prefix');
    $stmt->execute(['prefix' => $prefix . '-' . $date . '-%']);
    $next = (int) $stmt->fetchColumn() + 1;
    return sprintf('%s-%s-%04d', $prefix, $date, $next);
}

/**
 * Normaliza um código de barras (remove espaços e tabs extras que o leitor injeta).
 */
function clean_barcode(string $code): string
{
    return trim(preg_replace('/[\s]+/', '', $code));
}

/**
 * Valida/obtém um item pelo código ou id.
 * Retorna o registro do item ou null.
 */
function find_item(string $codeOrId): ?array
{
    $pdo = db();
    $code = clean_barcode($codeOrId);

    if (ctype_digit($code) && strlen($code) <= 18) {
        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = :id OR code = :code LIMIT 1');
        $stmt->execute(['id' => $code, 'code' => $code]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM items WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
    }

    $item = $stmt->fetch();
    return $item ?: null;
}

/**
 * Atualiza a data de modificação e registra movimentação.
 * Opcionalmente registra o usuário responsável.
 */
function register_movement(int $itemId, string $itemCode, string $itemName, string $type, float $quantity, string $refCode, string $note = '', ?int $userId = null, string $userName = ''): void
{
    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO movements (type, ref_code, item_id, item_code, item_name, quantity, note, user_id, user_name)
        VALUES (:type, :ref_code, :item_id, :item_code, :item_name, :quantity, :note, :user_id, :user_name)
    ');
    $stmt->execute([
        'type' => $type,
        'ref_code' => $refCode,
        'item_id' => $itemId,
        'item_code' => $itemCode,
        'item_name' => $itemName,
        'quantity' => $quantity,
        'note' => $note,
        'user_id' => $userId,
        'user_name' => $userName,
    ]);

    $pdo->prepare('UPDATE items SET updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $itemId]);
}

function cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (request_method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
