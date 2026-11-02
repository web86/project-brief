#!/bin/sh
# Run only against the exclusive disposable MariaDB; mount the repository read-only at /workspace.
set -eu
test "$APP_ENV" = testing
test "$DB_CONNECTION" = mysql
test "$DB_HOST" = 127.0.0.1
test "$DB_DATABASE" = projectbrief_agent_ux_full_qa
qa_root="$(mktemp -d /tmp/projectbrief-plans-php84.XXXXXX)"
trap 'rm -rf "$qa_root"' EXIT HUP INT TERM
mkdir -p "$qa_root/api"
for entry in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml .env.example .env.production.example; do
    cp -R "/workspace/api/$entry" "$qa_root/api/$entry"
done
mkdir -p "$qa_root/api/bootstrap/cache" "$qa_root/api/storage/framework/cache/data" "$qa_root/api/storage/framework/sessions" "$qa_root/api/storage/framework/views" "$qa_root/api/storage/app/private" "$qa_root/api/storage/logs"
cp /workspace/api/bootstrap/app.php /workspace/api/bootstrap/providers.php "$qa_root/api/bootstrap/"
ln -s /workspace/api/vendor "$qa_root/api/vendor"
ln -s /workspace/tests "$qa_root/tests"
export APP_BASE_PATH="$qa_root/api"
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
cd "$qa_root/api"
php -v | head -n 1
php -d memory_limit=512M vendor/bin/phpunit "$@"
