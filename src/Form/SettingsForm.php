<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global configuration form for the Code Block Field module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['code_block_field.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'code_block_field_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('code_block_field.settings');

    $form['filter_html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filter HTML on save'),
      '#description' => $this->t('When enabled, the submitted HTML is run through the Filter module’s "filter_html" plugin using the list below. Recommended for sites with multiple editors.'),
      '#default_value' => (bool) $config->get('filter_html'),
    ];

    $form['allowed_html'] = [
      '#type' => 'textarea',
      '#rows' => 6,
      '#title' => $this->t('Allowed HTML tags'),
      '#description' => $this->t('Same format as the Filter module HTML filter. Example: <code>&lt;a href target rel class&gt; &lt;img src alt title width height class data-*&gt;</code>. Use <code>data-*</code> to allow any <code>data-*</code> attribute.'),
      '#default_value' => $config->get('allowed_html'),
    ];

    $form['upload_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Upload destination'),
      '#description' => $this->t('Stream wrapper path where inline-uploaded images are stored. Example: <code>public://code-block-field</code>.'),
      '#default_value' => $config->get('upload_location'),
      '#required' => TRUE,
    ];

    $form['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum upload size'),
      '#description' => $this->t('Examples: <code>2 MB</code>, <code>1024 KB</code>, <code>1G</code>. Leave empty for the site default.'),
      '#default_value' => $config->get('max_filesize'),
    ];

    $form['default_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image style'),
      '#empty_option' => $this->t('- Original -'),
      '#options' => array_map(fn($s) => $s->label(), \Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple()),
      '#default_value' => $config->get('default_image_style'),
      '#description' => $this->t('Used by the formatter when the field display does not override it.'),
    ];

    $form['shadow_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Shadow DOM mode'),
      '#options' => [
        'open' => $this->t('Open'),
        'closed' => $this->t('Closed'),
      ],
      '#default_value' => $config->get('shadow_mode'),
    ];

    $form['expose_edit_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show floating "Edit Mode" button'),
      '#description' => $this->t('When disabled, integrators must trigger the editor manually via <code>Drupal.codeBlockField.activate()</code>.'),
      '#default_value' => (bool) $config->get('expose_edit_button'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('code_block_field.settings')
      ->set('filter_html', (bool) $form_state->getValue('filter_html'))
      ->set('allowed_html', $form_state->getValue('allowed_html'))
      ->set('upload_location', $form_state->getValue('upload_location'))
      ->set('max_filesize', $form_state->getValue('max_filesize'))
      ->set('default_image_style', $form_state->getValue('default_image_style'))
      ->set('shadow_mode', $form_state->getValue('shadow_mode'))
      ->set('expose_edit_button', (bool) $form_state->getValue('expose_edit_button'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
