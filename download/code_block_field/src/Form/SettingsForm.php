<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global configuration form for the Code Block Field module.
 *
 * Provides settings for:
 *  - HTML filtering (allowed tags + on/off toggle)
 *  - File storage (upload location, max filesize, default image style)
 *  - Shadow DOM mode (open / closed)
 *  - Inline editor UX (floating toolbar visibility)
 *  - Editor colours (toolbar + editable-element visual chrome)
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

    // ===== General / HTML filter =====
    $form['filter'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('HTML filter'),
      '#description' => $this->t('The submitted HTML is run through the core <em>filter_html</em> plugin on every save (both from the entity form and from the inline editor). The allowed-tags list controls which tags and attributes are kept.'),
    ];

    $form['filter']['filter_html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filter HTML on save'),
      '#description' => $this->t('When disabled, the raw HTML is stored as-is. Recommended only for sites with a single trusted editor.'),
      '#default_value' => (bool) $config->get('filter_html'),
    ];

    $form['filter']['allowed_html'] = [
      '#type' => 'textarea',
      '#rows' => 6,
      '#title' => $this->t('Allowed HTML tags'),
      '#description' => $this->t('Same format as the Filter module HTML filter. Example: <code>&lt;a href target rel class&gt; &lt;img src alt title width height class data-*&gt;</code>. Use <code>data-*</code> to allow any <code>data-*</code> attribute.'),
      '#default_value' => $config->get('allowed_html'),
    ];

    // ===== File storage =====
    $form['files'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('File storage'),
      '#description' => $this->t('Controls where the inline editor uploads images and how big they can be.'),
    ];

    $form['files']['upload_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Upload destination'),
      '#description' => $this->t('Stream wrapper path where inline-uploaded images are stored. Example: <code>public://code-block-field</code>.'),
      '#default_value' => $config->get('upload_location'),
      '#required' => TRUE,
    ];

    $form['files']['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum upload size'),
      '#description' => $this->t('Examples: <code>2 MB</code>, <code>1024 KB</code>, <code>1G</code>. Leave empty for the site default.'),
      '#default_value' => $config->get('max_filesize'),
    ];

    $image_styles = ['' => $this->t('- Original (no style) -')];
    foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple() as $style) {
      $image_styles[$style->id()] = $style->label();
    }
    $form['files']['default_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image style'),
      '#options' => $image_styles,
      '#default_value' => $config->get('default_image_style'),
      '#description' => $this->t('Used by the formatter when the field display does not override it.'),
    ];

    // ===== Shadow DOM =====
    $form['shadow'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Shadow DOM'),
      '#description' => $this->t('Each code block is rendered inside a Shadow DOM so its CSS does not leak into the host theme. <em>Closed</em> mode prevents external scripts from reaching into the block through <code>element.shadowRoot</code>.'),
    ];

    $form['shadow']['shadow_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Shadow DOM mode'),
      '#options' => [
        'open' => $this->t('Open (developer-inspectable)'),
        'closed' => $this->t('Closed (extra isolation)'),
      ],
      '#default_value' => $config->get('shadow_mode'),
    ];

    // ===== Inline editor UX =====
    $form['editor'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Inline editor'),
      '#description' => $this->t('Controls when and how the page-builder-style inline editor is exposed to the user.'),
    ];

    $form['editor']['expose_edit_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the floating "Edit Mode" button on every page where code blocks are present'),
      '#description' => $this->t('When disabled, integrators must trigger the editor manually via <code>Drupal.codeBlockField.activate()</code>.'),
      '#default_value' => (bool) $config->get('expose_edit_button'),
    ];

    // ===== Editor colours =====
    $form['colors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Editor colours'),
      '#description' => $this->t('Visual chrome of the inline editor toolbar and the editable-element affordances. These colours are emitted as CSS custom properties so you can override them per-theme.'),
    ];

    $form['colors']['color_toolbar_bg'] = [
      '#type' => 'color',
      '#title' => $this->t('Toolbar background'),
      '#description' => $this->t('Background colour of the floating toolbar.'),
      '#default_value' => $config->get('color_toolbar_bg') ?? '#1e1e2e',
    ];

    $form['colors']['color_toolbar_accent'] = [
      '#type' => 'color',
      '#title' => $this->t('Toolbar accent (Edit-mode toggle when active)'),
      '#description' => $this->t('Background of the "Edit Mode" button when toggled on, and of the toolbar badge.'),
      '#default_value' => $config->get('color_toolbar_accent') ?? '#ff8a3d',
    ];

    $form['colors']['color_edit_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Editing outline'),
      '#description' => $this->t('Outline colour drawn around an editable block while edit mode is on.'),
      '#default_value' => $config->get('color_edit_outline') ?? '#ff8a3d',
    ];

    $form['colors']['color_dirty_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Dirty outline'),
      '#description' => $this->t('Outline colour of a block that has unsaved changes.'),
      '#default_value' => $config->get('color_dirty_outline') ?? '#28a745',
    ];

    $form['colors']['color_focus_outline'] = [
      '#type' => 'color',
      '#title' => $this->t('Focused outline'),
      '#description' => $this->t('Outline colour of the block containing the element currently being edited.'),
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
