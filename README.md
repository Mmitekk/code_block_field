# Code Block Field

Модуль для Drupal 9.5+ / 10+ / 11, добавляющий новый тип поля для хранения связки **HTML / CSS / JS** в одном поле. Поле можно добавить к любой сущности (node, paragraph, block_content, taxonomy_term и т.д.). На странице блок рендерится внутри **изолированного Shadow DOM**, а пользователи с соответствующими правами могут редактировать его содержимое прямо на странице (текст, изображения, ссылки) — без необходимости открывать сущность на редактирование, как в визуальных конструкторах (Elementor / Webflow / Bricks).

[Read this documentation in English](./README.en.md)

## Возможности

- **Новый тип поля `code_block`** с под-полями **HTML**, **CSS**, **JS** и сериализованной картой **assets** (managed files)
- **Рендер в Shadow DOM** — CSS блока не конфликтует с темой и наоборот. Поддерживаются режимы `open` и `closed`
- **Inline-редактор в стиле конструкторов:**
  - Плавающая панель в правом верхнем углу с кнопками **«Edit mode»**, **«Save changes»**, **«Cancel»**
  - `contenteditable` для всех текстовых элементов (h1–h6, p, span, a, li, td, figcaption, blockquote, strong, em, b, i, …)
  - Клик по `<img data-cbf-asset="key">` → модальный Drupal file-picker с загрузкой и редактированием alt
  - Двойной клик по `<a>` (или по карандашу ✎) → модальная форма редактирования `href`, `target`, `rel`, текста ссылки
  - Отслеживание «грязных» блоков (dirty tracking) и CSRF-защищённый JSON endpoint для сохранения
- **Редактор кода CodeMirror 5** в форме сущности — режимы HTML / CSS / JS, автодополнение, автозакрытие тегов, темы (Material Darker / Dracula / Nord / Monokai / Default)
- **Глобальная страница настроек** в админке (как у FAQ by URL): HTML-фильтр, путь загрузки, макс. размер, режим Shadow DOM, цвета инлайн-редактора
- **Per-role permissions** + проверка права `update` на сущности при каждом сохранении
- **File usage tracking** — все картинки, загруженные через инлайн-редактор, привязываются к сущности через `file.usage` и автоматически освобождаются при удалении сущности
- **HTML-фильтрация** на каждом сохранении через ядрный `filter_html`
- **Совместимость** — Drupal 9.5.x, 10.x и 11.x (PHP 7.4+ для 9.5, 8.1+ для 10, 8.3+ для 11)

## Установка

### Вариант 1: Через Composer (рекомендуется)

Composer автоматически скачает модуль в нужную директорию и будет управлять обновлениями.

1. Добавьте репозиторий модуля в `composer.json` вашего проекта:
   ```bash
   composer config repositories.code_block_field vcs https://github.com/Mmitekk/code_block_field.git
   ```

2. Установите модуль:
   ```bash
   composer require mmitekk/code_block_field:dev-main
   ```
   Модуль будет установлен в `web/modules/custom/code_block_field/` (путь зависит от структуры вашего проекта).

3. Включите модуль через Drush или админку:
   ```bash
   drush en code_block_field -y
   ```

4. Модуль автоматически создаст файл конфигурации `code_block_field.settings.yml` с настройками по умолчанию.

**Обновление через Composer:**
```bash
composer update mmitekk/code_block_field
drush updb -y
drush cr
```

**Переключение на стабильный релиз** (когда появятся теги):
```bash
composer require mmitekk/code_block_field:^1.0
```

> **Примечание:** если Composer устанавливает модуль не в `web/modules/custom/`, а в другую директорию — добавьте в `composer.json` проекта секцию `installer-paths`:
> ```json
> "extra": {
>     "installer-paths": {
>         "web/modules/custom/{$name}": ["type:drupal-custom-module"]
>     }
> }
> ```

### Вариант 2: Вручную

1. Скачайте архив с GitHub: https://github.com/Mmitekk/code_block_field/archive/refs/heads/main.zip
2. Распакуйте и переименуйте папку в `code_block_field`
3. Скопируйте папку `code_block_field` в директорию `web/modules/custom/` вашего Drupal-сайта
4. Включите модуль через админку (`/admin/modules`) или Drush:
   ```bash
   drush en code_block_field -y
   ```
5. Модуль автоматически создаст конфигурацию по умолчанию

## Настройка

### Шаг 1. Добавьте поле к сущности

1. Откройте **Структура → Типы материалов → [ваш тип] → Управление полями** (или любую другую сущность: paragraph, block_content, taxonomy_term)
2. Нажмите **«Добавить поле»** → выберите тип **Code Block (HTML / CSS / JS)**
3. Задайте имя поля (например, `field_code_block`)

### Шаг 2. Настройте форму и дисплей

1. В **Управление формой** выберите виджет **Code Block editor (CodeMirror)**
2. В **Управлении отображением** выберите форматтер **Code Block (Shadow DOM, inline-editable)**
3. У форматтера доступны собственные настройки:
   - **Shadow DOM mode** — `open` / `closed`
   - **Enable inline editing on this display** — включает/выключает inline-редактор для этого display mode
   - **Image style for managed assets** — какой image style применять к загруженным картинкам

### Шаг 3. Настройте права доступа

На странице **People → Permissions** (`/admin/people/permissions`):

| Право | Описание |
|-------|----------|
| **Use inline editor for Code Block fields** | Доступ к инлайн-редактору и endpoint сохранения. Дополнительно пользователь должен иметь `update` на сущности |
| **Administer Code Block Field settings** | Доступ к глобальной странице настроек модуля |
| **Edit raw HTML in Code Block fields** | Доступ к редактированию HTML-кода в форме сущности (можно отключить для контент-менеджеров, оставив только inline-редактор) |

### Шаг 4. Глобальные настройки модуля

Перейдите в **Конфигурация → Контент → Code Block Field** (`/admin/config/content/code-block-field`):

#### HTML-фильтр

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| **Filter HTML on save** | Включает фильтрацию HTML через `filter_html` при каждом сохранении | Вкл |
| **Allowed HTML tags** | Список разрешённых тегов и атрибутов (формат Filter module) | См. ниже |

#### Хранилище файлов

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| **Upload destination** | Stream wrapper для загрузки картинок через инлайн-редактор | `public://code-block-field` |
| **Maximum upload size** | Максимальный размер файла (`5 MB`, `1024 KB`, `1G`) | `5 MB` |
| **Default image style** | Image style по умолчанию для рендера картинок | Оригинал |

#### Shadow DOM

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| **Default Shadow DOM mode** | `Open` (разработчик-инспектируемый) или `Closed` (доп. изоляция) | `Open` |

#### Inline editor

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| **Show floating "Edit Mode" button** | Показывать плавающую кнопку на страницах с code-блоками | Вкл |

#### Цвета инлайн-редактора

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| **Toolbar background** | Фон плавающей панели | `#1e1e2e` |
| **Toolbar accent** | Акцентный цвет кнопки «Edit mode» (когда активна) и бейджа | `#ff8a3d` |
| **Editing outline** | Контур редактируемого блока | `#ff8a3d` |
| **Dirty outline** | Контур блока с несохранёнными изменениями | `#28a745` |
| **Focused outline** | Контур блока с активным курсором | `#0071eb` |

## Использование

### Создание блока

1. Откройте сущность (node / paragraph / block / term) на редактирование
2. В поле **Code Block** заполните вкладки **HTML**, **CSS**, **JS**
3. Чтобы сделать картинку управляемой (заменяемой через инлайн-редактор), пометьте её атрибутом `data-cbf-asset`:
   ```html
   <img data-cbf-asset="hero-photo" src="/sites/default/files/placeholder.jpg" alt="Главное фото">
   ```
4. Сохраните сущность

### Inline-редактирование

1. Откройте страницу с рендером блока (любой display, использующий форматтер **Code Block (Shadow DOM, inline-editable)**)
2. Если у вас есть право `use code block field inline editor` и право `update` на сущность — в правом верхнем углу появится плавающая панель **Code Block**
3. Нажмите **Edit mode** — все блоки на странице получат пунктирную рамку, текст станет редактируемым на месте. Картинки покажут бейдж **«✎ Replace»**, ссылки получат маленький значок карандаша
4. Отредактируйте текст кликом по нему. Замените картинку кликом по ней (откроется модальный Drupal file-picker). Отредактируйте ссылку двойным кликом (или кликом по карандашу)
5. Нажмите **Save changes** — все «грязные» блоки будут отправлены POST-запросом на `/admin/code-block-field/inline-save` и сохранены прямо в поле сущности. Страница **не перезагружается** — Shadow DOM сохраняет новое содержимое
6. Нажмите **Cancel**, чтобы сбросить все несохранённые изменения и выйти из режима редактирования

## Структура модуля

```
code_block_field/
├── code_block_field.info.yml             — Информация о модуле
├── code_block_field.module               — Хуки модуля (theme, help, entity hooks)
├── code_block_field.install              — Install/uninstall hooks
├── code_block_field.routing.yml          — Маршруты (save, upload, picker dialogs)
├── code_block_field.permissions.yml      — Права доступа
├── code_block_field.links.menu.yml       — Пункт меню в админке
├── code_block_field.libraries.yml        — Подключаемые библиотеки (CodeMirror + свои)
├── composer.json                         — Composer-метаданные
├── config/
│   ├── install/code_block_field.settings.yml — Настройки по умолчанию
│   └── schema/code_block_field.schema.yml    — Схема конфигурации
├── css/
│   ├── codemirror-overrides.css          — Переопределение стилей CodeMirror
│   ├── widget.css                        — Стили виджета в форме сущности
│   └── inline-editor.css                 — Сили инлайн-редактора (toolbar, outlines)
├── js/
│   ├── codemirror-init.js                — Инициализация CodeMirror на textareas
│   ├── widget.js                         — Синхронизация HTML ↔ скрытое поле assets
│   ├── renderer.js                       — Монтирование Shadow DOM
│   └── inline-editor.js                  — Inline-редактор (toolbar, contenteditable, pickers)
├── templates/
│   └── code-block-field.html.twig        — Twig-шаблон форматтера
└── src/
    ├── Controller/
    │   └── InlineEditController.php      — AJAX endpoint'ы (save, upload, picker dialogs)
    ├── Form/
    │   ├── SettingsForm.php              — Форма глобальных настроек
    │   ├── InlineImagePickerForm.php     — Модальная форма выбора изображения
    │   └── InlineLinkPickerForm.php      — Модальная форма редактирования ссылки
    └── Plugin/Field/
        ├── FieldType/CodeBlockItem.php   — Тип поля (html, css, js, assets)
        ├── FieldWidget/CodeBlockWidget.php     — Виджет с CodeMirror
        └── FieldFormatter/CodeBlockFormatter.php — Форматтер с Shadow DOM
```

## Логика работы изоляции

Каждый блок рендерится в собственный Shadow DOM root файлом `js/renderer.js`:

- CSS блока **не может** утечь в тему, а CSS темы **не может** повлиять на блок
- JavaScript выполняется в глобальном scope страницы (Shadow DOM изолирует DOM и CSS, но не JS). Внутри скрипта блока доступны переменные `host` (хост-элемент) и `shadowRoot` (его shadow root). Для доступа к DOM блока используйте `shadowRoot.querySelector(...)`
- Для усиленной изоляции выберите режим **Closed** — внешние скрипты не смогут добраться до блока через `element.shadowRoot`. Inline-редактор продолжает работать, потому что при первом монтировании сохраняет прямую ссылку на shadow root

## Кастомизация

### Переопределение шаблона

Скопируйте `templates/code-block-field.html.twig` в папку темы и измените по необходимости. Шаблон выводит один `<div class="cbf-host">` с `data-*` атрибутами и встроенным `<script type="application/json">` с payload'ом.

### Переопределение стилей инлайн-редактора

Цвета настраиваются через админку (`/admin/config/content/code-block-field`) и эммитятся как CSS custom properties. Если нужно переопределить глубже — используйте свои стили в теме:

```css
.cbf-inline-toolbar {
  --cbf-toolbar-bg: #your-color;
  --cbf-toolbar-accent: #your-color;
  --cbf-edit-outline: #your-color;
  --cbf-dirty-outline: #your-color;
  --cbf-focus-outline: #your-color;
}
```

### Программный API

```javascript
// Активация/деактивация инлайн-редактора из своего JS
Drupal.codeBlockField.activate();
Drupal.codeBlockField.deactivate();

// Перерендер блока с новым payload (полезно после AJAX)
Drupal.codeBlockField.render(instanceId, { html, css, js });

// Реестр всех смонтированных инстансов на странице
window.codeBlockFieldRegistry;
```

## Endpoints

| Метод | Путь | Назначение |
|-------|------|------------|
| POST | `/admin/code-block-field/inline-save` | Сохраняет изменённый HTML одного field item. CSRF-защищён |
| POST | `/admin/code-block-field/image-upload` | Загрузка картинки из инлайн-редактора. CSRF-защищён |
| GET  | `/admin/code-block-field/image-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{asset_key}` | Модальная форма выбора картинки |
| GET  | `/admin/code-block-field/link-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{link_key}` | Модальная форма редактирования ссылки |

## Ограничения и особенности

- Инлайн-редактор модифицирует только HTML-подполе (и карту assets). Изменения CSS/JS делаются через форму сущности
- Фильтрация inline-сохранённого HTML использует глобальный `code_block_field.settings.allowed_html`. Переопределение на уровне поля сохраняется, но пока не применяется при inline-сохранении
- Редактор использует `DOMParser` и `Element.matches` — доступны во всех браузерах, поддерживаемых Drupal 10 / 11
- CodeMirror загружается с `cdnjs.cloudflare.com`. Для офлайн-работы скачайте локальные копии в `assets/codemirror/` и перепишите пути в `code_block_field.libraries.yml`
- CSRF-токен генерируется на каждую сессию, поэтому render-array инлайн-редактора имеет `#cache['max-age'] = 0`

## Удаление модуля

1. Отключите модуль через админку или Drush:
   ```bash
   drush pm:uninstall code_block_field -y
   ```
2. При удалении Drupal автоматически удалит конфигурацию модуля. Field storage и данные поля также удалятся автоматически. File usage записи освобождаются через `hook_entity_delete()` при удалении сущности-хоста

## Совместимость

| Drupal | PHP | Статус |
|--------|-----|--------|
| 9.5.x  | 7.4+ | Полная поддержка |
| 10.x   | 8.1+ | Полная поддержка |
| 11.x   | 8.3+ | Полная поддержка |

## Лицензия

GPL-2.0-or-later, как и ядро Drupal.

## Автор

- **Mmitekk** — [https://github.com/Mmitekk](https://github.com/Mmitekk)

## Ссылки

- **Репозиторий:** [https://github.com/Mmitekk/code_block_field](https://github.com/Mmitekk/code_block_field)
- **Issues:** [https://github.com/Mmitekk/code_block_field/issues](https://github.com/Mmitekk/code_block_field/issues)
- **English documentation:** [README.en.md](./README.en.md)
