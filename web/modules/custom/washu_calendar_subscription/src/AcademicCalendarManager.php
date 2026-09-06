<?php

declare(strict_types=1);

namespace Drupal\washu_calendar_subscription;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides helpers for the academic calendar semester stepper and defaults.
 *
 * The academic_calendar_event content type stores the term as two list_string
 * fields: field_season (spring|summer|fall) and field_semester_year (a year
 * such as "2026"). This service treats the (season, year) pair as a single
 * "semester" and exposes:
 *   - the configured "current" semester (editor-set, see the settings form),
 *   - the ordered list of semesters that actually have published events,
 *   - adjacency (previous/next) for the front-end stepper.
 */
class AcademicCalendarManager {

  /**
   * Ordering of seasons within a single year.
   */
  protected const SEASON_ORDER = [
    'spring' => 0,
    'summer' => 1,
    'fall' => 2,
  ];

  /**
   * Human labels for the season machine values.
   */
  protected const SEASON_LABELS = [
    'spring' => 'Spring',
    'summer' => 'Summer',
    'fall' => 'Fall',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {}

  /**
   * Returns the editor-configured current semester.
   *
   * Falls back to a date-based computation if the setting has not been saved,
   * so the calendar is never empty on a fresh install.
   *
   * @return array
   *   An array with 'season' and 'year' (both strings).
   */
  public function getCurrentSemester(): array {
    $config = $this->configFactory->get('washu_calendar_subscription.settings');
    $season = $config->get('current_season');
    $year = $config->get('current_year');

    if (!empty($season) && !empty($year) && isset(self::SEASON_ORDER[$season])) {
      return ['season' => $season, 'year' => (string) $year];
    }

    return $this->computeSemesterFromDate();
  }

  /**
   * Computes a sensible current semester from today's date.
   *
   * Mirrors the windows used by the legacy current_season views argument:
   * spring runs Jan 1 to May 19, summer May 20 to Aug 17, fall otherwise.
   *
   * @return array
   *   An array with 'season' and 'year' (both strings).
   */
  public function computeSemesterFromDate(): array {
    $today = new \DateTime();
    $year = $today->format('Y');
    $summer = new \DateTime('May 20');
    $fall = new \DateTime('August 18');

    if ($today < $summer) {
      $season = 'spring';
    }
    elseif ($today < $fall) {
      $season = 'summer';
    }
    else {
      $season = 'fall';
    }

    return ['season' => $season, 'year' => (string) $year];
  }

  /**
   * Returns the distinct semesters that have published events, ordered.
   *
   * Ordered chronologically: by year ascending, then spring, summer, fall.
   *
   * @return array
   *   A 0-indexed list of arrays, each with 'season', 'year' and 'label'.
   */
  public function getSemesters(): array {
    $query = $this->database->select('node__field_season', 's');
    $query->innerJoin('node__field_semester_year', 'y', 'y.entity_id = s.entity_id AND y.deleted = s.deleted');
    $query->innerJoin('node_field_data', 'n', 'n.nid = s.entity_id');
    $query->fields('s', ['field_season_value']);
    $query->fields('y', ['field_semester_year_value']);
    $query->condition('n.status', 1);
    $query->condition('n.type', 'academic_calendar_event');
    $query->condition('s.deleted', 0);
    $query->distinct();

    $rows = $query->execute()->fetchAll();

    $semesters = [];
    foreach ($rows as $row) {
      $season = $row->field_season_value;
      $year = (string) $row->field_semester_year_value;
      if ($season === NULL || $year === '' || !isset(self::SEASON_ORDER[$season])) {
        continue;
      }
      // Sort key: year then season order, e.g. "2026-1".
      $sort_key = $year . '-' . self::SEASON_ORDER[$season];
      $semesters[$sort_key] = [
        'season' => $season,
        'year' => $year,
        'label' => $this->formatLabel($season, $year),
      ];
    }

    ksort($semesters, SORT_NATURAL);
    return array_values($semesters);
  }

  /**
   * Resolves the semester to display from request input.
   *
   * @param string|null $season
   *   The requested season machine value, or NULL.
   * @param string|null $year
   *   The requested year, or NULL.
   *
   * @return array
   *   The matching semester (with 'season', 'year', 'label'). When the request
   *   does not specify a valid semester, the configured current semester is
   *   returned.
   */
  public function getActiveSemester(?string $season, ?string $year): array {
    if (!empty($season) && !empty($year) && isset(self::SEASON_ORDER[$season])) {
      return [
        'season' => $season,
        'year' => (string) $year,
        'label' => $this->formatLabel($season, (string) $year),
      ];
    }

    $current = $this->getCurrentSemester();
    $current['label'] = $this->formatLabel($current['season'], $current['year']);
    return $current;
  }

  /**
   * Returns the previous and next semesters relative to the active one.
   *
   * Adjacency is based on the list of semesters that have events, so the
   * stepper never lands on an empty term. Returns NULL at the ends.
   *
   * @param string $season
   *   The active season.
   * @param string $year
   *   The active year.
   *
   * @return array
   *   An array with 'prev' and 'next' keys, each a semester array or NULL.
   */
  public function getAdjacent(string $season, string $year): array {
    $semesters = $this->getSemesters();
    $index = $this->indexOf($semesters, $season, $year);

    // If the active semester has no events it won't be in the list. Place it
    // by value so the stepper can still move toward the nearest real terms.
    if ($index === -1) {
      $index = $this->nearestIndex($semesters, $season, $year);
      return [
        'prev' => $semesters[$index] ?? NULL,
        'next' => $semesters[$index + 1] ?? NULL,
      ];
    }

    return [
      'prev' => $semesters[$index - 1] ?? NULL,
      'next' => $semesters[$index + 1] ?? NULL,
    ];
  }

  /**
   * Formats a season/year pair as a display label, e.g. "Summer 2026".
   */
  public function formatLabel(string $season, string $year): string {
    $label = self::SEASON_LABELS[$season] ?? ucfirst($season);
    return $label . ' ' . $year;
  }

  /**
   * Finds the index of a semester in an ordered list, or -1.
   */
  protected function indexOf(array $semesters, string $season, string $year): int {
    foreach ($semesters as $i => $semester) {
      if ($semester['season'] === $season && $semester['year'] === (string) $year) {
        return $i;
      }
    }
    return -1;
  }

  /**
   * Returns the index of the last semester at or before the given term.
   */
  protected function nearestIndex(array $semesters, string $season, string $year): int {
    $target = (int) $year * 10 + (self::SEASON_ORDER[$season] ?? 0);
    $result = -1;
    foreach ($semesters as $i => $semester) {
      $value = (int) $semester['year'] * 10 + self::SEASON_ORDER[$semester['season']];
      if ($value <= $target) {
        $result = $i;
      }
    }
    return $result;
  }

}
