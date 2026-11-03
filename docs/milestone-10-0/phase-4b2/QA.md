# Проверки локального этапа

- PHP 8.5.10 / SQLite. `PlansPreparationTest` и `OnboardingUxTest` — 18 тестов, 145 утверждений. Проверены owner-only preview, отказ разработчику и постороннему оператору, метаданные Compatibility без срока и отсутствие имён в платформенном ответе.
- Фронтенд — 453 теста и production Vite build. Сборка выдала предупреждение о размере основного chunk.
- Chromium на локальном HTTPS. Onboarding — 20 сценариев, 108 снимков. Проверены четыре языка, три темы, мобильная и настольная ширина, standalone security для onboarding, прямое открытие и reload рабочего раздела безопасности для Compatibility-владельца.
- Проверка выполнения реальных переходов, PHP 8.4/MariaDB race tests, Jino и проверка извлечённого Production ZIP остаются открытыми.
