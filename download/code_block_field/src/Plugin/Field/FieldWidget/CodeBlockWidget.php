<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
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
      '#title' => $this->t('Textarea rows'),
      '#default_value' => $this->getSetting('rows'),
      '#min' => 4,
      '#max' => 60,
    ];
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('CodeMirror theme'),
      '#options' => [
        'material-darker' => $this->t('Material Darker'),
        'default' => $this->t('Default (light)'),
        'dracula' => $this->t('Dracula'),
        'nord' => $this->t('Nord'),
        'monokai' => $this->t('Monokai'),
      ],
      '#default_value' => $this->getSetting('theme'),
    ];
    $form['tab_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Tab size'),
      '#default_value' => $this->getSetting('tab_size'),
      '#min' => 1,
      '#max' => 8,
    ];
    $form['line_numbers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show line numbers'),
      '#default_value' => $this->getSetting('line_numbers'),
    ];
    $form['auto_close_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-close HTML tags'),
      '#default_value' => $this->getSetting('auto_close_tags'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      $this->t('Rows: @rows, Theme: @theme', [
        '@rows' => $this->getSetting('rows'),
        '@theme' => $this->getSetting('theme'),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];

    $element += [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Code Block #@delta', ['@delta' => $delta + 1]),
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
      '#description' => $this->t('Use <code>&lt;img data-cbf-asset="KEY" src="…" alt="…"&gt;</code> to mark images as managed assets. The editor will keep their file references in sync.'),
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
      '#description' => $this->t('CSS is scoped to the block’s Shadow DOM. Use <code>:host</code> for the host element.'),
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
      '#description' => $this->t('Executes after the Shadow DOM is mounted. The variable <code>host</code> refers to the host element, <code>shadowRoot</code> to its shadow root.'),
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
      '#title' => $this->t('Managed assets (@count)', ['@count' => count($assets)]),
      '#group' => 'code',
      '#attributes' => ['class' => ['code-block-asset-browser']],
    ];

    if (empty($assets)) {
      $build['empty'] = [
        '#markup' => '<p class="description">' . $this->t('No managed assets yet. Use the inline editor on the page or add <code>&lt;img data-cbf-asset="KEY"&gt;</code> in the HTML and upload the file via the inline picker.')->render() . '</p>',
      ];
      return $build;
    }

    $header = [
      $this->t('Key'),
      $this->t('Preview'),
      $this->t('File'),
      $this->t('Alt'),
      $this->t('Operations'),
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
          $file ? $file->getFilename() : $this->t('(missing file)'),
          $asset['alt'] ?? '',
          '',
        ],
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No assets.'),
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

}
