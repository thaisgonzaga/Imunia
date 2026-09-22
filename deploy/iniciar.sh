#!/bin/sh
# Início do servidor de produção. Tudo aqui depende das variáveis de ambiente
# do serviço, que só existem com o contêiner no ar — não durante o build.
set -e

# O Render gera a chave como base64 de 256 bits, sem o prefixo que o Laravel
# usa para saber que precisa decodificá-la.
case "${APP_KEY:-}" in
    "" | base64:*) ;;
    *) export APP_KEY="base64:${APP_KEY}" ;;
esac

# O endereço público é o que o Render anuncia, salvo quando alguém o fixa
# (um domínio próprio, por exemplo). Telas e API moram no mesmo endereço.
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost:8080}}"
export FRONTEND_URL="${FRONTEND_URL:-$APP_URL}"
export SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-$(echo "$APP_URL" | sed -E 's#^https?://##; s#/.*$##')}"

php artisan optimize
php artisan migrate --force
php artisan imunia:preparar

exec frankenphp run --config /etc/frankenphp/Caddyfile
