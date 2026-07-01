<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Formatter that renders the code block inside a Shadow DOM.
 *
 * The server-side output is a single <div class="cbf-host"> carrying all the
 * metadata the client needs to mount its Shadow DOM (HTML, CSS, JS, asset
 * URLs, host entity reference, etc.). The actual mount happens in
 * js/renderer.js so that no inline script / style tags leak into the host
 * document.
 *
 * @FieldFormatter(
 *   id = "code_block_formatter",
 *   label = @Translation("Code Block (Shadow DOM, inline-editable)"),
 *   field_types = { "code_block" }
 * )
 */
class CodeBlockFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'shadow_mode' => 'open',
      'enable_inline_editing' => TRUE,
      'image_style' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['shadow_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Режим Shadow DOM'),
      '#options' => [
        'open' => $this->t('Open'),
        'closed' => $this->t('Closed (дополнительная изоляция)'),
      ],
      '#default_value' => $this->getSetting('shadow_mode'),
    ];
    $form['enable_inline_editing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Включить инлайн-редактирование в этом режиме отображения'),
      '#default_value' => $this->getSetting('enable_inline_editing'),
      '#description' => $this->t('Даже если включено — пользователю всё равно нужно право «use code block field inline editor».'),
    ];
    $image_styles = ['' => $this->t('- Нет (оригинал) -')];
    foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple() as $style) {
      $image_styles[$style->id()] = $style->label();
    }
    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Стиль изображений для управляемых ассетов'),
      '#options' => $image_styles,
      '#default_value' => $this->getSetting('image_style'),
      '#empty_option' => $this->t('- Оригинал -'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      $this->t('Shadow: @mode, Инлайн: @inline', [
        '@mode' => $this->getSetting('shadow_mode'),
        '@inline' => $this->getSetting('enable_inline_editing') ? $this->t('вкл') : $this->t('выкл'),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode = NULL) {
    $elements = [];
    $entity = $items->getEntity();
    $field_name = $items->getName();
    $settings = $this->getSettings();
    $inline_enabled = !empty($settings['enable_inline_editing'])
      && \Drupal::currentUser()->hasPermission('use code block field inline editor');

    foreach ($items as $delta => $item) {
      $assets = is_array($item->assets) ? $item->assets : [];
      // Resolve managed file URLs and inject them into the HTML.
      $html = $this->processAssets((string) $item->html, $assets, $settings['image_style'] ?? '');

      // Sanitise HTML using the configured allowed-html list.
      $html = $this->filterHtml($html);

      // Unique, stable ID for this block instance on the page.
      $instance_id = Html::getUniqueId(sprintf(
        'cbf-%s-%s-%s-%d',
        $entity->getEntityTypeId(),
        $entity->id() ?: 'new',
        $field_name,
        $delta
      ));

      $elements[$delta] = [
        '#theme' => 'code_block_field',
        '#instance_id' => $instance_id,
        '#html' => $html,
        '#css' => (string) $item->css,
        '#js' => (string) $item->js,
        '#entity_type' => $entity->getEntityTypeId(),
        '#entity_id' => $entity->id(),
        '#field_name' => $field_name,
        '#delta' => $delta,
        '#langcode' => $langcode ?? $entity->language()->getId(),
        '#shadow_mode' => $settings['shadow_mode'] ?? 'open',
        '#inline_enabled' => $inline_enabled,
        '#attached' => [
          'library' => ['code_block_field/renderer'],
        ],
      ];
      if ($inline_enabled) {
        $elements[$delta]['#attached']['library'][] = 'code_block_field/inline_editor';
        // Pass the inline-save/upload endpoints and a CSRF token (valid for
        // the inline-save route) to the client. The token is session-specific
        // so the whole element must be regenerated for every request when
        // inline editing is enabled.
        $config = \Drupal::config('code_block_field.settings');
        $elements[$delta]['#attached']['drupalSettings']['code_block_field'] = [
          'endpoints' => [
            'save' => Url::fromRoute('code_block_field.inline_save')->toString(),
            'upload' => Url::fromRoute('code_block_field.image_upload')->toString(),
          ],
          'csrf_token' => \Drupal::csrfToken()->get('/admin/code-block-field/inline-save'),
          'colors' => [
            'toolbar_bg' => $config->get('color_toolbar_bg') ?? '#1e1e2e',
            'toolbar_accent' => $config->get('color_toolbar_accent') ?? '#ff8a3d',
            'edit_outline' => $config->get('color_edit_outline') ?? '#ff8a3d',
            'dirty_outline' => $config->get('color_dirty_outline') ?? '#28a745',
            'focus_outline' => $config->get('color_focus_outline') ?? '#0071eb',
          ],
        ];
        $elements[$delta]['#cache']['max-age'] = 0;
        $elements[$delta]['#cache']['tags'][] = 'config:code_block_field.settings';
      }
      $elements[$delta]['#cache']['tags'] = array_merge(
        $elements[$delta]['#cache']['tags'] ?? [],
        $entity->getCacheTags()
      );
      $elements[$delta]['#cache']['contexts'][] = 'user.permissions';
    }
    return $elements;
  }

  /**
   * Replaces <img data-cbf-asset="key"> src with the actual file URL.
   *
   * Also fills empty src attributes, normalises missing alt text and
   * optionally swaps in an image-style derivative.
   */
  protected function processAssets(string $html, array $assets, string $image_style): string {
    if (empty($assets) || $html === '') {
      return $html;
    }
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $file_url = \Drupal::service('file_url_generator');
    $image_style_storage = \Drupal::entityTypeManager()->getStorage('image_style');

    // Use DOMDocument for robust replacement.
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    // Wrap in a utf-8 meta so DOMDocument parses the encoding correctly.
    $dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $imgs = $dom->getElementsByTagName('img');
    foreach ($imgs as $img) {
      $key = $img->getAttribute('data-cbf-asset');
      if ($key === '' || !isset($assets[$key])) {
        continue;
      }
      $fid = $assets[$key]['fid'] ?? 0;
      if (!$fid) {
        continue;
      }
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $file_storage->load($fid);
      if (!$file) {
        continue;
      }
      if ($image_style && ($style = $image_style_storage->load($image_style))) {
        /** @var \Drupal\image\ImageStyleInterface $style */
        $url = $style->buildUrl($file->getFileUri());
      }
      else {
        $url = $file_url->generateAbsoluteString($file->getFileUri());
      }
      $img->setAttribute('src', $url);
      if (!empty($assets[$key]['alt']) && !$img->getAttribute('alt')) {
        $img->setAttribute('alt', $assets[$key]['alt']);
      }
    }

    // Extract the inner HTML of the wrapper <div>.
    $wrapper = $dom->getElementsByTagName('div')->item(0);
    $inner = '';
    if ($wrapper) {
      foreach ($wrapper->childNodes as $child) {
        $inner .= $dom->saveHTML($child);
      }
    }
    return $inner;
  }

  /**
   * Filters the HTML using the global allowed-html setting.
   */
  protected function filterHtml(string $html): string {
    $config = \Drupal::config('code_block_field.settings');
    if (!$config->get('filter_html')) {
      return $html;
    }
    $allowed = $config->get('allowed_html');
    if (!$allowed) {
      return $html;
    }
    /** @var \Drupal\filter\Plugin\FilterInterface $filter */
    $filter = \Drupal::service('plugin.manager.filter')->createInstance('filter_html', [
      'settings' => [
        'allowed_html' => $allowed,
        'filter_html_help' => FALSE,
        'filter_html_nofollow' => FALSE,
      ],
    ]);
    $result = $filter->process($html, 'und');
    return (string) $result;
  }

}
