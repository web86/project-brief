#!/bin/sh
# Disposable QA container only; the repository is mounted read-only.
set -eu
test "$DB_DATABASE" = projectbrief_m90_qa
test "$DB_HOST" = project-brief-m90-db
test "$APP_ENV" = testing
mkdir -p /tmp/m90-api
cd /tmp/m90-api
for entry in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml; do
    cp -R "/workspace/api/$entry" .
done
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private storage/logs
cp /workspace/api/bootstrap/app.php /workspace/api/bootstrap/providers.php bootstrap/
ln -s /workspace/api/vendor vendor
export APP_BASE_PATH=/tmp/m90-api
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
if test "${1:-test}" = prepare; then
    php artisan migrate:fresh --force --no-interaction
else
    php -d memory_limit=512M vendor/bin/phpunit --filter '(PlatformAdminTest|ApiUsageTest|SessionSecurityTest|SeamlessSecurityConfirmationTest|IntegrationReadTest|ClientSsoTest|ProjectRequestsTest|WebhooksTest|WebhookProjectFiltersTest|ClientCommentWebhooksTest|IntegrationsTest::(?!test_integration_migration_rolls_back_and_reapplies))'
fi
