#!/bin/sh
# Run only inside the disposable QA container with the repository mounted read-only.
set -eu
test "$DB_DATABASE" = projectbrief_m73_qa
test "$DB_HOST" = project-brief-m73-mariadb
test "$APP_ENV" = testing
mkdir -p /tmp/project-brief-m73-api
cd /tmp/project-brief-m73-api
export APP_BASE_PATH=/tmp/project-brief-m73-api
export DB_PORT=3306
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export APP_KEY
for item in app config database lang resources routes tests artisan composer.json composer.lock phpunit.xml; do
    cp -R "/workspace/api/$item" .
done
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private storage/logs
cp /workspace/api/bootstrap/app.php /workspace/api/bootstrap/providers.php bootstrap/
ln -s /workspace/api/vendor vendor
# GD-dependent tenant/image cases and an existing transactional DDL rollback case
# remain covered by the full native SQLite suite, not this MariaDB selection.
php vendor/bin/phpunit --filter '(HttpHardeningTest|AccountSecurityTest|FlexibleAccountSecurityTest|SeamlessSecurityConfirmationTest|SessionSecurityTest|AccessLinkTest|IntegrationReadTest|BrowserProjectExportTest|ProjectExportTest|IntegrationsTest::(?!test_integration_migration_rolls_back_and_reapplies))'
