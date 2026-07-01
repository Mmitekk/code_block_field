<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Modal form that lets the inline editor pick or upload a replacement image.
 */
class InlineImagePickerForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'code_block_field_inline_image_picker';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $entity_type = '', int $entity_id = 0, string $field_name = '', int $delta = 0, string $asset_key = '', int $current_fid = 0, string $current_alt = '') {
    $form_state->setStorage([
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'field_name' => $field_name,
      'delta' => $delta,
      'asset_key' => $asset_key,
    ]);

    $form['fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Изображение'),
      '#upload_validators' => [
        'file_validate_extensions' => ['gif png jpg jpeg webp svg'],
        'file_validate_isImage' => [],
      ],
      '#upload_location' => $this->config('code_block_field.settings')->get('upload_location') ?: 'public://code-block-field',
      '#default_value' => $current_fid ? [$current_fid] : NULL,
      '#required' => TRUE,
    ];

    $form['alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alt-текст'),
      '#default_value' => $current_alt,
      '#description' => $this->t('Используется скринридерами и поисковыми системами.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Использовать это изображение'),
      '#submit' => ['::submitAjax'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'code-block-field-image-picker',
      ],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Отмена'),
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::cancelAjax',
      ],
    ];

    $form['#prefix'] = '<div id="code-block-field-image-picker">';
    $form['#suffix'] = '</div>';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op: handled by submitAjax().
  }

  /**
   * Ajax handler for the "Use this image" button.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    $fids = $form_state->getValue('fid') ?: [];
    $fid = reset($fids);
    $response = new AjaxResponse();
    if (!$fid) {
      return $response;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    if (!$file) {
      return $response;
    }
    $file->setPermanent();
    $file->save();
    if ($entity_id = (int) ($storage['entity_id'] ?? 0)) {
      \Drupal::service('file.usage')->add($file, 'code_block_field', $storage['entity_type'], $entity_id);
    }
    $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
    $payload = [
      'asset_key' => $storage['asset_key'] ?? '',
      'fid' => (int) $fid,
      'url' => $url,
      'alt' => $form_state->getValue('alt'),
    ];
    $response->addCommand(new InvokeCommand(
      NULL,
      'trigger',
      ['codeBlockFieldImagePicked', [$payload]]
    ));
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * Standard AJAX callback — returns the rebuilt form.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Cancel button: just close the modal.
   */
  public function cancelAjax(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

}
