# Milestone 6.0 — проверка RU / EN / TR / ZH-CN

26 октября 2026, PHP 8.5.10, Laravel 13.34.0, Node 22.23.2, Vue 3 / vue-i18n 11. Lock-файлы и runtime-зависимости не менялись. Проверено локально без production deployment и push.

| Проверка                                 | Результат                                                                                                                                                        |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `npm test`                               | 243/243 PASS                                                                                                                                                     |
| `npm run build`                          | PASS, основной JS 426.13 kB / gzip 145.89 kB                                                                                                                     |
| `php artisan test --compact`             | 676/676 PASS, 8240 assertions                                                                                                                                    |
| `vendor/bin/pint --dirty --format agent` | PASS                                                                                                                                                             |
| I18n                                     | По 782 одинаковых ключа RU/EN/TR/ZH-CN, непустые строки, параметры, рендер для 0/1/2/5/21, fallback EN, отсутствие технических ключей                            |
| Каталоги                                 | Коды и Intl-регионы frontend/backend согласованы; четыре полных серверных словаря на каждом языке                                                                |
| Браузер                                  | Четыре языка × 1440/390 px × owner/client: 16 сценариев, 24 UI-снимка; нет ошибок JS, intlify и горизонтального overflow                                         |
| Определение языка                        | zh-CN/zh-Hans/zh-SG → zh-CN; zh-Hant/TW/HK/MO и zh → EN. Проверены браузер, общий ранний загрузчик и сервер                                                      |
| Профиль                                  | Переключение без document navigation; PATCH, reload, повторный вход и другое устройство; API zh-CN ↔ БД zh в существующих двухсимвольных полях                   |
| Уведомления                              | 9 событий × 4 языка получателя × Email/Telegram/Push; язык отправителя не подменяет язык получателя; изолированные транспортные адаптеры                         |
| Сервисные письма                         | Reset, подтверждение/смена Email, notification Email, приглашения; HTML/text/subject на языке профиля, включая китайского адресата у RU-отправителя              |
| Telegram                                 | `/start` для сохранённого китайского адресата, локализованные служебные ответы                                                                                   |
| Экспорт                                  | Реальные HTTP PDF/DOCX на четырёх языках, отличных от UI; точные суммы, USD и исходный китайский/турецкий/русский текст сохранены                                |
| PDF                                      | Извлечение Unicode на всех четырёх языках, реальные FontFile2 и ToUnicode; китайский PDF визуально проверен: глифы и переносы без обрезания                      |
| DOCX                                     | Полные Regular/Bold встроены, fontKey декодируется обратно в исходный TTF с совпадающей SHA-256; OFL включена; китайский DOCX отрисован LibreOffice и просмотрен |
| Память                                   | PDF/DOCX через реальный HTTP работают при стандартных 128 MiB; PDF использует локальные части шрифта, DOCX встраивает через временные файлы                      |
| Регрессии                                | RU/EN/TR, турецкий i/İ/ı/I, все существующие проверки прав, сессий, очередей, интеграций и каналов проходят                                                      |
| PWA                                      | Набор PWA unit-тестов, `/app` owner/client, reload, loader/fallback из общих словарей                                                                            |

Результаты: `frontend-results.json`, `laravel-results.json`, `browser-results.json`, `export-results.json`. Китайские изображения: `desktop/mobile-login/admin/client-zh-CN.png`, `export-zh-CN-pdf.png`, `export-zh-CN-docx.png`. Смешанный пользовательский текст намеренно не переводится.

```sh
npm test
npm run build
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Из корня:

```sh
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:multilingual
./bin/build-release
```

E2E использует отдельную временную SQLite-БД, свободные локальные порты и Chromium. После теста серверы и БД удаляются. Release pipeline повторяет unit/build/Laravel, устанавливает production vendor в staging, проверяет состав и секреты, затем запускает packaged runtime на изолированной копии. В нём проверяются четыре сохранённых языка, сервисные письма и китайские PDF/DOCX из включённых в ZIP шрифтов. Итоговые source commit, размер, SHA-256, число проверенных записей и CRC указаны в `RELEASE-ARTIFACT.json` и `SHA256SUMS.txt` после сборки.

Физические iOS/Android и реальная доставка SMTP/Telegram/Web Push не проверялись; транспортные тесты изолированы. Deployment и push не выполнялись.

Итоговый ZIP: `project-brief-v0.6.0-1d98eba.zip`, 96 187 282 байт. Source commit: `1d98eba25991eb14035205c2d38f13d74fe62023` (реализация китайского — `4eab8a9`). Release pipeline повторно подтвердил 243 frontend-теста, 676 Laravel-тестов / 8240 assertions и packaged runtime, включая CJK-экспорт. CRC архива и SHA-256 всех 8095 записей `checksums.txt` проверены непосредственно в ZIP. [Метаданные](RELEASE-ARTIFACT.json), [SHA-256 архива](SHA256SUMS.txt). После сборки менялись только документы с результатами; код архива соответствует source commit. Контрольная сумма самого ZIP публикуется вне архива, чтобы внутри не оставались данные предыдущего релиза.
