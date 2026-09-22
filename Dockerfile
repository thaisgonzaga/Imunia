# syntax=docker/dockerfile:1

# Imagem de produção do Imunia: as telas (Vue) e a API (Laravel) no mesmo
# endereço, servidas pelo FrankenPHP. Mesma origem é o que o Sanctum em modo
# SPA pede — cookie de sessão de primeira parte, sem CORS e sem o bloqueio de
# cookies de terceiros dos navegadores. Ver deploy/LEIAME.md.

# 1. Telas: só o resultado do build segue para a imagem final.
FROM node:22-bookworm-slim AS telas
WORKDIR /telas
COPY imunia-frontend/package.json imunia-frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY imunia-frontend/ ./
RUN npm run build

# 2. Servidor.
FROM dunglas/frankenphp:1-php8.5-bookworm

# pdo_mysql para o banco; gd para o QR e as imagens do PDF (dompdf); intl,
# zip e bcmath pedidos pelas dependências do composer.lock.
RUN install-php-extensions pdo_mysql gd intl zip bcmath opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependências antes do código: a camada só se refaz quando o lock muda.
COPY imunia-backend/composer.json imunia-backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY imunia-backend/ ./
# O .htaccess é do Apache: aqui ele só seria servido como arquivo comum.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && rm -f public/.htaccess

COPY --from=telas /telas/dist/ ./public/

COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-imunia.ini"
COPY --chmod=755 deploy/iniciar.sh /usr/local/bin/iniciar

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

CMD ["iniciar"]
