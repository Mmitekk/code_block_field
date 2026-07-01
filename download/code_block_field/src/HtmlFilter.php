<?php

declare(strict_types=1);

namespace Drupal\code_block_field;

/**
 * Case-sensitive HTML filter for the Code Block Field module.
 *
 * The core filter_html plugin uses DOMDocument internally, which normalises
 * attribute names to lowercase. SVG presentation attributes like `viewBox`
 * are case-sensitive — after DOMDocument normalises them to `viewbox`, the
 * filter_html whitelist check (which is case-sensitive too) fails to match
 * the entry `<svg viewBox>` and silently strips the attribute.
 *
 * This class provides a regex-based alternative that:
 *  - Parses the allowed_html string (same format as the Filter module:
 *    `<tag attr1 attr2 attr-*> <other-tag>`).
 *  - Walks through the input HTML tag-by-tag with a regex.
 *  - Removes tags that are not in the whitelist.
 *  - Removes attributes that are not in the tag's whitelist, preserving
 *    the case of attribute names so SVG attributes survive.
 *  - Always allows the `data-cbf-asset` attribute (used internally by
 *    the inline editor) regardless of the whitelist.
 *
 * The regex approach is intentionally simpler than DOM-based parsing and
 * may be less robust for malformed HTML — but for our use case (HTML
 * that has been authored in the CodeMirror editor) it is reliable
 * enough, and it preserves the original case of every attribute.
 */
final class HtmlFilter {

  /**
   * Filters HTML according to the given allowed_html list.
   *
   * @param string $html
   *   The HTML to filter.
   * @param string $allowed_html
   *   The allowed_html list, same format as the Filter module's filter_html
   *   setting (e.g. `'<a href class> <img src alt data-*> <svg viewBox fill stroke>'`).
   *
   * @return string
   *   The filtered HTML, with disallowed tags and attributes removed but
   *   attribute-name case preserved.
   */
  public static function filter(string $html, string $allowed_html): string {
    if ($html === '' || $allowed_html === '') {
      return $html;
    }

    $allowed = self::parseAllowedHtml($allowed_html);
    if (empty($allowed)) {
      return $html;
    }

    // Walk through every tag in the HTML (both opening and self-closing).
    // The regex captures:
    //   - The full tag (group 0).
    //   - The leading slash for closing tags (group 1).
    //   - The tag name (group 2).
    //   - The rest of the tag — attributes and trailing slash (group 3).
    return preg_replace_callback(
      '/<((\/?)([a-zA-Z][a-zA-Z0-9]*))([^>]*)>/',
      function ($m) use ($allowed) {
        $is_closing = !empty($m[2]);
        $tag_name = strtolower($m[3]);
        $rest = $m[4];

        // Tag not in whitelist — strip the whole tag (but keep its text
        // content, which is outside this match).
        if (!isset($allowed[$tag_name])) {
          return '';
        }

        // Closing tags never need attribute filtering.
        if ($is_closing) {
          return '</' . $tag_name . '>';
        }

        // Parse the attribute string. We need to handle:
        //  - bare attributes (e.g. `disabled`)
        //  - double-quoted values (e.g. `class="foo"`)
        //  - single-quoted values (e.g. `class='foo'`)
        //  - unquoted values (e.g. `class=foo`)
        // We do a case-insensitive match on the attribute name but
        // preserve the original case in the output.
        $attr_pattern = '/
          \s+
          (?P<name>[a-zA-Z_:][a-zA-Z0-9_:.\-]*)  # attribute name
          (?:
            \s*=\s*
            (?:
              "(?P<dq>[^"]*)"      # double-quoted value
              | \'(?P<sq>[^\']*)\' # single-quoted value
              | (?P<uq>[^\s"\'>]+) # unquoted value
            )
          )?
        /x';

        $allowed_attrs = $allowed[$tag_name];
        $kept_attrs = [];

        if (preg_match_all($attr_pattern, $rest, $attr_matches, PREG_SET_ORDER)) {
          foreach ($attr_matches as $am) {
            $name_lower = strtolower($am['name']);
            $name_original = $am['name'];

            // Always allow data-cbf-asset (used internally).
            if ($name_lower === 'data-cbf-asset') {
              $kept_attrs[] = self::rebuildAttr($am, $name_original);
              continue;
            }

            // Allow global wildcards (e.g. `data-*`).
            $allowed_here = FALSE;
            if (isset($allowed_attrs[$name_lower]) && $allowed_attrs[$name_lower] !== FALSE) {
              $allowed_here = TRUE;
            }
            else {
              // Check wildcard prefixes.
              foreach ($allowed_attrs as $attr_name => $dummy) {
                if ($attr_name !== '' && substr($attr_name, -1) === '*' && strpos($name_lower, substr($attr_name, 0, -1)) === 0) {
                  $allowed_here = TRUE;
                  break;
                }
              }
            }

            if ($allowed_here) {
              $kept_attrs[] = self::rebuildAttr($am, $name_original);
            }
          }
        }

        // Detect self-closing.
        $self_closing = (preg_match('/\/\s*$/', $rest) === 1);

        $output = '<' . $tag_name;
        if (!empty($kept_attrs)) {
          $output .= ' ' . implode(' ', $kept_attrs);
        }
        $output .= $self_closing ? ' />' : '>';
        return $output;
      },
      $html
    );
  }

  /**
   * Rebuilds a single attribute string from a preg match.
   */
  protected static function rebuildAttr(array $m, string $name): string {
    if (isset($m['dq'])) {
      return $name . '="' . $m['dq'] . '"';
    }
    if (isset($m['sq'])) {
      return $name . '="' . $m['sq'] . '"';
    }
    if (isset($m['uq']) && $m['uq'] !== '') {
      return $name . '="' . $m['uq'] . '"';
    }
    return $name;
  }

  /**
   * Parses an allowed_html string into a structured array.
   *
   * Output format:
   *   [
   *     'svg' => ['viewbox' => TRUE, 'fill' => TRUE, 'stroke' => TRUE, ...],
   *     'img' => ['src' => TRUE, 'alt' => TRUE, 'data-*' => TRUE, ...],
   *     ...
   *   ]
   *
   * Attribute names are stored lowercased for case-insensitive lookup.
   *
   * @param string $allowed_html
   *   The allowed_html string.
   *
   * @return array
   *   The parsed whitelist.
   */
  protected static function parseAllowedHtml(string $allowed_html): array {
    $allowed = [];
    // Match each <tag attr1 attr2 ...> entry.
    if (!preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', $allowed_html, $tag_matches)) {
      return $allowed;
    }
    foreach ($tag_matches[1] as $idx => $tag_name) {
      $tag_name_lower = strtolower($tag_name);
      $attr_str = $tag_matches[2][$idx];
      $attrs = [];

      // Split the attribute string by whitespace. Each token is either
      // an attribute name, an attribute name with a wildcard suffix
      // (e.g. `data-*`), or empty.
      $tokens = preg_split('/\s+/', trim($attr_str));
      if (is_array($tokens)) {
        foreach ($tokens as $token) {
          if ($token === '') {
            continue;
          }
          // Attributes can be in the form `name` or `name="value"` or
          // `name=value` in the allowed_html string. We only care about
          // the name (with optional `*` suffix); values are not
          // restricted by our filter.
          if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:.\-*]*)/', $token, $m)) {
            $attrs[strtolower($m[1])] = TRUE;
          }
        }
      }
      $allowed[$tag_name_lower] = $attrs;
    }
    return $allowed;
  }

}
