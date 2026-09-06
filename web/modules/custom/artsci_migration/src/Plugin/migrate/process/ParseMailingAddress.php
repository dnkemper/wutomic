<?php

declare(strict_types=1);

namespace Drupal\artsci_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Parses a multi-line mailing address string into Drupal address values.
 *
 * Splits the input on <br/> tags (D10 stores addresses as plain long text
 * with HTML break separators), then:
 *   - Treats the LAST line as "City, ST ZIP[-####]" via regex; on a match,
 *     populates locality / administrative_area / postal_code.
 *   - On no match, puts the line back into the body so it ends up in
 *     address_line1/2.
 *   - First remaining line -> address_line1.
 *   - Any further lines -> address_line2 (joined with ", ").
 *
 * Adapted from the existing artsci hand-rolled importer code; behavior is
 * intentionally identical so addresses parse the same as before.
 *
 * @MigrateProcessPlugin(
 *   id = "parse_mailing_address"
 * )
 *
 * Configuration:
 *   - country_code: Two-letter country code applied to every parsed address.
 *     Defaults to "US".
 *
 * Source:
 *   - A plain address string (single source), OR
 *   - a positional list [address_string, organization] where the second
 *     element is mapped onto the address `organization` subproperty. This
 *     lets a caller fold a separate D10 field into the same address field
 *     without conflicting with the parsed address string. Single-string
 *     callers are unaffected.
 *
 * Returns NULL when there is nothing to store so the field stays unset.
 */
class ParseMailingAddress extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Accept either a plain address string or a positional list
    // [address_string, organization].
    if (is_array($value)) {
      $raw = trim((string) ($value[0] ?? ''));
      $organization = trim((string) ($value[1] ?? ''));
    }
    else {
      $raw = trim((string) ($value ?? ''));
      $organization = '';
    }

    // Nothing to store at all -> leave the field unset.
    if ($raw === '' && $organization === '') {
      return NULL;
    }

    $address = [
      'country_code' => (string) ($this->configuration['country_code'] ?? 'US'),
    ];

    if ($organization !== '') {
      $address['organization'] = $organization;
    }

    if ($raw !== '') {
      $address_lines = array_values(array_filter(
        array_map('trim', preg_split('/\s*(<br\s*\/?>|[\r\n]+)\s*/i', $raw))
      ));

      if (!empty($address_lines)) {
        // Last line should be "City, ST ZIP" or "City, ST ZIP-####".
        $last = array_pop($address_lines);
        if (preg_match('/^(.+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/', (string) $last, $m)) {
          $address['locality'] = trim($m[1]);
          $address['administrative_area'] = $m[2];
          $address['postal_code'] = $m[3];
        }
        else {
          // Couldn't parse the last line as City/ST/ZIP — put it back so it
          // ends up in address_line1/2 rather than being silently dropped.
          $address_lines[] = $last;
        }

        if (count($address_lines) >= 1) {
          $address['address_line1'] = (string) array_shift($address_lines);
        }
        if (count($address_lines) >= 1) {
          $address['address_line2'] = implode(', ', $address_lines);
        }
      }
    }

    // If nothing but the default country_code ended up set (e.g. the address
    // string was only break tags and there's no organization), treat it as
    // empty — preserves the original single-string behavior.
    if (array_keys($address) === ['country_code']) {
      return NULL;
    }

    return $address;
  }

}
