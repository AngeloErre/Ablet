#!/usr/bin/env bash
# Esegue l'intera verifica su un database DEDICATO AI TEST (lo schema viene azzerato).
set -euo pipefail
cd "$(dirname "$0")/.."
php -r 'require "src/bootstrap.php"; if (Ablet\Support\Env::get("APP_ENV")!=="development" || !str_contains(strtolower(Ablet\Support\Env::get("DB_NAME","")),"test")) {fwrite(STDERR,"Rifiutato: suite distruttiva consentita solo su database di test in development.\n");exit(1);}'
php bin/migrate.php --fresh >/dev/null
php database/seeds/demo.php --quiet
php tests/run.php | tail -1
php tests/free_activity.php | tail -1
php tests/features_v05.php | tail -1
php tests/input_validation.php
php tests/token_monitor.php | tail -1
php bin/migrate.php --fresh >/dev/null
php database/seeds/demo.php --quiet
export PASSWORD_RESET_TEST_OUTBOX="$(mktemp "${TMPDIR:-/tmp}/ablet-reset-XXXXXX")"
PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8101 -t public public/index.php >/tmp/ablet_app.log 2>&1 & APP_PID=$!
php -S 127.0.0.1:8102 tests/mock_llm.php >/tmp/ablet_mock.log 2>&1 & MOCK_PID=$!
trap 'pkill -P $APP_PID 2>/dev/null || true; kill $APP_PID $MOCK_PID 2>/dev/null || true; rm -f "$PASSWORD_RESET_TEST_OUTBOX"' EXIT
sleep 1
APP=http://127.0.0.1:8101 MOCK=http://127.0.0.1:8102 php tests/http.php
php tests/activity_edit_http.php
php tests/debug_regressions.php

MOCK=http://127.0.0.1:8102 php tests/provider_reliability.php

php tests/student_memory.php
php tests/pin_access.php
php tests/accounts.php
php tests/admin.php
php tests/password_recovery.php
php tests/upgrade_memory.php

if [[ "${ABLET_STRESS:-0}" == "1" ]]; then
  php bin/migrate.php --fresh >/dev/null
  php database/seeds/demo.php --quiet
  php tests/stress.php
fi
