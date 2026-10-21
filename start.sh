#!/usr/bin/env bash
# Inicia o sistema de estoque no PHP built-in server.
# Uso: ./start.sh  ou  PORT=8080 ./start.sh
# Configuração do banco MySQL vem do arquivo .env (veja .env.example)
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${PORT:-8000}"
HOST="${HOST:-0.0.0.0}"

command -v php >/dev/null 2>&1 || {
    echo "ERRO: PHP não encontrado. Instale com: sudo apt install php-cli php-mysql" >&2
    exit 1
}

# Carrega credenciais do banco (se existir .env)
if [ -f "$DIR/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    source "$DIR/.env"
    set +a
fi

cd "$DIR"
echo "Sistema de estoque rodando em http://localhost:${PORT}"
echo "Banco: MySQL ${DB_NAME:-estoque} @ ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}"
php -S "${HOST}:${PORT}" router.php
