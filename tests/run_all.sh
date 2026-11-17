#!/usr/bin/env bash
# Esegue l'intera verifica su un database DEDICATO AI TEST (lo schema viene azzerato).
set -e
cd "$(dirname "$0")/.."
php bin/migrate.php --fresh >/dev/null
php database/seeds/demo.php --quiet
php tests/run.php | tail -1
php tests/free_activity.php | tail -1
php bin/migrate.php --fresh >/dev/null
php database/seeds/demo.php --quiet
PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8101 -t public public/index.php >/tmp/ablet_app.log 2>&1 & APP_PID=$!
php -S 127.0.0.1:8102 tests/mock_llm.php >/tmp/ablet_mock.log 2>&1 & MOCK_PID=$!
trap 'pkill -P $APP_PID 2>/dev/null || true; kill $APP_PID $MOCK_PID 2>/dev/null || true' EXIT
sleep 1
APP=http://127.0.0.1:8101 MOCK=http://127.0.0.1:8102 php tests/http.php

MOCK=http://127.0.0.1:8102 php tests/provider_reliability.php
