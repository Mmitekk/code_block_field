<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the "code_block_widget" widget.
 *
 * Renders three textareas (HTML / CSS / JS) and a managed-file browser for
 * assets referenced by &lt;img data-cbf-asset="key"&gt; placeholders inside
 * the HTML. CodeMirror is attached client-side via the `code_block_field/codemirror`
 * library.
 *
 * @FieldWidget(
 *   id = "code_block_widget",
 *   label = @Translation("Code Block editor (CodeMirror)"),
 *   field_types = { "code_block" }
 * )
 */
class CodeBlockWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'rows' => 14,
      'theme' => 'material-darker',
      'tab_size' => 2,
      'line_numbers' => TRUE,
      'auto_close_tags' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['rows'] = [
      '#type' => 'number',
      '#title' => $this->t('Количество строк'),
      '#default_value' => $this->getSetting('rows'),
      '#min' => 4,
      '#max' => 60,
    ];
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Тема CodeMirror'),
      '#options' => [
        'material-darker' => $this->t('Material Darker'),
        'default' => $this->t('По умолчанию (светлая)'),
        'dracula' => $this->t('Dracula'),
        'nord' => $this->t('Nord'),
        'monokai' => $this->t('Monokai'),
      ],
      '#default_value' => $this->getSetting('theme'),
    ];
    $form['tab_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Размер табуляции'),
      '#default_value' => $this->getSetting('tab_size'),
      '#min' => 1,
      '#max' => 8,
    ];
    $form['line_numbers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Показывать номера строк'),
      '#default_value' => $this->getSetting('line_numbers'),
    ];
    $form['auto_close_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Автозакрытие HTML-тегов'),
      '#default_value' => $this->getSetting('auto_close_tags'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      $this->t('Строк: @rows, Тема: @theme', [
        '@rows' => $this->getSetting('rows'),
        '@theme' => $this->getSetting('theme'),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Wraps the whole field widget into a stable container. Per-block AJAX
   * actions (clone / delete) replace this container after the rebuild.
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $build = parent::form($items, $form, $form_state);
    $wrapper_id = $this->buildWrapperId($form['#parents'] ?? []);
    $build['#prefix'] = ($build['#prefix'] ?? '') . '<div id="' . $wrapper_id . '">';
    $build['#suffix'] = '</div>' . ($build['#suffix'] ?? '');
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];

    $element += [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Блок кода #@delta', ['@delta' => $delta + 1]),
      '#attributes' => [
        'class' => ['code-block-field-widget'],
        'data-cbf-widget' => TRUE,
      ],
    ];

    $widget_settings = [
      'theme' => $this->getSetting('theme'),
      'tabSize' => (int) $this->getSetting('tab_size'),
      'lineNumbers' => (bool) $this->getSetting('line_numbers'),
      'autoCloseTags' => (bool) $this->getSetting('auto_close_tags'),
    ];
    $element['#attributes']['data-cbf-settings'] = json_encode($widget_settings);

    // Per-block actions: copy code / clone / delete, exposed as a small
    // dropdown next to the block title. Clone & delete are AJAX buttons that
    // shift deltas in the raw user input server-side (see cbfShiftDeltas());
    // the whole widget is replaced after the rebuild, so names, ids and
    // CodeMirror editors stay consistent. Only meaningful for unlimited
    // fields — a fixed-cardinality field cannot gain or lose deltas.
    $is_multiple = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality() === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED;
    $wrapper_id = $this->buildWrapperId($element['#field_parents'] ?? []);

    $menu['copy'] = [
      '#type' => 'inline_template',
      '#template' => '<button type="button" class="cbf-actions-item" data-cbf-copy>{{ label }}</button>',
      '#context' => ['label' => $this->t('Скопировать код')],
    ];

    if ($is_multiple) {
      $field_path = array_merge($element['#field_parents'], [$this->fieldDefinition->getName()]);
      $ajax = [
        'callback' => [static::class, 'cbfWidgetAjax'],
        'wrapper' => $wrapper_id,
        // Core binds AJAX submit buttons on mousedown by default, which
        // would fire the request BEFORE the JS confirmation dialog for
        // delete (bound as a capture click listener) can veto it. Bind on
        // click instead so the confirm runs first.
        'event' => 'click',
      ];
      $menu['clone'] = [
        '#type' => 'submit',
        '#value' => $this->t('Клонировать'),
        '#name' => 'cbf_clone_' . $delta . '_' . Html::getId($this->fieldDefinition->getName()),
        '#submit' => [[static::class, 'cbfCloneSubmit']],
        '#limit_validation_errors' => [$field_path],
        '#ajax' => $ajax,
        '#attributes' => ['class' => ['cbf-actions-item']],
        '#cbf_field_name' => $this->fieldDefinition->getName(),
        '#cbf_field_parents' => $element['#field_parents'],
        '#cbf_delta' => $delta,
      ];
      $menu['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Удалить'),
        '#name' => 'cbf_delete_' . $delta . '_' . Html::getId($this->fieldDefinition->getName()),
        '#submit' => [[static::class, 'cbfDeleteSubmit']],
        '#limit_validation_errors' => [$field_path],
        '#ajax' => $ajax,
        '#attributes' => [
          'class' => ['cbf-actions-item', 'cbf-item-danger'],
          'data-cbf-confirm' => $this->t('Удалить этот блок?')->render(),
        ],
        '#cbf_field_name' => $this->fieldDefinition->getName(),
        '#cbf_field_parents' => $element['#field_parents'],
        '#cbf_delta' => $delta,
      ];
    }

    $element['cbf_actions'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['cbf-actions']],
      'toggle' => [
        '#type' => 'inline_template',
        '#template' => '<button type="button" class="cbf-actions-toggle" title="{{ title }}" aria-haspopup="true" aria-expanded="false">⋮</button>',
        '#context' => ['title' => $this->t('Действия с блоком')],
      ],
      'menu' => $menu + [
        '#type' => 'container',
        '#attributes' => ['class' => ['cbf-actions-menu'], 'hidden' => 'hidden'],
      ],
    ];

    // Priority loading: render the block server-side via Declarative Shadow
    // DOM so it is visible on the first paint (no wait for renderer.js).
    // Use for above-the-fold / first-screen blocks. Forces "open" shadow
    // mode (closed is incompatible with declarative shadow DOM + the inline
    // editor's shadow-root reference).
    $element['priority'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Приоритетная загрузка'),
      '#description' => $this->t('Рендерить блок серверно (Declarative Shadow DOM), чтобы он был виден сразу при первой отрисовке страницы, не дожидаясь выполнения JS. Используйте для блоков «первого экрана» (например, главного экрана сайта).'),
      '#default_value' => (bool) ($item->priority ?? FALSE),
    ];

    // Use vertical tabs to switch HTML/CSS/JS without scrolling.
    $element['code'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-' . Html::getId($this->fieldDefinition->getName()) . '-' . $delta . '-html',
    ];

    $element['html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('HTML'),
      '#default_value' => $item->html ?? '',
      '#rows' => $this->getSetting('rows'),
      '#description' => $this->t('Используйте <code>&lt;img data-cbf-asset="KEY" src="…" alt="…"&gt;</code>, чтобы пометить картинки как управляемые ассеты. Редактор будет синхронизировать ссылки на файлы автоматически. Если в настройках модуля включено авто-присвоение — атрибут будет добавлен всем картинкам без него при сохранении.'),
      '#attributes' => [
        'class' => ['code-block-editor', 'code-block-editor-html'],
        'data-cbf-mode' => 'htmlmixed',
      ],
      '#group' => 'code',
    ];

    $element['css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CSS'),
      '#default_value' => $item->css ?? '',
      '#rows' => $this->getSetting('rows'),
      '#description' => $this->t('CSS изолирован в Shadow DOM блока. Используйте <code>:host</code> для обращения к хост-элементу.'),
      '#attributes' => [
        'class' => ['code-block-editor', 'code-block-editor-css'],
        'data-cbf-mode' => 'css',
      ],
      '#group' => 'code',
    ];

    $element['js'] = [
      '#type' => 'textarea',
      '#title' => $this->t('JavaScript'),
      '#default_value' => $item->js ?? '',
      '#rows' => $this->getSetting('rows'),
      '#description' => $this->t('Выполняется после монтирования Shadow DOM. Переменная <code>host</code> указывает на хост-элемент, <code>shadowRoot</code> — на его shadow root.'),
      '#attributes' => [
        'class' => ['code-block-editor', 'code-block-editor-js'],
        'data-cbf-mode' => 'javascript',
      ],
      '#group' => 'code',
    ];

    // Hidden serialized assets map. The browser-side code keeps it in sync
    // with the HTML whenever the user inserts an asset through the picker.
    $element['assets'] = [
      '#type' => 'hidden',
      '#default_value' => json_encode($item->assets ?? []),
      '#attributes' => ['class' => ['code-block-assets']],
    ];

    // Existing-assets browser (managed files attached to this field item).
    $element['asset_browser'] = $this->buildAssetBrowser($items, $delta, $item->assets ?? []);

    $element['#attached']['library'][] = 'code_block_field/widget';
    $element['#attached']['library'][] = 'code_block_field/codemirror';

    return $element;
  }

  /**
   * Builds a small table that lists existing assets and lets the user replace
   * or remove them.
   */
  protected function buildAssetBrowser(FieldItemListInterface $items, int $delta, array $assets): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Управляемые ассеты (@count)', ['@count' => count($assets)]),
      '#group' => 'code',
      '#attributes' => ['class' => ['code-block-asset-browser']],
    ];

    if (empty($assets)) {
      $build['empty'] = [
        '#markup' => '<p class="description">' . $this->t('Пока нет управляемых ассетов. Используйте инлайн-редактор на странице или добавьте <code>&lt;img data-cbf-asset="KEY"&gt;</code> в HTML и загрузите файл через инлайн-пикер. Если в настройках модуля включено авто-присвоение — все картинки получат ключ автоматически при сохранении.')->render() . '</p>',
      ];
      return $build;
    }

    $header = [
      $this->t('Ключ'),
      $this->t('Превью'),
      $this->t('Файл'),
      $this->t('Alt'),
      $this->t('Действия'),
    ];
    $rows = [];
    foreach ($assets as $key => $asset) {
      $fid = $asset['fid'] ?? 0;
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $fid ? \Drupal::entityTypeManager()->getStorage('file')->load($fid) : NULL;
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<code>' . htmlspecialchars((string) $key) . '</code>']],
          $file ? [
            'data' => [
              '#type' => 'inline_template',
              '#template' => '<img src="{{ url }}" alt="" style="max-width:80px;max-height:80px">',
              '#context' => ['url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri())],
            ],
          ] : '-',
          $file ? $file->getFilename() : $this->t('(файл отсутствует)'),
          $asset['alt'] ?? '',
          '',
        ],
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('Нет ассетов.'),
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Decodes the JSON-encoded assets map submitted by the hidden field back
   * into an array so the field type can store it.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $delta => $value) {
      if (!empty($values[$delta]['assets']) && is_string($values[$delta]['assets'])) {
        $decoded = json_decode($values[$delta]['assets'], TRUE);
        $values[$delta]['assets'] = is_array($decoded) ? $decoded : [];
      }
      else {
        $values[$delta]['assets'] = [];
      }
    }
    return $values;
  }

  /**
   * AJAX callback for the per-block clone / delete buttons.
   *
   * Returns the whole (already rebuilt) field widget container so that the
   * shifted deltas are re-rendered and the editors re-initialised on the
   * client. The button sits at [..., field_name, 'widget', delta,
   * 'cbf_actions', 'menu', button], so we walk five levels up.
   */
  public static function cbfWidgetAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -5), $exists);
    if (!$exists || !is_array($element)) {
      return new AjaxResponse();
    }
    return $element;
  }

  /**
   * Submit handler for the per-block "Клонировать" button.
   */
  public static function cbfCloneSubmit(array $form, FormStateInterface $form_state) {
    static::cbfShiftDeltas($form_state, 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the per-block "Удалить" button.
   */
  public static function cbfDeleteSubmit(array $form, FormStateInterface $form_state) {
    static::cbfShiftDeltas($form_state, -1);
    $form_state->setRebuild();
  }

  /**
   * Inserts a duplicate of the acted-on delta right after it (+1) or removes
   * the delta (-1) in the raw user input, re-indexes the tail and updates
   * the widget state. The subsequent rebuild re-renders all deltas from the
   * adjusted input, so field names, element ids and editors stay consistent.
   */
  protected static function cbfShiftDeltas(FormStateInterface $form_state, int $direction): void {
    $button = $form_state->getTriggeringElement();
    $field_name = $button['#cbf_field_name'];
    $field_parents = $button['#cbf_field_parents'];
    $delta = (int) $button['#cbf_delta'];

    $path = array_merge($field_parents, [$field_name]);
    $input = $form_state->getUserInput();
    $field_input = NestedArray::getValue($input, $path, $exists);
    if (!$exists || !is_array($field_input)) {
      return;
    }
    unset($field_input['add_more']);
    $deltas = array_values($field_input);
    // Core renders items_count + 1 deltas (always one trailing empty row).
    // Trim trailing empty deltas first so that after the splice the rebuilt
    // form keeps exactly one empty row at the bottom.
    while (count($deltas) > 1 && static::cbfDeltaIsEmpty(end($deltas))) {
      array_pop($deltas);
    }
    if ($delta < 0) {
      return;
    }
    // The acted-on delta may have been a trimmed trailing empty row.
    if ($delta >= count($deltas)) {
      $delta = count($deltas) - 1;
    }
    if ($direction > 0) {
      array_splice($deltas, $delta + 1, 0, [$deltas[$delta]]);
    }
    else {
      array_splice($deltas, $delta, 1);
    }
    $field_input = [];
    foreach ($deltas as $i => $item_value) {
      $field_input[(string) $i] = $item_value;
    }
    NestedArray::setValue($input, $path, $field_input);
    $form_state->setUserInput($input);

    // Keep the form-state entity in sync: core renders items_count + 1 rows
    // and the extra trailing row falls back to the entity's item at that
    // delta. Without this trim the row would resurrect the last deleted
    // item as its default value (and it would be saved back as junk).
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface) {
      $entity = $form_object->getEntity();
      if ($entity->hasField($field_name)) {
        $entity_items = $entity->get($field_name);
        while ($entity_items->count() > count($deltas)) {
          $entity_items->removeItem($entity_items->count() - 1);
        }
      }
    }

    $field_state = static::getWidgetState($field_parents, $field_name, $form_state);
    if (is_array($field_state)) {
      $field_state['items_count'] = count($deltas);
      static::setWidgetState($field_parents, $field_name, $form_state, $field_state);
    }
  }

  /**
   * Checks whether a submitted delta has no user-visible content.
   */
  protected static function cbfDeltaIsEmpty(array $delta_input): bool {
    foreach (['html', 'css', 'js'] as $key) {
      if (isset($delta_input[$key]) && trim((string) $delta_input[$key]) !== '') {
        return FALSE;
      }
    }
    $assets = $delta_input['assets'] ?? '';
    $decoded = is_string($assets) ? json_decode($assets, TRUE) : $assets;
    if (is_array($decoded) && $decoded) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Stable HTML id of the wrapper around the whole field widget; used as the
   * AJAX replace target of the per-block actions.
   */
  protected function buildWrapperId(array $field_parents): string {
    return 'cbf-wrapper-' . Html::getId(implode('-', array_merge($field_parents, [$this->fieldDefinition->getName()])));
  }

}
