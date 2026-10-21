<?php
/**
 * API de Usuários (somente administrador).
 * GET    /api/users.php                -> lista usuários
 * POST   /api/users.php                -> criar usuário
 * PUT    /api/users.php/{id}           -> editar nome/email/perfil/status
 * POST   /api/users.php/{id}/password  -> redefinir senha (gera nova)
 * GET    /api/users.php/{id}           -> detalhe (inclui últimas movimentações)
 * DELETE /api/users.php/{id}           -> desativar usuário
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();
db_init();

$pdo = db();

// Toda a API de usuários exige perfil admin
$current = require_permission('users.manage');
if ($current['role'] !== 'admin') {
    json_error('Apenas administradores podem gerenciar usuários.', 403);
}

$method = request_method();
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$segments = array_values(array_filter(explode('/', $path)));

$id = null;
foreach ($segments as $seg) {
    if (ctype_digit($seg)) {
        $id = (int) $seg;
    }
}
$subAction = in_array('password', $segments, true) ? 'password' : null;

function get_user_or_404(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch();
    if (!$u) {
        json_error('Usuário não encontrado.', 404);
    }
    return $u;
}

if ($method === 'GET' && $id !== null) {
    $u = get_user_or_404($pdo, $id);

    // Últimas movimentações feitas por esse usuário
    $stmt = $pdo->prepare('
        SELECT id, type, ref_code, item_code, item_name, quantity, note, created_at
        FROM movements WHERE user_id = :uid
        ORDER BY id DESC LIMIT 20
    ');
    $stmt->execute(['uid' => $id]);

    json_ok([
        'user' => public_user($u),
        'recent_movements' => $stmt->fetchAll(),
    ]);
}

if ($method === 'GET') {
    $stmt = $pdo->query('
        SELECT u.id, u.name, u.email, u.username, u.role, u.active, u.last_login_at,
               u.created_at, u.updated_at,
               (SELECT COUNT(*) FROM movements m WHERE m.user_id = u.id) AS movements_count
        FROM users u
        ORDER BY u.name ASC
    ');

    json_ok(['users' => array_map('public_user', $stmt->fetchAll())]);
}

if ($method === 'POST' && $id !== null && $subAction === 'password') {
    $u = get_user_or_404($pdo, $id);
    $d = body_json();

    $newPassword = (string) ($d['new_password'] ?? '');
    $pwErr = validate_password($newPassword);
    if ($pwErr) {
        json_error($pwErr);
    }

    $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id")
        ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $id]);

    json_ok(['message' => "Senha do usuário redefinida. Nova senha: $newPassword"]);
}

if ($method === 'POST' && $id === null) {
    $d = body_json();
    $name = trim($d['name'] ?? '');
    $email = strtolower(trim($d['email'] ?? ''));
    $username = trim($d['username'] ?? '');
    $role = $d['role'] ?? 'operator';
    $password = (string) ($d['password'] ?? '');

    if ($name === '' || $email === '') {
        json_error('Nome e e-mail são obrigatórios.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('E-mail inválido.');
    }
    if (!in_array($role, ['admin', 'operator'], true)) {
        json_error('Perfil inválido.');
    }
    $pwErr = validate_password($password);
    if ($pwErr) {
        json_error($pwErr);
    }

    // E-mail duplicado?
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    if ((int) $stmt->fetchColumn() > 0) {
        json_error('Já existe um usuário com este e-mail.');
    }
    if ($username !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        if ((int) $stmt->fetchColumn() > 0) {
            json_error('Já existe um usuário com este nome de usuário.');
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO users (name, email, username, password_hash, role, active)
        VALUES (:name, :email, :username, :hash, :role, 1)
    ');
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'username' => $username !== '' ? $username : null,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);

    $newId = (int) $pdo->lastInsertId();
    json_ok(['user' => public_user(get_user_or_404($pdo, $newId)), 'message' => 'Usuário criado com sucesso!'], 201);
}

if ($method === 'PUT' && $id !== null) {
    $u = get_user_or_404($pdo, $id);
    $d = body_json();

    $name = trim($d['name'] ?? $u['name']);
    $email = strtolower(trim($d['email'] ?? $u['email']));
    $username = trim($d['username'] ?? $u['username'] ?? '');
    $role = $d['role'] ?? $u['role'];

    // Ativação: o status 'active' é controlado via PUT também (1 ou 0)
    $active = array_key_exists('active', $d) ? (int) $d['active'] : (int) $u['active'];

    if ($name === '' || $email === '') {
        json_error('Nome e e-mail são obrigatórios.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('E-mail inválido.');
    }
    if (!in_array($role, ['admin', 'operator'], true)) {
        json_error('Perfil inválido.');
    }

    // Evitar desativar o último administrador ativo
    if ($active === 0 && (int) $u['active'] === 1 && $role === 'admin' && (int) $u['id'] === (int) $current['id']) {
        json_error('Você não pode desativar sua própria conta.');
    }
    if ($active === 0 && $u['role'] === 'admin') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1");
        if ((int) $stmt->fetchColumn() <= 1) {
            json_error('Não é possível desativar o último administrador ativo.');
        }
    }

    // E-mail duplicado (excluindo o próprio)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id != :id');
    $stmt->execute(['email' => $email, 'id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        json_error('Já existe outro usuário com este e-mail.');
    }

    $stmt = $pdo->prepare('
        UPDATE users
        SET name = :name, email = :email, username = :username, role = :role, active = :active,
            updated_at = datetime(\'now\',\'localtime\')
        WHERE id = :id
    ');
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'username' => $username !== '' ? $username : null,
        'role' => $role,
        'active' => $active,
        'id' => $id,
    ]);

    json_ok(['user' => public_user(get_user_or_404($pdo, $id)), 'message' => 'Usuário atualizado!']);
}

if ($method === 'DELETE' && $id !== null) {
    $u = get_user_or_404($pdo, $id);

    if ((int) $u['id'] === (int) $current['id']) {
        json_error('Você não pode excluir sua própria conta.');
    }

    // Desativa em vez de excluir (mantém histórico de movimentações)
    $pdo->prepare("UPDATE users SET active = 0, updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $id]);

    json_ok(['message' => 'Usuário desativado.']);
}

json_error('Método não suportado', 405);
