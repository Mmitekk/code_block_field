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

    // Add a watchdog log entry ONCE per request to help diagnose inline
    // editor enable/disable decisions. Without this, when inline editing
    // silently fails to load (no JS, no toolbar), it's very hard to tell
    // whether the formatter decided to disable it or the JS just failed
    // to initialise.
    static $logged = FALSE;
    if (!$logged) {
      $logged = TRUE;
      \Drupal::logger('code_block_field')->debug('Formatter: inline_enabled=@enabled, enable_inline_editing_setting=@setting, has_permission=@perm, user_id=@uid, entity_type=@type, entity_id=@id, field=@field', [
        '@enabled' => $inline_enabled ? 'yes' : 'no',
        '@setting' => !empty($settings['enable_inline_editing']) ? 'yes' : 'no',
        '@perm' => \Drupal::currentUser()->hasPermission('use code block field inline editor') ? 'yes' : 'no',
        '@uid' => \Drupal::currentUser()->id(),
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@field' => $field_name,
      ]);
    }

    foreach ($items as $delta => $item) {
      $assets = is_array($item->assets) ? $item->assets : [];
      // Resolve managed file URLs and inject them into the HTML.
      // HTML is NOT re-filtered on render — filtering happens once, at
      // save time (hook_entity_presave + InlineEditController::save).
      // Re-filtering on render caused SVG attributes like `viewBox` to be
      // stripped because DOMDocument (used internally by filter_html)
      // normalises attribute names to lowercase, after which the
      // case-sensitive whitelist match in filter_html fails.
      $html = $this->processAssets((string) $item->html, $assets, $settings['image_style'] ?? '');

      // Unique, stable ID for this block instance on the page.
      $instance_id = Html::getUniqueId(sprintf(
        'cbf-%s-%s-%s-%d',
        $entity->getEntityTypeId(),
        $entity->id() ?: 'new',
        $field_name,
        $delta
      ));

      // Priority loading: render the block server-side via Declarative
      // Shadow DOM so it is visible on the first paint. Closed shadow
      // mode is incompatible with declarative shadow DOM (the parser
      // creates the root and we can no longer capture a reference for
      // the inline editor), so priority forces "open".
      $priority = !empty($item->priority);
      $shadow_mode = $settings['shadow_mode'] ?? 'open';
      if ($priority) {
        $shadow_mode = 'open';
      }

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
        '#shadow_mode' => $shadow_mode,
        '#inline_enabled' => $inline_enabled,
        '#priority' => $priority,
        // CSS pre-processed server-side for the Declarative Shadow DOM
        // template. The theme-variable bridge (--var: inherit) is still
        // injected client-side by renderer.js (it needs getComputedStyle).
        '#prepared_css' => $priority ? $this->prepareCssServerSide((string) $item->css) : '',
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
      // Use BOTH user.permissions and user contexts. user.permissions
      // varies the cache by permission sets (so admin vs anon get
      // different cached versions). user varies by user ID (so two
      // different admins get different cached versions, which matters
      // because the CSRF token in drupalSettings is per-session).
      $elements[$delta]['#cache']['contexts'][] = 'user.permissions';
      $elements[$delta]['#cache']['contexts'][] = 'user';
    }
    return $elements;
  }

  /**
   * Pre-processes the user-supplied CSS so that it works inside the
   * Declarative Shadow DOM that the formatter emits for priority blocks.
   *
   * This is a PHP port of the two selector rewrites performed in
   * js/renderer.js (prepareCss) that do NOT require access to the live
   * document:
   *  1. `:root { ... }` → `:host { ... }` (custom properties declared on
   *     :root are invisible inside a shadow root; :host resolves them on
   *     the host element).
   *  2. `html, body { ... }` (and `html`/`body` alone) → `:host { ... }`
   *     (there is no <html>/<body> inside a shadow root).
   *
   * The theme-variable bridge (`:host { --var: inherit; }`) requires
   * getComputedStyle() and is therefore still injected client-side by
   * renderer.js after hydration.
   *
   * Kept in sync with the JS implementation in js/renderer.js.
   */
  protected function prepareCssServerSide(string $css): string {
    if ($css === '') {
      return $css;
    }

    // 1. :root { → :host {
    $css = preg_replace('/(^|\s|;|}):root\s*\{/', '$1:host {', $css);

    // 2. html, body / body, html / html / body { → :host {
    $css = preg_replace('/(^|\s|;|})\s*(html\s*,\s*body|body\s*,\s*html|html|body)\s*\{/', '$1 :host {', $css);

    return $css;
  }

  /**
   * Replaces <img data-cbf-asset="key"> src with the actual file URL.
   *
   * Also fills empty src attributes, normalises missing alt text and
   * optionally swaps in an image-style derivative.
   *
   * IMPORTANT: this method uses a regex-based approach instead of
   * DOMDocument. DOMDocument normalises attribute names to lowercase
   * (e.g. `viewBox` → `viewbox`), which breaks SVG icons and is
   * case-sensitively checked against the allowed_html whitelist later.
   * Regex replacement leaves the rest of the HTML (including SVG
   * attributes) completely untouched.
   */
  protected function processAssets(string $html, array $assets, string $image_style): string {
    if (empty($assets) || $html === '') {
      return $html;
    }
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $file_url = \Drupal::service('file_url_generator');
    $image_style_storage = \Drupal::entityTypeManager()->getStorage('image_style');

    // Pre-resolve asset URLs and alt-text defaults so we don't re-load
    // the file inside the regex callback.
    $resolved = [];
    foreach ($assets as $key => $asset) {
      $fid = $asset['fid'] ?? 0;
      if (!$fid) {
        continue;
      }
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
      $resolved[$key] = [
        'url' => $url,
        'alt' => $asset['alt'] ?? '',
      ];
    }

    if (empty($resolved)) {
      return $html;
    }

    // Regex-based replacement of <img ... data-cbf-asset="KEY" ...>.
    // We match the entire <img> tag and then patch its `src` attribute
    // (and add `alt` if missing). Everything else in the HTML — including
    // <svg> tags with their case-sensitive attributes like `viewBox` —
    // is left completely untouched.
    return preg_replace_callback(
      '/<img\b([^>]*?\bdata-cbf-asset="([^"]+)")[^>]*>/i',
      function ($m) use ($resolved) {
        $full_tag = $m[0];
        $key = $m[2];
        if (!isset($resolved[$key])) {
          return $full_tag;
        }
        $url = $resolved[$key]['url'];
        $alt_default = $resolved[$key]['alt'];

        // Replace existing src="..." or insert a new src attribute.
        if (preg_match('/\bsrc="([^"]*)"/i', $full_tag)) {
          $full_tag = preg_replace('/\bsrc="[^"]*"/i', 'src="' . htmlspecialchars($url, ENT_QUOTES) . '"', $full_tag, 1);
        }
        else {
          // Insert src right after the <img.
          $full_tag = preg_replace('/^<img\b/i', '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '"', $full_tag, 1);
        }

        // If alt is missing and we have a default, add it.
        if ($alt_default && !preg_match('/\balt="[^"]*"/i', $full_tag)) {
          $full_tag = preg_replace('/^<img\b/i', '<img alt="' . htmlspecialchars($alt_default, ENT_QUOTES) . '"', $full_tag, 1);
        }

        return $full_tag;
      },
      $html
    );
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
