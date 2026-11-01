#!/bin/sh
# Disposable PHP 8.4/MariaDB QA; repository is mounted read-only.
set -eu
test "$APP_ENV" = testing
test "$DB_DATABASE" = projectbrief_m96_php84_qa
test "$DB_HOST" = 127.0.0.1
mkdir -p /tmp/m99-api
cd /tmp/m99-api
for entry in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml; do
    cp -R "/workspace/api/$entry" .
done
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private storage/logs
cp /workspace/api/bootstrap/app.php /workspace/api/bootstrap/providers.php bootstrap/
ln -s /workspace/api/vendor vendor
export APP_BASE_PATH=/tmp/m99-api
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
php -v | head -n 1
php -d memory_limit=512M vendor/bin/phpunit --filter '(AgentCollaborationMigrationTest|AgentCollaborationTest|TaskDescriptionTest|AgentApiTest|AgentPermissionsPrivacyTest|AgentPermissionsMigrationTest|IntegrationsTest|IntegrationReadTest|WebhooksTest|WebhookProjectFiltersTest|ApiUsageTest|Milestone95Test)'
