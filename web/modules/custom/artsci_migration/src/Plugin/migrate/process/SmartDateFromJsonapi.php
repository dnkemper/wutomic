<?php

declare(strict_types=1);

namespace Drupal\artsci_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Converts a JSON:API smartdate object into a Smart Date field value.
 *
 * The D10 source emits field_event_smart_date over JSON:API as a single
 * object, e.g.:
 *   {
 *     "value":       "2025-08-24T21:00:00+00:00",
 *     "end_value":   "2025-08-24T22:00:00+00:00",
 *     "duration":    null,        // sometimes an int (minutes), often null
 *     "rrule":       null,
 *     "rrule_index": null,
 *     "timezone":    null         // sometimes "" instead of null
 *   }
 *
 * Smart Date storage expects integer UNIX timestamps for value/end_value,
 * an integer duration in MINUTES, a string timezone, and (for recurring
 * events) integer rrule / rrule_index referencing a smart_date_rule
 * config/content entity. This plugin normalizes the source object into a
 * single-delta field value array:
 *
 *   [['value' => N, 'end_value' => N, 'duration' => N,
 *     'rrule' => 0, 'rrule_index' => NULL, 'timezone' => '']]
 *
 * Behavior / decisions (all deliberate, change here if needed):
 *   - value / end_value: accepts either an int timestamp or an ISO 8601
 *     string (strtotime). If end_value is missing it falls back to value
 *     (a zero-length instant) rather than failing.
 *   - duration: used verbatim when the source provides an int; otherwise
 *     COMPUTED as round((end - start) / 60). This is why a process
 *     callback won't do — duration needs both endpoints at once.
 *   - timezone: NULL becomes '' (empty = use site default on display).
 *   - rrule: NOT carried over. A D10 rrule is an integer id pointing at a
 *     smart_date_rule entity that this migration does NOT migrate, so
 *     copying the integer would create a dangling reference. Recurring
 *     source events are therefore flattened to their single stored
 *     value/end_value instance. If recurring events must round-trip,
 *     build a smart_date_rule migration first and resolve rrule via
 *     migration_lookup instead of dropping it here. No source row in the
 *     events set had an rrule at build time, so this path is untested.
 *   - Returns NULL when there is no usable start value. field_event_when
 *     is REQUIRED on D11, so such a row will fail to save — that is
 *     intentional: it surfaces date-less source rows rather than masking
 *     them.
 *
 * @MigrateProcessPlugin(
 *   id = "smart_date_from_jsonapi"
 * )
 */
class SmartDateFromJsonapi extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_array($value)) {
      return NULL;
    }

    // Accept either shape:
    //   - the associative JSON:API object
    //     {value, end_value, duration, rrule, rrule_index, timezone}, or
    //   - a positional list [start, end, duration, timezone].
    // The migration uses the positional form because the migrate_plus JSON
    // data parser only reliably returns scalar LEAF selectors (e.g.
    // attributes/field_event_smart_date/value), not a whole nested object,
    // so passing the object wholesale yielded a non-array here and produced
    // empty dates. Leaf selectors fed as a `source:` list arrive as [0..3].
    if (array_key_exists('value', $value)) {
      $raw_start = $value['value'] ?? NULL;
      $raw_end = $value['end_value'] ?? NULL;
      $raw_duration = $value['duration'] ?? NULL;
      $raw_tz = $value['timezone'] ?? '';
    }
    else {
      $raw_start = $value[0] ?? NULL;
      $raw_end = $value[1] ?? NULL;
      $raw_duration = $value[2] ?? NULL;
      $raw_tz = $value[3] ?? '';
    }

    $start = $this->toTimestamp($raw_start);
    if ($start === NULL) {
      // No start date -> nothing to store. Required field will reject.
      return NULL;
    }

    $end = $this->toTimestamp($raw_end);
    if ($end === NULL) {
      $end = $start;
    }

    // Duration in minutes: honor an explicit source int, else derive it.
    if (is_numeric($raw_duration)) {
      $duration = (int) $raw_duration;
    }
    else {
      $duration = (int) round(($end - $start) / 60);
    }

    $timezone = is_string($raw_tz) ? $raw_tz : '';

    return [
      [
        'value' => $start,
        'end_value' => $end,
        'duration' => $duration,
        // rrule deliberately dropped — see class docblock.
        'rrule' => 0,
        'rrule_index' => NULL,
        'timezone' => $timezone,
      ],
    ];
  }

  /**
   * Normalizes a source date (int timestamp or ISO 8601 string) to epoch.
   *
   * @param mixed $raw
   *   An int/numeric timestamp, an ISO 8601 datetime string, or NULL.
   *
   * @return int|null
   *   UNIX timestamp, or NULL when the input is empty/unparseable.
   */
  protected function toTimestamp($raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (is_numeric($raw)) {
      return (int) $raw;
    }
    $ts = strtotime((string) $raw);
    return $ts === FALSE ? NULL : $ts;
  }

}
