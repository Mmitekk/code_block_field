<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Modal form for editing an <a> element inside the inline editor.
 */
class InlineLinkPickerForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'code_block_field_inline_link_picker';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $entity_type = '', int $entity_id = 0, string $field_name = '', int $delta = 0, string $link_key = ''): array {
    $form_state->setStorage([
      'link_key' => $link_key,
    ]);

    $form['href'] = [
      '#type' => 'url',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Absolute or relative URL (e.g. <code>https://example.com</code> or <code>/about</code>).'),
      '#required' => FALSE,
    ];
    $form['text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#description' => $this->t('Visible text of the link. Leave empty to keep current.'),
    ];
    $form['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open in new tab'),
    ];
    $form['rel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rel attribute'),
      '#description' => $this->t('Comma-separated, e.g. <code>noopener,nofollow</code>.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save link'),
      '#submit' => ['::submitAjax'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'code-block-field-link-picker',
      ],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#limit_validation_errors' => [],
      '#ajax' => ['callback' => '::cancelAjax'],
    ];

    $form['#prefix'] = '<div id="code-block-field-link-picker">';
    $form['#suffix'] = '</div>';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op.
  }

  /**
   * Ajax handler.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $storage = $form_state->getStorage();
    $response = new AjaxResponse();
    $payload = [
      'link_key' => $storage['link_key'] ?? '',
      'href' => $form_state->getValue('href'),
      'text' => $form_state->getValue('text'),
      'target' => $form_state->getValue('target') ? '_blank' : '',
      'rel' => $form_state->getValue('rel'),
    ];
    $response->addCommand(new InvokeCommand(NULL, 'trigger', ['codeBlockFieldLinkPicked', [$payload]]));
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * Standard AJAX callback.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Cancel.
   */
  public function cancelAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
