FROM composer:2.9.5@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer
FROM hyperf/hyperf:8.4-alpine-v3.22-swoole-v6.2.2@sha256:cb43095d43871e5719efea95f73fbce5bca8a2197873decd77601c9a83be41c0
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader
COPY . .
RUN mkdir -p runtime && chown -R nobody:nobody runtime
USER nobody
ENTRYPOINT ["sh", "bin/entrypoint.sh"]
CMD ["php", "bin/server.php", "start"]
