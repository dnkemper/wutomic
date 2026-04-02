<?php

namespace Drupal\academic_calendar_ical\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the Academic Calendar iCal feed.
 */
class AcademicCalendarIcalController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new AcademicCalendarIcalController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Generates the iCal feed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The iCal response.
   */
  public function feed(Request $request): Response {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Query all published academic_calendar_event nodes, sorted by start date.
    $query = $node_storage->getQuery()
      ->condition('type', 'article')
      ->condition('status', 1)
      ->sort('created', 'ASC')
      ->accessCheck(TRUE);

    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);

    // Build the iCal output.
    $output = $this->buildIcal($nodes, $request);

    $response = new Response($output);
    $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
    $response->headers->set('Content-Disposition', 'inline; filename="news.ics"');
    // Allow caching for 1 hour, but allow revalidation.
    $response->headers->set('Cache-Control', 'public, max-age=3600');

    return $response;
  }

  /**
   * Builds the iCal string from an array of nodes.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   An array of academic_calendar_event nodes.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (used for generating UIDs).
   *
   * @return string
   *   The iCal formatted string.
   */
  protected function buildIcal(array $nodes, Request $request): string {
    $host = $request->getHost();
    $lines = [];

    // Calendar header.
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//Washington University in St. Louis//News//EN';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';
    $lines[] = 'X-WR-CALNAME:News';
    $lines[] = 'X-WR-TIMEZONE:America/Chicago';

    foreach ($nodes as $node) {
      // Get the start date value (stored as Y-m-d for date-only fields).
      $start_date_value = $node->get('created')->value;
      if (empty($start_date_value)) {
        continue;
      }

      // Format as iCal DATE (no time component) — e.g., 20260301
      // Per RFC 5545, a DTSTART with VALUE=DATE and no DTEND means a single
      // all-day event on that date.
      $date = new \DateTime('@' . $start_date_value);
$date->setTimezone(new \DateTimeZone('America/Chicago'));
      $dtstart = $date->format('Ymd');

      // Generate a stable UID based on node ID and host.
      $uid = $node->id() . '@' . $host;

      // Get the node title for SUMMARY.
      $summary = $this->escapeIcalText($node->getTitle());

      // Get the body field for DESCRIPTION (strip HTML tags).
      $description = '';
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $body_value = $node->get('body')->value;
        $description = $this->escapeIcalText(strip_tags($body_value));
      }

      // Get the external URL field.
      $url = '';
      if ($node->hasField('field_article_source_link') && !$node->get('field_article_source_link')->isEmpty()) {
        $url = $node->get('field_article_source_link')->value;
      }

      // Use the node's changed timestamp for DTSTAMP and LAST-MODIFIED.
      $dtstamp = gmdate('Ymd\THis\Z', $node->getChangedTime());
      $created = gmdate('Ymd\THis\Z', $node->getCreatedTime());

      // Build the VEVENT.
      $lines[] = 'BEGIN:VEVENT';
      $lines[] = 'UID:' . $uid;
      $lines[] = 'DTSTAMP:' . $dtstamp;
      $lines[] = 'DTSTART;VALUE=DATE:' . $dtstart;
      $lines[] = 'SUMMARY:' . $summary;
      $lines[] = 'TRANSP:TRANSPARENT';
      $lines[] = 'CREATED:' . $created;
      $lines[] = 'LAST-MODIFIED:' . $dtstamp;

      if (!empty($description)) {
        $lines[] = $this->foldLine('DESCRIPTION:' . $description);
      }

      if (!empty($url)) {
        $lines[] = 'URL:' . $url;
      }

      $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    // iCal uses CRLF line endings per RFC 5545.
    return implode("\r\n", $lines) . "\r\n";
  }

  /**
   * Escapes text for iCal output per RFC 5545.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   The escaped text.
   */
  protected function escapeIcalText(string $text): string {
    // Decode HTML entities first.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize line endings to \n.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Escape backslashes, semicolons, and commas per RFC 5545.
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(';', '\\;', $text);
    $text = str_replace(',', '\\,', $text);
    // Escape newlines as literal \n in iCal.
    $text = str_replace("\n", '\\n', $text);

    return $text;
  }

  /**
   * Folds long lines per RFC 5545 (max 75 octets per line).
   *
   * Lines longer than 75 octets are folded by inserting a CRLF followed by
   * a single whitespace character.
   *
   * @param string $line
   *   The line to fold.
   *
   * @return string
   *   The folded line.
   */
  protected function foldLine(string $line): string {
    if (strlen($line) <= 75) {
      return $line;
    }

    $folded = '';
    $remaining = $line;

    // First line can be 75 octets.
    $folded .= mb_strcut($remaining, 0, 75);
    $remaining = mb_strcut($remaining, 75);

    // Continuation lines: CRLF + space + up to 74 octets of content.
    while (strlen($remaining) > 0) {
      $folded .= "\r\n " . mb_strcut($remaining, 0, 74);
      $remaining = mb_strcut($remaining, 74);
    }

    return $folded;
  }

}
