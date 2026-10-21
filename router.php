<?php
/**
 * Router para o servidor embutido do PHP (php -S).
 * Serve arquivos estáticos e encaminha o resto para o index.php.
 * URLs da API com subsegmentos (ex.: /api/users.php/2) são roteadas
 * para o arquivo .php correspondente.
 */

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// Arquivo estático real -> deixa o servidor embutido servir
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false;
}

// Caminhos da API (ex.: /api/users.php/2 -> api/users.php)
if (strpos($uri, '/api/') === 0) {
    // Pega o primeiro segmento que termina com .php
    $apiFile = null;
    foreach (explode('/', trim($uri, '/')) as $seg) {
        if (str_ends_with($seg, '.php')) {
            $apiFile = __DIR__ . '/api/' . $seg;
            break;
        }
    }
    if ($apiFile && file_exists($apiFile) && is_file($apiFile)) {
        require $apiFile;
        exit;
    }
}

// Qualquer outra rota -> aplicação SPA
require __DIR__ . '/index.php';
