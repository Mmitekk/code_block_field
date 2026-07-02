<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Controller;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the inline editor’s AJAX endpoints.
 *
 *  - POST /admin/code-block-field/inline-save
 *      Accepts a JSON payload describing the modified HTML and saves it back
 *      to the entity’s code_block field item.
 *  - POST /admin/code-block-field/image-upload
 *      Accepts an uploaded file, validates it as an image and returns a JSON
 *      description (fid, url, key) of the new managed asset.
 *  - GET  /admin/code-block-field/image-picker/{…}
 *      Opens a Drupal modal with a managed-file form for replacing a single
 *      image inside the inline editor.
 *  - GET  /admin/code-block-field/link-picker/{…}
 *      Opens a Drupal modal with a small form to edit an <a> element.
 */
class InlineEditController extends ControllerBase {

  /**
   * The file usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The image style storage (lazy-loaded).
   */
  protected $imageStyleStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->fileUsage = $container->get('file.usage');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->csrfToken = $container->get('csrf_token');
    $instance->imageStyleStorage = $container->get('entity_type.manager')->getStorage('image_style');
    return $instance;
  }

  /**
   * Access check for the inline-save endpoint.
   */
  public function accessSave(Request $request): AccessResult {
    $account = $this->currentUser();
    if (!$account->hasPermission('use code block field inline editor')) {
      return AccessResult::forbidden('Missing inline editor permission.')->cachePerPermissions();
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return AccessResult::forbidden('Invalid payload.')->cachePerPermissions();
    }
    $entity_type = $payload['entity_type'] ?? '';
    $entity_id = $payload['entity_id'] ?? 0;
    $field_name = $payload['field_name'] ?? '';
    if (!$entity_type || !$entity_id || !$field_name) {
      return AccessResult::forbidden('Missing entity reference.')->cachePerPermissions();
    }
    try {
      $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    } catch (\Throwable) {
      $entity = NULL;
    }
    if (!$entity instanceof EntityInterface) {
      return AccessResult::forbidden('Entity not found.')->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, 'use code block field inline editor')
      ->andIf(AccessResult::allowedIf($entity->access('update', $account)))
      ->cachePerPermissions()
      ->addCacheableDependency($entity);
  }

  /**
   * Access check for the image-upload endpoint.
   */
  public function accessUpload(Request $request): AccessResult {
    $account = $this->currentUser();
    if (!$account->hasPermission('use code block field inline editor')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (is_array($payload)) {
      $entity_type = $payload['entity_type'] ?? '';
      $entity_id = $payload['entity_id'] ?? 0;
      if ($entity_type && $entity_id) {
        try {
          $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
        } catch (\Throwable) {
          $entity = NULL;
        }
        if ($entity instanceof EntityInterface) {
          return AccessResult::allowedIfHasPermission($account, 'use code block field inline editor')
            ->andIf(AccessResult::allowedIf($entity->access('update', $account)))
            ->cachePerPermissions()
            ->addCacheableDependency($entity);
        }
      }
    }
    return AccessResult::allowedIfHasPermission($account, 'use code block field inline editor')->cachePerPermissions();
  }

  /**
   * Access check for the picker (modal) routes.
   */
  public function accessPicker($entity_type, $entity_id, AccountInterface $account): AccessResult {
    if (!$account->hasPermission('use code block field inline editor')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $entity = $entity_type && $entity_id
      ? $this->entityTypeManager()->getStorage($entity_type)->load($entity_id)
      : NULL;
    if (!$entity instanceof EntityInterface) {
      return AccessResult::forbidden('Entity not found.')->cachePerPermissions();
    }
    return AccessResult::allowedIf($entity->access('update', $account))
      ->cachePerPermissions()
      ->addCacheableDependency($entity);
  }

  /**
   * Saves the modified HTML of a code_block field item back to the entity.
   *
   * Payload:
   * {
   *   "entity_type": "node",
   *   "entity_id": 12,
   *   "field_name": "field_code_block",
   *   "delta": 0,
   *   "langcode": "ru",
   *   "html": "<…>",
   *   "assets": {
   *     "img-1": { "fid": 7, "alt": "…" }
   *   },
   *   "css": "...",   // optional, replaces the CSS sub-field if provided
   *   "js": "..."     // optional, replaces the JS sub-field if provided
   * }
   */
  public function save(Request $request): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON payload.'], 400);
    }
    $required = ['entity_type', 'entity_id', 'field_name', 'delta'];
    foreach ($required as $key) {
      if (!array_key_exists($key, $payload)) {
        return new JsonResponse(['error' => sprintf('Missing %s in payload.', $key)], 400);
      }
    }

    try {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entityTypeManager()->getStorage($payload['entity_type'])->load($payload['entity_id']);
    } catch (\Throwable $e) {
      return new JsonResponse(['error' => 'Entity not found: ' . $e->getMessage()], 404);
    }
    if (!$entity) {
      return new JsonResponse(['error' => 'Entity not found.'], 404);
    }
    if (!$entity->hasField($payload['field_name'])) {
      return new JsonResponse(['error' => 'Field does not exist on entity.'], 400);
    }
    $field = $entity->get($payload['field_name']);
    $delta = (int) $payload['delta'];
    if (!isset($field[$delta])) {
      return new JsonResponse(['error' => 'Delta out of range.'], 400);
    }
    if ($field->getFieldDefinition()->getType() !== 'code_block') {
      return new JsonResponse(['error' => 'Field is not a code_block field.'], 400);
    }

    $html = (string) ($payload['html'] ?? $field[$delta]->html);

    // IMPORTANT: As of 1.3.0, we do NOT filter the HTML or auto-assign
    // data-cbf-asset attributes here. Both operations were causing HTML
    // to be mangled (SVG attributes lowercased, tags self-closed, etc.)
    // on some sites. The field now stores whatever HTML the editor sent,
    // verbatim. File usage for known assets is still tracked via
    // hook_entity_presave().
    $filtered_html = $html;

    $field[$delta]->html = $filtered_html;
    if (array_key_exists('css', $payload)) {
      $field[$delta]->css = (string) $payload['css'];
    }
    if (array_key_exists('js', $payload)) {
      $field[$delta]->js = (string) $payload['js'];
    }

    // Reconcile assets: parse the new HTML for <img data-cbf-asset="key">
    // entries and rebuild the assets map from the payload-provided list.
    $assets = is_array($payload['assets'] ?? NULL) ? $payload['assets'] : [];
    $reconciled = $this->reconcileAssets($filtered_html, $assets, $entity);
    $field[$delta]->assets = $reconciled;

    // Sync file usage.
    $this->syncFileUsage($reconciled, $entity, (array) ($field[$delta]->assets ?? []));

    // Optionally create a new revision (configurable in the module settings).
    // Only applies to entity types that actually support revisions.
    $config = $this->config('code_block_field.settings');
    $create_revision = (bool) $config->get('create_revisions');
    if ($create_revision && $entity instanceof \Drupal\Core\Entity\RevisionableInterface) {
      $log_template = $config->get('revision_log_message') ?: 'Inline edit (%date)';
      $log = str_replace('%date', date('Y-m-d H:i:s'), $log_template);
      $entity_type = $entity->getEntityType();
      if ($entity_type->isRevisionable()) {
        // Set the revision log field if the entity has one.
        $revision_log_field = $entity_type->getRevisionMetadataKey('revision_log') ?? 'revision_log';
        if ($entity->hasField($revision_log_field)) {
          $entity->set($revision_log_field, $log);
        }
        elseif ($entity->hasField('revision_log')) {
          $entity->set('revision_log', $log);
        }
        // Mark this as a new revision (default = TRUE for revisionable
        // entities, but enforce it explicitly so revisions stay enabled
        // even if the host entity has them turned off by default).
        if (method_exists($entity, 'setNewRevision')) {
          $entity->setNewRevision(TRUE);
        }
        // Use the current user as the revision author.
        if (method_exists($entity, 'setRevisionUserId')) {
          $entity->setRevisionUserId($this->currentUser()->id());
        }
        elseif ($entity->hasField('revision_uid')) {
          $entity->set('revision_uid', $this->currentUser()->id());
        }
      }
    }

    try {
      // For Paragraph entities (and any entity with a getParentEntity()
      // method), we MUST save through the parent — NOT directly on the
      // paragraph. Here's why:
      //
      // The Paragraphs module stores paragraph references on the parent
      // entity as (target_id, target_revision_id). When you call
      // $paragraph->save() directly, Paragraphs creates a NEW revision
      // of the paragraph, but the parent's reference still points to the
      // OLD revision. On the next page render, Drupal loads the OLD
      // revision (the one the parent references) — so the inline edit
      // is invisible even though the paragraph was technically saved.
      //
      // The correct flow is:
      //   1. Modify the paragraph's fields (already done above).
      //   2. Mark the paragraph as needing save via setNeedsSave(TRUE).
      //   3. Save the PARENT — Paragraphs module's hook_entity_presave
      //      will detect the needsSave flag, save the paragraph with a
      //      new revision, AND update the parent's target_revision_id
      //      to point to the new revision.
      //
      // For non-paragraph entities (nodes, block_content, taxonomy_term
      // with the code_block field directly), we save the entity directly.
      $saved_through_parent = FALSE;
      if (method_exists($entity, 'getParentEntity') && method_exists($entity, 'setNeedsSave')) {
        try {
          $parent = $entity->getParentEntity();
          if ($parent) {
            // Mark the paragraph as needing save so Paragraphs module
            // will save it (with a new revision if applicable) and
            // update the parent's reference when we save the parent.
            $entity->setNeedsSave(TRUE);
            $parent->save();
            $saved_through_parent = TRUE;

            \Drupal::logger('code_block_field')->debug('Inline save: saved paragraph @id through parent @ptype/@pid (new revision @rid)', [
              '@id' => $entity->id(),
              '@ptype' => $parent->getEntityTypeId(),
              '@pid' => $parent->id(),
              '@rid' => method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : '?',
            ]);
          }
        } catch (\Throwable $parent_e) {
          // Parent save failed — fall back to direct save.
          \Drupal::logger('code_block_field')->warning('Inline save: parent save failed (@msg), falling back to direct paragraph save', [
            '@msg' => $parent_e->getMessage(),
          ]);
        }
      }

      if (!$saved_through_parent) {
        // Direct save for non-paragraph entities, or as a fallback when
        // parent save failed.
        $entity->save();
      }
    } catch (\Throwable $e) {
      return new JsonResponse(['error' => 'Save failed: ' . $e->getMessage()], 500);
    }

    // Log the successful save for debugging.
    \Drupal::logger('code_block_field')->debug('Inline save: entity_type=@type, entity_id=@id, revision_id=@rid, field=@field, delta=@delta, html_length=@len, html_preview=@preview, saved_through_parent=@stp', [
      '@type' => $entity->getEntityTypeId(),
      '@id' => $entity->id(),
      '@rid' => method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : '?',
      '@field' => $payload['field_name'],
      '@delta' => $delta,
      '@len' => strlen($filtered_html),
      '@preview' => substr($filtered_html, 0, 200),
      '@stp' => $saved_through_parent ? 'yes' : 'no',
    ]);

    $response_data = [
      'success' => TRUE,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'revision_id' => method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : NULL,
      'field_name' => $payload['field_name'],
      'delta' => $delta,
      'html' => $filtered_html,
      'html_length' => strlen($filtered_html),
      'html_preview' => substr($filtered_html, 0, 200),
      'assets' => $reconciled,
      'saved_through_parent' => $saved_through_parent,
    ];
    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($entity);
    return $response;
  }

  /**
   * Handles image uploads coming from the inline editor.
   *
   * Accepts multipart form data with `file` (the upload) and the optional
   * fields `entity_type`, `entity_id`, `alt`, `key`.
   */
  public function uploadImage(Request $request): JsonResponse {
    $file = $request->files->get('file');
    if (!$file) {
      return new JsonResponse(['error' => 'No file uploaded.'], 400);
    }

    $config = $this->config('code_block_field.settings');
    $validators = [
      'file_validate_extensions' => ['gif png jpg jpeg webp svg'],
      'file_validate_isImage' => [],
    ];
    $max = $config->get('max_filesize');
    if ($max) {
      $validators['file_validate_size'] = [Bytes::toNumber($max)];
    }
    $destination = $config->get('upload_location') ?: 'public://code-block-field';
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $fs->prepareDirectory($destination, $fs::CREATE_DIRECTORY | $fs::MODIFY_PERMISSIONS);

    /** @var \Drupal\file\FileRepositoryInterface $file_repository */
    $file_repository = \Drupal::service('file.repository');
    try {
      $uploaded = $file_repository->writeData(
        file_get_contents($file->getPathname()),
        $destination . '/' . $file->getClientOriginalName(),
        \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
      );
    } catch (\Throwable $e) {
      return new JsonResponse(['error' => 'Upload failed: ' . $e->getMessage()], 500);
    }
    if (!$uploaded) {
      return new JsonResponse(['error' => 'Could not save uploaded file.'], 500);
    }

    $errors = \Drupal::service('file.validator')->validate($uploaded, $validators);
    if ($errors) {
      $uploaded->delete();
      return new JsonResponse(['error' => implode(' ', $errors)], 400);
    }
    $uploaded->setPermanent();
    $uploaded->save();

    $key = $request->request->get('key') ?: 'asset-' . $uploaded->id();
    $alt = $request->request->get('alt', '');

    $entity_type = $request->request->get('entity_type');
    $entity_id = (int) $request->request->get('entity_id', 0);
    if ($entity_type && $entity_id) {
      $this->fileUsage->add($uploaded, 'code_block_field', $entity_type, $entity_id);
    }

    return new JsonResponse([
      'success' => TRUE,
      'fid' => $uploaded->id(),
      'key' => $key,
      'alt' => $alt,
      'url' => $this->fileUrlGenerator->generateAbsoluteString($uploaded->getFileUri()),
      'filename' => $uploaded->getFilename(),
    ]);
  }

  /**
   * Modal picker for replacing an <img> inside the inline editor.
   */
  public function imagePicker($entity_type, $entity_id, $field_name, $delta, $asset_key): array {
    $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    $current_fid = 0;
    $current_alt = '';
    if ($entity && $entity->hasField($field_name)) {
      $assets = $entity->get($field_name)[$delta]->assets ?: [];
      if (isset($assets[$asset_key])) {
        $current_fid = $assets[$asset_key]['fid'] ?? 0;
        $current_alt = $assets[$asset_key]['alt'] ?? '';
      }
    }
    $form = $this->formBuilder()->getForm(
      '\Drupal\code_block_field\Form\InlineImagePickerForm',
      $entity_type, (int) $entity_id, $field_name, (int) $delta, $asset_key, (int) $current_fid, $current_alt
    );
    $build['form'] = $form;
    $build['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $build['#title'] = $this->t('Заменить изображение');
    return $build;
  }

  /**
   * Modal picker for editing an <a> element inside the inline editor.
   */
  public function linkPicker($entity_type, $entity_id, $field_name, $delta, $link_key): array {
    $form = $this->formBuilder()->getForm(
      '\Drupal\code_block_field\Form\InlineLinkPickerForm',
      $entity_type, (int) $entity_id, $field_name, (int) $delta, $link_key
    );
    $build['form'] = $form;
    $build['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $build['#title'] = $this->t('Редактировать ссылку');
    return $build;
  }

  /**
   * Filters an HTML string using the global allowed_html setting.
   *
   * Delegates to the centralised code_block_field_filter_html() helper so
   * that the filtering logic is identical on both save paths (entity form
   * and inline editor).
   */
  protected function filterHtml(string $html): string {
    $config = $this->config('code_block_field.settings');
    if (!$config->get('filter_html')) {
      return $html;
    }
    $allowed = $config->get('allowed_html');
    if (!$allowed) {
      return $html;
    }
    return code_block_field_filter_html($html, $allowed);
  }

  /**
   * Reconciles the assets map with the HTML content.
   *
   * Drops entries whose data-cbf-asset key is no longer present in the HTML,
   * keeps alt text up to date, and resolves fid references.
   *
   * IMPORTANT: uses a regex instead of DOMDocument. DOMDocument lowercases
   * attribute names and self-closes empty tags, which would mangle SVG
   * icons stored in the same HTML payload. The regex only reads attribute
   * values from <img> tags; it does not modify the HTML.
   */
  protected function reconcileAssets(string $html, array $payload_assets, EntityInterface $entity): array {
    $used_keys = [];

    // Find every <img ... data-cbf-asset="KEY" ...> in the HTML.
    // We use a regex to extract the key, alt and src attributes without
    // round-tripping the HTML through DOMDocument.
    if (preg_match_all('/<img\b[^>]*\bdata-cbf-asset\s*=\s*"([^"]+)"[^>]*>/i', $html, $img_matches, PREG_SET_ORDER)) {
      foreach ($img_matches as $img_tag) {
        $full_tag = $img_tag[0];
        $key = $img_tag[1];
        $used_keys[$key] = TRUE;

        // Extract alt and src from the tag.
        $alt = '';
        $src = '';
        if (preg_match('/\balt\s*=\s*"([^"]*)"/i', $full_tag, $m)) {
          $alt = $m[1];
        }
        if (preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $full_tag, $m)) {
          $src = $m[1];
        }

        $payload_assets[$key] = $payload_assets[$key] ?? [];
        if ($alt !== '') {
          $payload_assets[$key]['alt'] = $alt;
        }
        if ($src) {
          $payload_assets[$key]['src'] = $src;
        }
      }
    }

    $reconciled = [];
    foreach ($payload_assets as $key => $asset) {
      if (!isset($used_keys[$key])) {
        // Asset was removed from the HTML — release its file usage.
        $fid = $asset['fid'] ?? 0;
        if ($fid) {
          $file = $this->entityTypeManager()->getStorage('file')->load($fid);
          if ($file) {
            $this->fileUsage->delete($file, 'code_block_field', $entity->getEntityTypeId(), (int) $entity->id(), 0);
          }
        }
        continue;
      }
      if (empty($asset['fid'])) {
        continue;
      }
      $reconciled[$key] = [
        'fid' => (int) $asset['fid'],
        'alt' => $asset['alt'] ?? '',
        'src' => $asset['src'] ?? '',
      ];
    }
    return $reconciled;
  }

  /**
   * Synchronises file usage between the previous and the new assets map.
   *
   * @param array $new_assets
   *   The new assets map (after save).
   * @param array $old_assets
   *   The previous assets map (before save).
   */
  protected function syncFileUsage(array $new_assets, EntityInterface $entity, array $old_assets): void {
    $new_fids = array_column($new_assets, 'fid');
    $old_fids = array_column($old_assets, 'fid');
    $removed = array_diff($old_fids, $new_fids);
    $added = array_diff($new_fids, $old_fids);

    foreach ($removed as $fid) {
      if (!$fid) {
        continue;
      }
      $file = $this->entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        $this->fileUsage->delete($file, 'code_block_field', $entity->getEntityTypeId(), (int) $entity->id(), 0);
      }
    }
    foreach ($added as $fid) {
      if (!$fid) {
        continue;
      }
      $file = $this->entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        $this->fileUsage->add($file, 'code_block_field', $entity->getEntityTypeId(), (int) $entity->id());
      }
    }
  }

}
