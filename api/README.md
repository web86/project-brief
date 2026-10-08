# ProjectBrief API

Laravel 13 API for the existing Vue app in the repository root. Setup, environment variables, session/client access boundaries, endpoint list and production topology are documented in [the root README](../README.md).

Use root `npm run api:dev` for local Laravel serving with the correct PHP upload limits. Do not run this scaffold's frontend: the application frontend lives in root `src/`.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure MariaDB in .env.
php artisan migrate
php artisan app:create-admin
php artisan test
```

`GET /api/session` returns only `{authenticated:false}`, an admin persona, or a
client persona with the public project UUID. It uses the same client-session
validator as protected client endpoints, clears stale client-session authorization,
and sends `Cache-Control: no-store`. No credentials, access tokens or hashes are returned.
Production env examples recommend finite 365-day database sessions
(`SESSION_LIFETIME=525600`, `SESSION_EXPIRE_ON_CLOSE=false`); security flags and
CSRF remain unchanged. Existing deployments must update their env override and
refresh the config cache to adopt the recommendation. No new migration is required.
