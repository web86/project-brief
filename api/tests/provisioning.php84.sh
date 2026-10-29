#!/bin/sh
# Disposable PHP 8.4/MariaDB QA. The repository is mounted read-only at /workspace.
set -eu
test "$DB_DATABASE" = projectbrief_m80_qa
test "$APP_ENV" = testing
mkdir -p /tmp/project-brief-m80-api
cd /tmp/project-brief-m80-api
export APP_BASE_PATH=/tmp/project-brief-m80-api
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
for item in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml; do
    cp -R "/workspace/api/$item" .
done
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private storage/logs
cp /workspace/api/bootstrap/app.php /workspace/api/bootstrap/providers.php bootstrap/
ln -s /workspace/api/vendor vendor
# DDL rollback is covered by native SQLite; MariaDB DDL implicitly commits.
php -d memory_limit=512M vendor/bin/phpunit --filter '(ProjectRequestsTest|ProjectProvisioningTest::(?!test_schema_upgrade_and_empty_rollback)|ClientProjectLoginTest|ClientInvitationsTest|IntegrationReadTest|IntegrationsTest::(?!test_integration_migration_rolls_back_and_reapplies)|WebhooksTest)'
