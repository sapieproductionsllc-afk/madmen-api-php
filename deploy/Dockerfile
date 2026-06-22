# API MadMen — image PHP 8.4 servie par le serveur web intégré (comme en dev),
# placée DERRIÈRE le reverse-proxy Caddy existant (réseau ssm-cloud_ssm).
# Les secrets (.env) sont MONTÉS au runtime, jamais cuits dans l'image.
FROM php:8.4-cli-alpine

# Extensions : PDO MySQL (la base) + sockets (K40 / rats-zkteco).
# linux-headers fournit linux/sock_diag.h, requis pour compiler l'extension sockets sous Alpine.
RUN apk add --no-cache linux-headers \
 && docker-php-ext-install pdo_mysql sockets

# Composer depuis l'image officielle.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 1) Dépendances d'abord (couche cache tant que composer.* ne bouge pas).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# 2) Code applicatif.
COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && mkdir -p storage/uploads \
 && chmod -R 0775 storage

EXPOSE 8000
# Concurrence du serveur PHP intégré (PHP 8.4).
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
