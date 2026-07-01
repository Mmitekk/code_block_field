<?php

declare(strict_types=1);

namespace Drupal\code_block_field;

use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;

/**
 * Processes HTML to auto-assign data-cbf-asset keys to <img> tags.
 *
 * For every <img> in the HTML that does not already have a data-cbf-asset
 * attribute, this class:
 *  1. Generates a unique asset key (e.g. "auto-asset-68a1f3-2").
 *  2. Adds the data-cbf-asset attribute to the <img>.
 *  3. If the src attribute points to a Drupal managed file (i.e. it can be
 *     resolved to a file URI by the file_url_generator), looks up the file
 *     entity and registers it in the assets map with its fid. This makes
 *     existing images immediately manageable through the inline editor
 *     without manual data-cbf-asset markup.
 *
 * IMPORTANT: this class uses a regex-based approach instead of DOMDocument.
 * DOMDocument normalises attribute names to lowercase (e.g. `viewBox` →
 * `viewbox`) and self-closes empty tags (e.g. `<polyline></polyline>` →
 * `<polyline />`), which breaks SVG icons that are stored in the same
 * HTML payload as <img> tags. The regex approach only patches the <img>
 * tags that need a data-cbf-asset attribute and leaves the rest of the
 * HTML completely untouched.
 *
 * The class is intentionally side-effect free apart from the assets map
 * (passed by reference) — it does not save anything to the database, does
 * not register file.usage (that is done by the caller via the entity hooks),
 * and does not modify the file entities themselves.
 */
final class ProcessImages {

  /**
   * Processes the HTML and the assets map in place.
   *
   * @param string $html
   *   The HTML content of the code_block field item.
   * @param array $assets
   *   The assets map (key => { fid, alt, src }). Modified in place.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The host entity (used only for context, not modified).
   *
   * @return string
   *   The processed HTML with data-cbf-asset added to every <img>.
   */
  public static function process(string $html, array &$assets, ?EntityInterface $entity = NULL): string {
    if ($html === '') {
      return $html;
    }

    // Per-request counter for unique key generation.
    static $counter = 0;

    // Regex-based scan of every <img ...> tag. We match the whole tag and
    // patch only the tags that do NOT have a data-cbf-asset attribute yet.
    // Everything else in the HTML (including <svg>, <polyline>, etc.) is
    // left completely untouched.
    return preg_replace_callback(
      '/<img\b([^>]*?)\s*\/?>/i',
      function ($m) use (&$assets, &$counter) {
        $full_tag = $m[0];
        $attrs_str = $m[1];

        // Check if data-cbf-asset is already present (case-insensitive).
        if (preg_match('/\bdata-cbf-asset\s*=/i', $attrs_str)) {
          // Extract the existing key and make sure it's in the assets map.
          if (preg_match('/\bdata-cbf-asset\s*=\s*"([^"]*)"/i', $attrs_str, $km)) {
            $key = $km[1];
            if (!isset($assets[$key])) {
              $assets[$key] = [
                'fid' => 0,
                'alt' => self::extractAttr($attrs_str, 'alt'),
                'src' => self::extractAttr($attrs_str, 'src'),
              ];
            }
          }
          return $full_tag;
        }

        // Generate a unique key.
        $counter++;
        $key = 'auto-asset-' . dechex(time() & 0xFFFFFF) . '-' . $counter;

        // Extract src and alt from the existing attributes.
        $src = self::extractAttr($attrs_str, 'src');
        $alt = self::extractAttr($attrs_str, 'alt');
        $fid = 0;
        if ($src) {
          $fid = self::resolveFileIdFromSrc($src);
        }
        $assets[$key] = [
          'fid' => $fid,
          'alt' => $alt,
          'src' => $src,
        ];

        // Add the data-cbf-asset attribute right after <img.
        return preg_replace(
          '/^<img\b/i',
          '<img data-cbf-asset="' . htmlspecialchars($key, ENT_QUOTES) . '"',
          $full_tag,
          1
        );
      },
      $html
    );
  }

  /**
   * Extracts an attribute value from an attribute string.
   *
   * @param string $attrs_str
   *   The attribute portion of an HTML tag (everything between <img and >).
   * @param string $name
   *   The attribute name to extract.
   *
   * @return string
   *   The attribute value, or empty string if not present.
   */
  protected static function extractAttr(string $attrs_str, string $name): string {
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $attrs_str, $m)) {
      return $m[1];
    }
    if (preg_match('/\b' . preg_quote($name, '/') . "\s*=\s*'([^']*)'/i", $attrs_str, $m)) {
      return $m[1];
    }
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*([^\s"\'<>]+)/i', $attrs_str, $m)) {
      return $m[1];
    }
    return '';
  }

  /**
   * Tries to resolve a <img src="…"> URL to a Drupal managed file ID.
   *
   * Handles:
   *  - Absolute URLs to the site's public:// files (e.g.
   *    https://example.com/sites/default/files/foo.jpg).
   *  - Root-relative URLs (e.g. /sites/default/files/foo.jpg).
   *  - Stream-wrapper-style URIs (e.g. public://foo.jpg) — unusual but
   *    valid if someone pastes them.
   *
   * Returns 0 if the src does not point to a known managed file.
   *
   * Note: we cannot use FileUrlGenerator::generateUriFromString() because
   * it was only introduced in Drupal 10.3. Instead, we do a manual
   * stream-wrapper-prefix lookup so the code works on 9.5+ / 10.x / 11.x.
   */
  protected static function resolveFileIdFromSrc(string $src): int {
    // Stream-wrapper style (rare).
    if (strpos($src, '://') !== FALSE) {
      $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $src]);
      $file = reset($files);
      if ($file instanceof FileInterface) {
        return (int) $file->id();
      }
      return 0;
    }

    // Strip query string and fragment.
    $src_clean = preg_replace('/[?#].*$/', '', $src);

    // Parse the URL to extract the path.
    $path = parse_url($src_clean, PHP_URL_PATH);
    if (!$path) {
      return 0;
    }

    // Convert the path to a stream-wrapper URI by matching against the
    // public:// and private:// wrapper base paths.
    /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $swm */
    $swm = \Drupal::service('stream_wrapper_manager');
    $uri = NULL;
    foreach ($swm->getWrappers() as $scheme => $wrapper_info) {
      /** @var \Drupal\Core\StreamWrapper\LocalStream $wrapper */
      $wrapper = $swm->getViaScheme($scheme);
      if (!method_exists($wrapper, 'getDirectoryPath')) {
        continue;
      }
      $dir = $wrapper->getDirectoryPath();
      if ($dir === '') {
        continue;
      }
      // Build the expected path prefix, e.g. "/sites/default/files".
      $prefix = '/' . trim($dir, '/');
      if (strpos($path, $prefix . '/') === 0) {
        $relative = substr($path, strlen($prefix) + 1);
        // urldecode because URLs are percent-encoded but file URIs are not.
        $relative = rawurldecode($relative);
        $uri = $scheme . '://' . $relative;
        break;
      }
    }

    if (!$uri) {
      return 0;
    }

    // For image-style derivatives, the URI will be something like
    // public://styles/large/public/foo.jpg — try to load the file by
    // that URI first, then fall back to looking up by the original URI
    // (strip the styles/.../public/ prefix).
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    if ($file instanceof FileInterface) {
      return (int) $file->id();
    }

    // Try to derive the original URI from an image-style path.
    // public://styles/{style}/public/{path}  ->  public://{path}
    if (preg_match('#^(public|private)://styles/[^/]+/(public|private)/(.+)$#i', $uri, $m)) {
      $original_uri = $m[1] . '://' . $m[3];
      $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $original_uri]);
      $file = reset($files);
      if ($file instanceof FileInterface) {
        return (int) $file->id();
      }
    }

    return 0;
  }

}
