<?php

declare(strict_types=1);

namespace Drupal\artsci_migration;

/**
 * Static helpers for building Drupal link field values in migrations.
 *
 * Used via the migrate `callback` process plugin in YAML configs where a
 * full custom plugin would be overkill.
 */
final class LinkHelpers {

  /**
   * Wraps a uri + title into a single-delta link field value.
   *
   * Returns an array shape compatible with `merge` (i.e. array-of-arrays),
   * even when there's only one link, so the result can be combined with
   * other link-producing plugins via the migrate_plus merge plugin.
   *
   * @param string|null $uri
   *   The link URI, or NULL/empty to produce no link.
   * @param string|null $title
   *   The link text. NULL becomes an empty string.
   *
   * @return array
   *   Either [] (when uri is empty) or [['uri' => ..., 'title' => ...]].
   */
  public static function singleLink($uri, $title = ''): array {
    if (empty($uri)) {
      return [];
    }
    return [
      [
        'uri' => (string) $uri,
        'title' => (string) ($title ?? ''),
      ],
    ];
  }

  /**
   * Wraps a single media id (or null) into a merge friendly array.
   *
   * Mirrors singleLink() for media entity reference deltas: returns either
   * [] or [['target_id' => N]]. Use this when combining a single media
   * lookup with a multi value plugin output via the migrate_plus merge
   * plugin, where every source needs to be an array of arrays.
   *
   * @param int|string|null $mid
   *   Media id, or NULL/empty when there's no media to attach.
   *
   * @return array
   *   Either [] or [['target_id' => N]].
   */
  public static function singleMediaTarget($mid): array {
    if (empty($mid)) {
      return [];
    }
    return [
      ['target_id' => (int) $mid],
    ];
  }

  /**
   * Strips zero-width and non-breaking invisible characters from a string.
   *
   * WYSIWYG editors (CKEditor in particular) sometimes inject U+200B
   * ZeroWidthSpace as a cursor placeholder, which ends up saved to the
   * database as &ZeroWidthSpace; — technically non-empty content that
   * passes skip_on_empty but renders as an invisible summary. Same deal
   * for U+00A0 non-breaking spaces from pasted Word/Google Docs content.
   *
   * Catches:
   *   U+200B ZERO WIDTH SPACE
   *   U+200C ZERO WIDTH NON-JOINER
   *   U+200D ZERO WIDTH JOINER
   *   U+FEFF ZERO WIDTH NO-BREAK SPACE / BOM
   *   U+00A0 NON-BREAKING SPACE
   *
   * @param string|null $value
   *   Raw source string.
   *
   * @return string
   *   The cleaned string (may be empty — chain a skip_on_empty after).
   */
  public static function stripInvisibleChars($value) {
    if ($value === NULL) {
      return '';
    }
    return preg_replace(
      '/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u',
      '',
      (string) $value
    );
  }

  /**
   * Converts a delimited string into a <ul><li>...</li></ul> HTML fragment.
   *
   * Splits on any mix of CRLF / LF / CR / <br> variants, trims each item,
   * drops empties, and wraps the result in <ul><li>. Returns NULL when the
   * input has no usable items — upstream skip_on_empty catches that and
   * leaves the destination field untouched.
   *
   * @param string|null $value
   *   Raw source string.
   *
   * @return string|null
   *   HTML list fragment, or NULL if nothing to render.
   */
  public static function htmlListFromDelimited($value) {
    if ($value === NULL || trim((string) $value) === '') {
      return NULL;
    }

    $items = array_filter(array_map('trim', preg_split(
      '/\s*(?:<br\s*\/?>|\r\n|\n|\r)\s*/i',
      (string) $value
    )));

    if (empty($items)) {
      return NULL;
    }

    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
  }

  /**
   * Null-safe count for migration callbacks.
   *
   * In PHP 8 `count(null)` is a fatal TypeError, so process pipelines that
   * want to branch on "is this array populated?" can't pipe a relationship
   * payload straight into `count`. This wrapper returns 0 for anything
   * that isn't an array, including null and scalars.
   *
   * Typical use is in front of a static_map step that maps 0 to an empty
   * string and falls back to a default when there's at least one item.
   * That pattern explicitly overwrites the destination field on every
   * row, so subsequent --update runs can clear a value that earlier runs
   * (or editors) populated — unlike skip_on_empty, which only declines to
   * set the field and leaves any existing value in place.
   *
   * @param mixed $value
   *   Anything; we only care whether it's a non-empty array.
   *
   * @return int
   *   The element count, or 0 if $value isn't an array.
   */
  public static function countOrZero($value): int {
    return is_array($value) ? count($value) : 0;
  }

  /**
   * Returns the first valid email found in a string, or '' if none.
   *
   * D10 field_event_contact was a free-text string; the D11 destination is
   * an email field. Some source rows hold a bare address ("a@b.edu"),
   * others hold "Name | a@b.edu" or tokens like "[site:mail]". Migration
   * bypasses form validation, so an invalid string would otherwise persist
   * in the email field. We extract the first email-shaped token and
   * validate it; anything without a valid email yields '' so a downstream
   * skip_on_empty leaves the field unset rather than storing garbage.
   *
   * @param string|null $value
   *   Raw source string.
   *
   * @return string
   *   A valid email address, or '' when none is present.
   */
  public static function extractEmailOrEmpty($value): string {
    if ($value === NULL || trim((string) $value) === '') {
      return '';
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $value, $m)) {
      if (filter_var($m[0], FILTER_VALIDATE_EMAIL)) {
        return $m[0];
      }
    }
    return '';
  }

  /**
   * Extracts a SoundCloud URL from a D10 embed / text value.
   *
   * D10 stored SoundCloud players in a formatted-text field as a raw
   * <iframe> widget embed, e.g.:
   *   <iframe ... src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/316458048&amp;color=..."></iframe>
   *
   * The D11 soundcloud media bundle (media_entity_soundcloud) wants a plain
   * URL in field_media_soundcloud that SoundCloud's oEmbed endpoint accepts —
   * the api.soundcloud.com/tracks/{id} or canonical soundcloud.com/... form.
   * This pulls the player's url= query parameter out of the iframe src and
   * decodes it (parse_str url-decodes values), falling back to the first bare
   * soundcloud URL in the string. Returns '' when nothing usable is present so
   * a downstream skip_on_empty can drop the row rather than create an invalid
   * media (the source field is required).
   *
   * @param string|null $value
   *   Raw D10 field_soundcloud value (iframe HTML or a bare URL).
   *
   * @return string
   *   A SoundCloud URL, or '' when none can be extracted.
   */
  public static function soundcloudUrlFromEmbed($value): string {
    if ($value === NULL || trim((string) $value) === '') {
      return '';
    }
    // Decode HTML entities first (&amp; -> &) so the query string parses.
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);

    // Prefer the iframe src; the player URL carries the real track / playlist
    // URL in its url= parameter.
    $src = $text;
    if (preg_match('/src=["\']([^"\']+)["\']/i', $text, $m)) {
      $src = $m[1];
    }

    // Pull the url= parameter out of the player URL and decode it.
    $query = parse_url($src, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
      parse_str($query, $params);
      if (!empty($params['url'])) {
        return (string) $params['url'];
      }
    }

    // Fallback: a bare soundcloud URL sitting in the string.
    if (preg_match('#https?://(?:api\.|w\.|on\.)?soundcloud\.com/[^\s"\'<>]+#i', $src, $m2)) {
      return $m2[0];
    }

    return '';
  }

}
