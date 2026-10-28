# Milestone 7.6 — UX, навигация и брендирование workspace

Ветка `codex/milestone-7-6-ux-refinements` создана от полного состояния 7.5 `dcd541f`, включающего заданную базу `9bd8fcb` и итоговые evidence. Изменения 7.5 сохранены. Push, merge и deployment 7.6 не выполнялись. Новых зависимостей и миграций нет.

## Причины и исправления

| Наблюдение                        | Причина                                                                                           | Результат 7.6                                                                                                                                                           |
| --------------------------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Два поиска и лишний шум           | UI/API разделяли название и client filter                                                         | Одно поле названия/имени/email, trim, от 3 символов, debounce 480 ms; EXISTS без дублей, page20, workspace/assignment permissions                                       |
| Запросы и гонки при вводе         | Не было единого effective query                                                                   | 1–2 символа дают исходную выборку, AbortController + revision, URL/page1; overlay spinner без сдвига карточек                                                           |
| Длинный глобальный sidebar        | Дублировал список проектов                                                                        | Только «Все проекты», без project-list API bootstrap; активные рабочие контексты сохранены                                                                              |
| Неочевидный вход в настройки      | Нижнее меню не давало отдельный Security                                                          | Единый settingsMenu: Profile/Security/Notifications и owner-ссылки; collapsible project group, aria/inert/keyboard/reduced motion                                       |
| Красный текст после Passkey       | Сервер возвращал 423 + mutationNotStarted до записи, но branding отсутствовал в allowlist повтора | Только известные branding mutations безопасно продолжаются один раз с тем же FormData; общий toast, без повторного выбора файла                                         |
| Непонятная загрузка               | Fetch не давал upload bytes                                                                       | Минимальный XHR для multipart с progress, настоящие loaded/total, отдельные sanitize/process/confirm; прежняя картинка до успеха, блокировка дубля, retry/discard файла |
| Save сохраняла одну секцию        | UI отправлял section PATCH                                                                        | Любая Save собирает все dirty секции, одна транзакция и CAS каждой изменённой секции; ошибки и новые in-flight правки сохраняют черновик                                |
| Бренд только в клиентском портале | Локальное применение стиля                                                                        | Общий workspaceAppearance для owner/developer/client, немедленный сброс при смене, platform appearance на login/личном аккаунте/выходе                                  |
| Info выглядел как success         | Общий зелёный стиль                                                                               | Success зелёный, error красный, warning янтарный, info синий; прежний компонент/API и mobile layout                                                                     |
| Неединые breadcrumbs              | Отсутствовала аккаунтная группа                                                                   | «Мой аккаунт → …», существующая profile-ссылка, четыре локали; workspace scope данных не меняется                                                                       |

Legacy PATCH `{section, values}` сохранён для совместимости 7.5. CAS гарантируется новым протоколом `{sections, versions}`, который использует UI 7.6. Сначала проверяются все значения, затем под row lock версии изменяемых секций; 409 оставляет всё неизменным. Явное обновление базы черновика объединяет только локально изменённые поля с последними серверными данными; пользователь проверяет результат перед повторным Save. Файл остаётся отдельной операцией: незавершённая загрузка учитывается в dirty state и блокирует ложный общий успех до retry/discard.

## Security step-up

Политика 7.5 требовала fresh proof для всех branding mutations, включая sanitize/upload/reset. Она сохранена: бренд теперь меняет доверенное представление всего workspace и писем. Дополнительный challenge не введён; действующая пятиминутная proof повторно используется. Пересмотр косметических операций потребовал бы отдельного разделения полномочий и анализа рисков; requireFresh молча не удаляется.

Повтор разрешён только для точного известного маршрута/метода и серверного `mutationNotStarted === true`. После второго 423 повторов нет. UV, счётчики, CSRF, TOTP/recovery/password alternatives и owner/developer policy сохранены. Unknown mutations в allowlist не добавлены. Cancel/ошибка сохраняют форму и файл для явного retry. Реальное signed UV подтверждение favicon с одной продолженной операцией проверено по HTTP.

## Изображения: измерения и хранение

[IMAGE-SIZES.json](IMAGE-SIZES.json) получен реальным controller upload на GD. Высокоэнтропийный JPEG воспроизводим из фиксированного seed; это нагрузочный пример, а не обещание одинаковой экономии для каждого логотипа.

| Пример                                |               Источник |          Нормализация 7.5 |                                                        Выход 7.6 |
| ------------------------------------- | ---------------------: | ------------------------: | ---------------------------------------------------------------: |
| JPEG → logo                           | 1 588 650 B, 1900×1100 | PNG 1 799 287 B, 1024×593 |                                           PNG 362 399 B, 524×303 |
| Тот же JPEG → favicon                 |            1 588 650 B |     PNG 92 993 B, 256×256 |                                            PNG 92 993 B, 256×256 |
| Простой SVG favicon, реальный браузер |       Статичный vector |   Browser использовал PNG | Очищенный SVG около 0.2 KB — primary; PNG compatibility остаётся |

7.5 действительно могла увеличить файл: в примере JPEG → PNG +13.3%. В 7.6 logo меньше результата 7.5 на 79.9%, исходника на 77.2%. Сначала PNG compression level 9 без изменения цвета/alpha; только при выходе >512 KiB максимальная сторона уменьшается шагами до нижнего предела 512 px. 512 KiB — мягкая цель. Обычные компактные logo сохраняют до 1024 px; favicon уже ограничен 256 px и в данном шумовом примере не уменьшился. Для мелкого текста и крупных экспортов предпочтителен статичный SVG. Прозрачность и пропорции проверены; уменьшение тяжёлого raster — осознанный компромисс разрешения, без quantization.

Новый raster: **один PNG** в `storage/app/private/branding/{workspace}/{token}.png`, без оригинала и EXIF. Новый SVG: серверный static allowlist, `.svg` и один PNG compatibility. SVG используется через `<img>`, без v-html/inline XML. Прямой URL повторно очищается и отдаёт `image/svg+xml`, CSP `default-src 'none'; script-src 'none'; style-src 'none'; sandbox`, nosniff. Внешние ресурсы/скрипты/обработчики/DOCTYPE/entities исключены. PNG обслуживает email, DOCX/PDF и совместимость старого кода; новые экзотические raster formats не введены. PNG-контракт сохранён для отката на 7.5 без переобработки.

В SVG-потоке сервер очищает XML, браузер создаёт PNG для несовместимых потребителей, сервер повторно проверяет SVG и нормализует PNG. Исходный небезопасный XML на диск не пишется. Старые assets не меняются до замены. Прежние пути попадают в durable cleanup; временный рост хранения зависит от работы существующего scheduler. Token меняется при замене, старый URL не обслуживается. Private/no-store policy сохранён; в доставленном письме изображение может оставаться в кеше email-клиента. Реквизиты в image route не выдаются.

Для browser SVG трафик может быть существенно меньше PNG; email продолжает использовать PNG и получает его оптимизацию. Итоговые байты показываются после успеха; для старых assets без метаданных размер не выдумывается. Автоматическая смена installed PWA icon не реализована: manifest/Apple/maskable остаются платформенными, динамический browser favicon этому не равен.

## Проверка и артефакты

- [TESTS.md](TESTS.md): команды, результаты и границы проверок.
- [PRODUCTION.md](PRODUCTION.md): обновление/откат 0.7.5 → 0.7.6, storage и HTTP hardening.
- [BROWSER-RESULTS.json](BROWSER-RESULTS.json), 74 screenshots: desktop/tablet/mobile, четыре языка, две темы, поиск/sidebar/save/toast/client reset.
- [Security browser results](SECURITY-BROWSER-RESULTS.json), [real branding results](real-browser/REAL-BRANDING-RESULTS.json): реальный Laravel/HTTPS/UV Passkey, upload bytes, atomic reload, два workspace и platform reset.
- [EXPORT-RESULTS.json](EXPORT-RESULTS.json), [PDF](export-previews/pdf.png), [DOCX](export-previews/docx.png): финальный PNG, Unicode и visual QA.
- [Desktop projects](screenshots/projects-page2-1440-light.png), [Mobile sidebar](screenshots/sidebar-project-390-dark.png), [Upload progress](real-browser/real-upload-progress.png), [Mobile branding](real-browser/real-optimized-image-mobile.png).

Raw QA PDF/DOCX, изображения, логи, SQLite, virtual authenticator keys и временные файлы — только в игнорируемом artifacts/tmp. Production builder копирует runtime allowlist и PRODUCTION.md, проверяет секреты/запреты/checksums/чистый ZIP. Итоговый ZIP и SHA-256: [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json). Домены и собственный отправитель остаются будущими disabled функциями.
