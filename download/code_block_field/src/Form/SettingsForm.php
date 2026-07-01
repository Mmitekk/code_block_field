<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Форма глобальных настроек модуля Code Block Field.
 *
 * Содержит настройки:
 *  - HTML-фильтр (разрешённые теги + вкл/выкл)
 *  - Хранилище файлов (путь загрузки, макс. размер, image style по умолчанию)
 *  - Режим Shadow DOM (open / closed)
 *  - UX инлайн-редактора (видимость плавающей кнопки)
 *  - Создание ревизий при inline-сохранении
 *  - Авто-присвоение data-cbf-asset картинкам
 *  - Цвета редактора (toolbar + визуальные индикаторы)
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['code_block_field.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'code_block_field_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('code_block_field.settings');

    // ===== HTML-фильтр =====
    $form['filter'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('HTML-фильтр'),
      '#description' => $this->t('Сохраняемый HTML пропускается через ядерный плагин <em>filter_html</em> при каждом сохранении (как из формы сущности, так и из инлайн-редактора). Список разрешённых тегов определяет, какие теги и атрибуты сохраняются.'),
    ];

    $form['filter']['filter_html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Фильтровать HTML при сохранении'),
      '#description' => $this->t('Если выключено — HTML сохраняется как есть. Рекомендуется только для сайтов с одним доверенным редактором.'),
      '#default_value' => (bool) $config->get('filter_html'),
    ];

    $form['filter']['allowed_html'] = [
      '#type' => 'textarea',
      '#rows' => 6,
      '#title' => $this->t('Разрешённые HTML-теги'),
      '#description' => $this->t('Формат такой же, как у фильтра HTML модуля Filter. Пример: <code>&lt;a href target rel class&gt; &lt;img src alt title width height class data-*&gt;</code>. Используйте <code>data-*</code>, чтобы разрешить любой атрибут <code>data-*</code>.'),
      '#default_value' => $config->get('allowed_html'),
    ];

    // ===== Хранилище файлов =====
    $form['files'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Хранилище файлов'),
      '#description' => $this->t('Куда инлайн-редактор загружает картинки и какой максимальный размер файла.'),
    ];

    $form['files']['upload_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Путь загрузки'),
      '#description' => $this->t('Путь со stream wrapper’ом, куда сохраняются картинки, загруженные через инлайн-редактор. Пример: <code>public://code-block-field</code>.'),
      '#default_value' => $config->get('upload_location'),
      '#required' => TRUE,
    ];

    $form['files']['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Максимальный размер файла'),
      '#description' => $this->t('Примеры: <code>2 MB</code>, <code>1024 KB</code>, <code>1G</code>. Оставьте пустым для значения по умолчанию сайта.'),
      '#default_value' => $config->get('max_filesize'),
    ];

    $image_styles = ['' => $this->t('- Оригинал (без стиля) -')];
    foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple() as $style) {
      $image_styles[$style->id()] = $style->label();
    }
    $form['files']['default_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Стиль изображений по умолчанию'),
      '#options' => $image_styles,
      '#default_value' => $config->get('default_image_style'),
      '#description' => $this->t('Используется форматтером, если на уровне поля не задан другой.'),
    ];

    // ===== Shadow DOM =====
    $form['shadow'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Shadow DOM'),
      '#description' => $this->t('Каждый блок рендерится внутри Shadow DOM, чтобы CSS блока не утекал в тему. Режим <em>Closed</em> запрещает внешним скриптам доступ к блоку через <code>element.shadowRoot</code>.'),
    ];

    $form['shadow']['shadow_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Режим Shadow DOM по умолчанию'),
      '#options' => [
        'open' => $this->t('Open (доступен для инспектирования разработчиком)'),
        'closed' => $this->t('Closed (дополнительная изоляция)'),
      ],
      '#default_value' => $config->get('shadow_mode'),
    ];

    // ===== Инлайн-редактор =====
    $form['editor'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Инлайн-редактор'),
      '#description' => $this->t('Когда и как показывать инлайн-редактор пользователю.'),
    ];

    $form['editor']['expose_edit_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Показывать плавающую кнопку «Режим редактирования» на всех страницах с code-блоками'),
      '#description' => $this->t('Если выключено, интегратор должен запускать редактор вручную через <code>Drupal.codeBlockField.activate()</code>.'),
      '#default_value' => (bool) $config->get('expose_edit_button'),
    ];

    $form['editor']['create_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Создавать новую ревизию сущности при каждом inline-сохранении'),
      '#description' => $this->t('Если включено — каждое inline-сохранение (правка текста/картинки/ссылки) создаёт новую ревизию сущности (node, paragraph и т.д.). Улучшает аудит, но создаёт больше записей в истории ревизий. Применяется только к типам сущностей, поддерживающим ревизии.'),
      '#default_value' => (bool) $config->get('create_revisions'),
    ];

    $form['editor']['revision_log_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Сообщение лога ревизии по умолчанию'),
      '#description' => $this->t('Используется как лог ревизии, когда inline-сохранение создаёт ревизию. <code>%date</code> заменяется на текущую дату/время.'),
      '#default_value' => $config->get('revision_log_message') ?: 'Inline edit (%date)',
      '#states' => [
        'visible' => [
          ':input[name="create_revisions"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['editor']['auto_assign_asset_keys'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Автоматически добавлять data-cbf-asset картинкам'),
      '#description' => $this->t('Если включено — при сохранении всем картинкам &lt;img&gt; без атрибута <code>data-cbf-asset</code> автоматически присваивается уникальный ключ, что позволяет редактировать их в инлайн-режакторе. Если <code>src</code> указывает на managed file Drupal — он также привязывается к полю (с регистрацией file usage).'),
      '#default_value' => (bool) $config->get('auto_assign_asset_keys'),
    ];

    // ===== Цвета редактора =====
    $form['colors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Цвета редактора'),
      '#description' => $this->t('Визуальное оформление плавающей панели инлайн-редактора и индикаторов редактируемых элементов. Цвета передаются как CSS custom properties, поэтому их можно переопределить из темы.'),
    ];

    $form['colors']['color_toolbar_bg'] = [
      '#type' => 'color',
      '#title' => $this->t('Фон панели'),
      '#description' => $this->t('Фон плавающей панели редактора.'),
      '#default_value' => $config->get('color_toolbar_bg') ?? '#1e1e2e',
    ];

    $form['colors']['color_toolbar_accent'] = [
      '#type' => 'color',
      '#title' => $this->t('Акцентный цвет панели'),
      '#description' => $this->t('Фон кнопки «Режим редактирования» во включённом состоянии и бейдж панели.'),
      '#default_value' => $config->get('color_toolbar_accent') ?? '#ff8a3d',
    ];

    $form['colors']['color_edit_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Контур редактирования'),
      '#description' => $this->t('Цвет рамки вокруг редактируемого блока в режиме редактирования.'),
      '#default_value' => $config->get('color_edit_outline') ?? '#ff8a3d',
    ];

    $form['colors']['color_dirty_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Контур несохранённых изменений'),
      '#description' => $this->t('Цвет рамки блока с несохранёнными изменениями.'),
      '#default_value' => $config->get('color_dirty_outline') ?? '#28a745',
    ];

    $form['colors']['color_focus_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Контур активного блока'),
      '#description' => $this->t('Цвет рамки блока, в котором сейчас редактируется элемент.'),
      '#default_value' => $config->get('color_focus_outline') ?? '#0071eb',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('code_block_field.settings')
      ->set('filter_html', (bool) $form_state->getValue('filter_html'))
      ->set('allowed_html', $form_state->getValue('allowed_html'))
      ->set('upload_location', $form_state->getValue('upload_location'))
      ->set('max_filesize', $form_state->getValue('max_filesize'))
      ->set('default_image_style', $form_state->getValue('default_image_style'))
      ->set('shadow_mode', $form_state->getValue('shadow_mode'))
      ->set('expose_edit_button', (bool) $form_state->getValue('expose_edit_button'))
      ->set('create_revisions', (bool) $form_state->getValue('create_revisions'))
      ->set('revision_log_message', $form_state->getValue('revision_log_message'))
      ->set('auto_assign_asset_keys', (bool) $form_state->getValue('auto_assign_asset_keys'))
      ->set('color_toolbar_bg', $form_state->getValue('color_toolbar_bg'))
      ->set('color_toolbar_accent', $form_state->getValue('color_toolbar_accent'))
      ->set('color_edit_outline', $form_state->getValue('color_edit_outline'))
      ->set('color_dirty_outline', $form_state->getValue('color_dirty_outline'))
      ->set('color_focus_outline', $form_state->getValue('color_focus_outline'))
      ->save();

    // Invalidate field-renderer cache so the new colours show up immediately.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['code_block_field:settings']);

    parent::submitForm($form, $form_state);
  }

}
