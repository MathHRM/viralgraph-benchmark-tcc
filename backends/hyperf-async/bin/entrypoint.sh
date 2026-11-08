#!/bin/sh
set -eu
# API 1 inicializa o schema antes da liberação das demais aplicações.
if [ "${INIT_SCHEMA:-0}" = "1" ]; then
    php bin/init.php
fi
exec "$@"
