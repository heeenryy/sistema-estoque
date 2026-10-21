<?php
/**
 * Autenticação, sessão, controle de tentativas e permissões.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Mapa de permissões por perfil.
 * Extensível: basta adicionar um novo perfil e suas permissões.
 *
 * Perfil 'admin' possui todas as permissões (*).
 */
const ROLE_PERMISSIONS = [
    'admin' => ['*'],
    'operator' => [
        'dashboard.view',
        'items.view',
        'items.create',
        'items.edit',
        'movement.in',
        'movement.out',
        'history.view',
        'reports.view',
        'labels.create',
        'account.manage',
    ],
];

const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

/**
 * Inicia a sessão de forma segura.
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_name('ESTOQUE_SESSID');
    session_start();
}

/**
 * Retorna o usuário logado (array) ou null.
 */
function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['active'] !== 1) {
        return null;
    }
    return $user;
}

/**
 * Exige usuário autenticado. Retorna o usuário ou aborta com 401.
 */
function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Não autenticado. Faça login.', 401);
    }
    return $user;
}

/**
 * Verifica se o usuário tem uma permissão.
 */
function user_can(array $user, string $permission): bool
{
    $perms = ROLE_PERMISSIONS[$user['role']] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

/**
 * Exige autenticação + permissão específica.
 */
function require_permission(string $permission): array
{
    $user = require_auth();
    if (!user_can($user, $permission)) {
        json_error('Acesso negado: você não tem permissão para esta ação.', 403);
    }
    return $user;
}

/**
 * Gera um nome de exibição seguro para o usuário logado.
 */
function user_display_name(array $user): string
{
    return trim($user['name'] !== '' ? $user['name'] : $user['email']);
}

/**
 * IP do cliente.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Verifica limite de tentativas de login (lockout temporário).
 */
function check_login_attempts(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_attempts
        WHERE email = :email AND success = 0
          AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE)
    ');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $fails = (int) $stmt->fetchColumn();

    if ($fails >= MAX_LOGIN_ATTEMPTS) {
        json_error("Muitas tentativas de login. Aguarde " . LOGIN_LOCK_MINUTES . " minutos.", 429);
    }
}

/**
 * Registra uma tentativa de login.
 */
function record_login_attempt(string $email, bool $success): void
{
    db()->prepare('
        INSERT INTO login_attempts (email, ip, success)
        VALUES (:email, :ip, :success)
    ')->execute([
        'email' => strtolower(trim($email)),
        'ip' => client_ip(),
        'success' => $success ? 1 : 0,
    ]);
}

/**
 * Limpa o histórico de tentativas após login bem-sucedido.
 */
function clear_login_attempts(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = :email')
        ->execute(['email' => strtolower(trim($email))]);
}

/**
 * Busca usuário por e-mail ou nome de usuário.
 */
function find_user_by_identifier(string $identifier): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :id OR username = :id LIMIT 1');
    $stmt->execute(['id' => trim($identifier)]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Realiza o login. Retorna o usuário (sem hash) ou null em caso de falha.
 */
function do_login(string $identifier, string $password): ?array
{
    $user = find_user_by_identifier($identifier);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($identifier, false);
        return null;
    }

    if ((int) $user['active'] !== 1) {
        return null;
    }

    // Login com sucesso: limpa tentativas, regenera sessão e marca último acesso
    clear_login_attempts($identifier);

    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['logged_in_at'] = time();

    db()->prepare("UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $user['id']]);

    unset($user['password_hash']);
    return $user;
}

/**
 * Encerra a sessão.
 */
function do_logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Valida força mínima de senha.
 * Retorna null se válida, ou mensagem de erro.
 */
function validate_password(string $password): ?string
{
    if (strlen($password) < 6) {
        return 'A senha deve ter pelo menos 6 caracteres.';
    }
    return null;
}

/**
 * Remove campos sensíveis antes de enviar ao cliente.
 */
function public_user(array $user): array
{
    unset($user['password_hash']);
    return $user;
}
