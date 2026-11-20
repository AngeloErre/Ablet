#!/usr/bin/env bash
# ATTENZIONE: azzera il database configurato. Solo database di test, APP_ENV=development.
set -euo pipefail
cd "$(dirname "$0")/.."
php -r 'require "src/bootstrap.php"; if (Ablet\Support\Env::get("APP_ENV")!=="development" || !str_contains(strtolower(Ablet\Support\Env::get("DB_NAME","")),"test")) {fwrite(STDERR,"Rifiutato: suite distruttiva consentita solo su database di test in development.\n");exit(1);}'
php bin/migrate.php --fresh >/dev/null
php database/seeds/demo.php --quiet
php tests/free_activity.php
php -S 127.0.0.1:8101 -t public public/index.php >/tmp/ablet_free_app.log 2>&1 & APP_PID=$!
php -S 127.0.0.1:8102 tests/mock_llm.php >/tmp/ablet_free_mock.log 2>&1 & MOCK_PID=$!
trap 'kill "$APP_PID" "$MOCK_PID" 2>/dev/null || true' EXIT
sleep 1
APP=http://127.0.0.1:8101 MOCK=http://127.0.0.1:8102 php tests/free_activity_http.php
