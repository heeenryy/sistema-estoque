<?php
/**
 * API de Autenticação.
 * POST /api/auth.php  -> login  (body: identifier, password)
 * GET  /api/auth.php  -> status da sessão (usuário logado ou null)
 * DELETE /api/auth.php -> logout
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$method = request_method();

if ($method === 'POST') {
    $d = body_json();
    $identifier = trim($d['identifier'] ?? '');
    $password = (string) ($d['password'] ?? '');

    if ($identifier === '' || $password === '') {
        json_error('Informe e-mail/usuário e senha.');
    }

    check_login_attempts($identifier);

    $user = do_login($identifier, $password);
    if (!$user) {
        json_error('E-mail/usuário ou senha inválidos.');
    }

    json_ok(['user' => public_user($user)]);
}

if ($method === 'GET') {
    start_session();
    $user = current_user();
    if (!$user) {
        json_ok(['user' => null]);
    }
    json_ok(['user' => public_user($user)]);
}

if ($method === 'DELETE') {
    do_logout();
    json_ok(['message' => 'Sessão encerrada.']);
}

json_error('Método não suportado', 405);
