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
 *
 * Uses a simple file upload element (not managed_file) to avoid the
 * confusing Drupal managed_file widget with its own Upload/Remove buttons.
 * The form has exactly 3 elements: a file input, an alt-text field, and
 * a big "Use this image" button. No clutter.
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
      'current_fid' => $current_fid,
    ]);

    // Show current image preview if there is one.
    if ($current_fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($current_fid);
      if ($file) {
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        $form['current_preview'] = [
          '#markup' => '<div style="margin-bottom:16px;text-align:center;">'
            . '<div style="font-weight:bold;margin-bottom:8px;">Текущее изображение:</div>'
            . '<img src="' . htmlspecialchars($url) . '" alt="" style="max-width:200px;max-height:200px;border-radius:8px;border:2px solid #ddd;" />'
            . '</div>',
        ];
      }
    }

    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Новое изображение'),
      '#description' => $this->t('Выберите файл для замены. Форматы: GIF, PNG, JPG, JPEG, WebP, SVG.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['gif png jpg jpeg webp svg'],
      ],
      // No #required — user can just change alt text without replacing.
    ];

    $form['alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alt-текст'),
      '#default_value' => $current_alt,
      '#description' => $this->t('Описание изображения для скринридеров и поисковых систем.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Сохранить'),
      '#submit' => ['::submitAjax'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'code-block-field-image-picker',
      ],
      '#attributes' => [
        'class' => ['button--primary'],
        'style' => 'font-size:16px;padding:12px 32px;',
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
    $form['#attached']['library'][] = 'core/drupal.ajax';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op: handled by submitAjax().
  }

  /**
   * Ajax handler for the "Save" button.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    $response = new AjaxResponse();

    $asset_key = $storage['asset_key'] ?? '';
    $entity_type = $storage['entity_type'] ?? '';
    $entity_id = (int) ($storage['entity_id'] ?? 0);
    $current_fid = (int) ($storage['current_fid'] ?? 0);
    $alt = $form_state->getValue('alt') ?? '';

    // Check if a new file was uploaded.
    $validators = [
      'file_validate_extensions' => ['gif png jpg jpeg webp svg'],
    ];
    $upload_location = $this->config('code_block_field.settings')->get('upload_location') ?: 'public://code-block-field';

    $file = file_save_upload('file', $validators, $upload_location, 0, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);

    if ($file && is_object($file)) {
      // New file uploaded — use it.
      $file->setPermanent();
      $file->save();
      if ($entity_id) {
        \Drupal::service('file.usage')->add($file, 'code_block_field', $entity_type, $entity_id);
      }
      $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      $fid = (int) $file->id();
    }
    elseif ($current_fid) {
      // No new file — keep the current one, just update alt.
      $existing_file = \Drupal::entityTypeManager()->getStorage('file')->load($current_fid);
      if ($existing_file) {
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($existing_file->getFileUri());
        $fid = $current_fid;
      }
      else {
        return $response;
      }
    }
    else {
      // No file at all.
      return $response;
    }

    $payload = [
      'asset_key' => $asset_key,
      'fid' => $fid,
      'url' => $url,
      'alt' => $alt,
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
