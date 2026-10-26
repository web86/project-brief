# API экспорта — Milestone 6.2

Внутренние session endpoints не являются bearer REST API `/api/v1`. Нужны существующая сессия, CSRF для POST и доступ к проекту. Admin дополнительно требует `export_project`; клиент использует свой проектный доступ и серверный allowlist.

| Endpoint | POST format | Результат |
| --- | --- | --- |
| `/api/admin/projects/{uuid}/export-data` | `pdf` | JSON документа |
| `/api/client/project/{uuid}/export-data` | `pdf` | Безопасный клиентский JSON |
| `/api/admin/projects/{uuid}/export` | `docx` | PHPWord DOCX |
| `/api/client/project/{uuid}/export` | `docx` | Клиентский DOCX |

Старый запрос `format: pdf` к `/export` возвращает 422: серверной генерации PDF нет.

Параметры сохранены: `scope` = all/approved/done; `locale` = ru/en/tr/zh-CN; опционально `documentTitle` (до 160 символов), `pageBreakBySection` (boolean), `fields` с boolean description/expectedResult/priority/status/estimateHours/price/attachments/approvalDate/developerNotes. Неизвестные параметры отклоняются. Клиент не получает estimateHours, price, developerNotes даже при подделке полей запроса.

JSON: `{ "schemaVersion": 1, "filename": "safe-name-specification-YYYY-MM-DD.pdf", "document": { ... } }`. Документ содержит только явный allowlist, локализованные строки и рассчитанные сервером итоги. Нет ORM-моделей, токенов, внутренних ID и путей хранения. Ответ `private, no-store, max-age=0`, `Pragma: no-cache`, `nosniff`. Действуют лимиты `config/browser_pdf.php`; недопустимые параметры/пустая выборка — 422, превышение размера — 413, rate limit — 429, ошибки доступа сохраняют текущие 401/403/404.

Переключатели прототипа и диагностические заголовки удалены. `BROWSER_PDF_RATE_LIMIT` сохраняет настройку лимита частоты; `BROWSER_PDF_PROTOTYPE_ENABLED` и `BROWSER_PDF_DIAGNOSTICS` больше не используются.

Создание проекта через `/api/admin/projects` и `/api/v1/projects` возвращает пустую структуру без задач. Начальные разделы больше не создаются. Поле `sections` не поддерживается при создании; административный API отклоняет непустое значение. Добавление первого раздела — отдельная существующая операция POST `/api/admin/projects/{uuid}/sections` с правом `manage_structure`. Права, идемпотентность REST и внешний identity link сохранены.
