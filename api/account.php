<?php
/**
 * API da conta do usuário logado.
 * GET  /api/account.php        -> dados do usuário atual
 * PUT  /api/account.php        -> alterar a própria senha
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$method = request_method();

if ($method === 'GET') {
    $user = require_auth();
    json_ok(['user' => public_user($user)]);
}

if ($method === 'PUT') {
    $user = require_permission('account.manage');
    $d = body_json();

    $currentPassword = (string) ($d['current_password'] ?? '');
    $newPassword = (string) ($d['new_password'] ?? '');
    $confirmPassword = (string) ($d['confirm_password'] ?? '');

    if (!password_verify($currentPassword, $user['password_hash'])) {
        json_error('Senha atual incorreta.');
    }
    if ($newPassword === '') {
        json_error('Informe a nova senha.');
    }
    if ($newPassword !== $confirmPassword) {
        json_error('A confirmação da nova senha não confere.');
    }
    $pwErr = validate_password($newPassword);
    if ($pwErr) {
        json_error($pwErr);
    }

    db()->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id")
        ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);

    json_ok(['message' => 'Senha alterada com sucesso!']);
}

json_error('Método não suportado', 405);
