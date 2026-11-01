#!/bin/sh
# Full suite in an isolated application/storage copy and an exclusive disposable DB.
set -eu
test "$APP_ENV" = testing
test "$DB_CONNECTION" = mysql
test "$DB_HOST" = 127.0.0.1
test "$DB_DATABASE" = projectbrief_agent_ux_full_qa
task_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
qa_root="$(mktemp -d /tmp/project-brief-agent-ux-suite.XXXXXX)"
trap 'rm -rf "$qa_root"' EXIT HUP INT TERM
mkdir -p "$qa_root/api"
for entry in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml .env.example .env.production.example; do
    cp -R "$task_root/api/$entry" "$qa_root/api/$entry"
done
mkdir -p "$qa_root/api/bootstrap/cache" "$qa_root/api/storage/framework/cache/data" "$qa_root/api/storage/framework/sessions" "$qa_root/api/storage/framework/views" "$qa_root/api/storage/app/private" "$qa_root/api/storage/logs"
cp "$task_root/api/bootstrap/app.php" "$task_root/api/bootstrap/providers.php" "$qa_root/api/bootstrap/"
ln -s "$task_root/api/vendor" "$qa_root/api/vendor"
ln -s "$task_root/tests" "$qa_root/tests"
export APP_BASE_PATH="$qa_root/api"
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
cd "$qa_root/api"
php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml
