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
