<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\shared_content\Service\SharedContentFieldMapper;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Essential shared content management commands.
 */
final class SharedContentEssentialCommands extends DrushCommands {

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly ClientInterface $httpClient,
    protected readonly TimeInterface $time,
    protected readonly StateInterface $state,
    protected readonly SharedContentFieldMapper $fieldMapper,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('http_client'),
      $container->get('datetime.time'),
      $container->get('state'),
      $container->get('shared_content.field_mapper'),
    );
  }

  /**
   * Force-refresh all shared content nodes from their XML feeds.
   *
   * Fetches fresh XML for every shared content node, updates field values,
   * and deletes nodes whose source returns errors or empty XML.
   */
  #[CLI\Command(name: 'shared_content:force-update', aliases: ['sc:force-update'])]
  #[CLI\Option(name: 'type', description: 'Limit to content type(s), comma-separated')]
  #[CLI\Option(name: 'limit', description: 'Max number of nodes to process')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without saving')]
  #[CLI\Option(name: 'batch-size', description: 'Process in batches of N to manage memory (default: 50)')]
  #[CLI\Usage(name: 'drush sc:force-update', description: 'Update all shared content nodes.')]
  #[CLI\Usage(name: 'drush sc:force-update --type=event', description: 'Update only event.')]
  #[CLI\Usage(name: 'drush sc:force-update --type=article,person --limit=10', description: 'Update 10 articles and faculty.')]
  #[CLI\Usage(name: 'drush sc:force-update --dry-run', description: 'Preview what would change.')]
  public function forceUpdate(
    array $options = [
      'type' => NULL,
      'limit' => 0,
      'dry-run' => FALSE,
      'batch-size' => 50,
    ],
  ): void {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');

    if (!empty($options['type'])) {
      $types = array_map('trim', explode(',', $options['type']));
      $query->condition('type', $types, 'IN');
    }

    if ((int) $options['limit'] > 0) {
      $query->range(0, (int) $options['limit']);
    }

    $nids = $query->execute();

    if (empty($nids)) {
      $this->io()->text('No shared content nodes found.');
      return;
    }

    $total = count($nids);
    $batch_size = max(1, (int) $options['batch-size']);
    $dry_run = (bool) $options['dry-run'];

    $this->io()->title('Force Update Shared Content');
    $this->io()->text("Nodes to process: $total (batch size: $batch_size)");
    if ($dry_run) {
      $this->io()->text('DRY RUN — no changes will be saved.');
    }
    $this->io()->newLine();

    $updated = 0;
    $deleted = 0;
    $skipped = 0;
    $errors = 0;
    $bundles = $this->bundleInfo->getBundleInfo('node');
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach (array_chunk($nids, $batch_size, TRUE) as $batch) {
      $nodes = $node_storage->loadMultiple($batch);

      foreach ($nodes as $node) {
        $nid = $node->id();
        $type = $node->bundle();
        $label = $bundles[$type]['label'] ?? $type;

        if ($node->get('field_shared_content_xml')->isEmpty()) {
          $skipped++;
          continue;
        }

        $url = (string) $node->get('field_shared_content_xml')->value;

        try {
          $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'http_errors' => FALSE,
            'headers' => [
              'Cache-Control' => 'no-cache',
              'Pragma' => 'no-cache',
            ],
          ]);

          $status_code = $response->getStatusCode();

          if ($status_code !== 200) {
            $this->io()->text("  NID $nid [$label]: HTTP $status_code — deleting");
            if (!$dry_run) {
              $node->delete();
            }
            $deleted++;
            continue;
          }

          $content = (string) $response->getBody();
          $xml = @simplexml_load_string($content);

          if (!$xml || !isset($xml->node) || count((array) $xml->node) === 0) {
            $this->io()->text("  NID $nid [$label]: Empty/invalid XML — deleting");
            if (!$dry_run) {
              $node->delete();
            }
            $deleted++;
            continue;
          }

          // Update hash if the field exists.
          if ($node->hasField('field_content_hash')) {
            $new_hash = hash('sha256', $content);
            $old_hash = $node->get('field_content_hash')->value ?? '';

            if ($new_hash === $old_hash) {
              $skipped++;
              continue;
            }

            $node->set('field_content_hash', $new_hash);
          }

          if ($node->hasField('field_last_fetch')) {
            $node->set('field_last_fetch', time());
          }

          // Update the stored XML.
          $node->set('field_shared_content', base64_encode($xml->asXML()));
          $node->set('field_shared_content_xml', $url);

          // Apply title, body, and per-bundle field values via the shared
          // field mapper, the same code cron refresh uses, so force-update
          // and refresh always agree. Bookkeeping fields (hash, last_fetch,
          // shared_content) are set above.
          $this->fieldMapper->mapAllFields($node, $xml->node, $url);

          if (!$dry_run) {
            $node->setNewRevision(TRUE);
            $node->setChangedTime($this->time->getRequestTime());
            $node->save();
          }

          $this->io()->text("  NID $nid [$label]: Updated");
          $updated++;
        }
        catch (\Exception $e) {
          $this->io()->text("  NID $nid [$label]: Error — {$e->getMessage()}");
          $errors++;
        }
      }

      // Free memory between batches.
      $node_storage->resetCache(array_keys($nodes));
    }

    // Summary.
    $this->io()->newLine();
    $this->io()->title('Summary');
    $rows = [
      ['Total', number_format($total)],
      ['Updated', number_format($updated)],
      ['Deleted (bad XML/404)', number_format($deleted)],
      ['Skipped (unchanged)', number_format($skipped)],
      ['Errors', number_format($errors)],
    ];
    $this->io()->table(['', 'Count'], $rows);

    if ($dry_run) {
      $this->io()->warning('This was a dry run. Run without --dry-run to apply changes.');
    }
  }

  /**
   * Find and delete aggregator feeds (and their nodes) matching a pattern.
   */
  #[CLI\Command(name: 'shared_content:feed-delete', aliases: ['sc:feed-delete'])]
  #[CLI\Option(name: 'url', description: 'URL pattern to match (e.g. "lasprogram.wustl.edu")')]
  #[CLI\Option(name: 'title', description: 'Title pattern to match')]
  #[CLI\Option(name: 'dry-run', description: 'Preview matches without deleting')]
  #[CLI\Usage(name: 'drush sc:feed-delete --url=lasprogram.wustl.edu', description: 'Delete feeds with URLs matching lasprogram.')]
  #[CLI\Usage(name: 'drush sc:feed-delete --title="Digital Commons"', description: 'Delete feeds with title matching Digital Commons.')]
  #[CLI\Usage(name: 'drush sc:feed-delete --url=lasprogram --dry-run', description: 'Preview what would be deleted.')]
  public function feedDelete(
    array $options = [
      'url' => NULL,
      'title' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $url_match = trim((string) ($options['url'] ?? ''));
    $title_match = trim((string) ($options['title'] ?? ''));
    $dry_run = (bool) $options['dry-run'];

    if ($url_match === '' && $title_match === '') {
      $this->io()->error('Provide at least one of --url or --title.');
      return;
    }

    $this->io()->title('Feed Delete' . ($dry_run ? ' (DRY RUN)' : ''));

    // Find matching feeds.
    $feeds = $this->findMatchingFeeds($url_match, $title_match);

    if (empty($feeds)) {
      $this->io()->text('No matching feeds found.');
      return;
    }

    // Show what we found.
    $rows = [];
    foreach ($feeds as $feed) {
      $rows[] = [$feed->id(), $feed->label(), $feed->get('url')->value];
    }
    $this->io()->table(['ID', 'Title', 'URL'], $rows);
    $this->io()->text(count($feeds) . ' feed(s) matched.');

    // Find associated nodes.
    $node_count = 0;
    $node_nids = [];
    foreach ($feeds as $feed) {
      $feed_url = $feed->get('url')->value;
      $feed_domain = parse_url($feed_url, PHP_URL_HOST);

      if ($feed_domain) {
        $nids = $this->database->query("
          SELECT x.entity_id
          FROM {node__field_shared_content_xml} x
          WHERE x.field_shared_content_xml_value LIKE :pattern
        ", [':pattern' => "%$feed_domain%"])->fetchCol();

        $node_nids = array_merge($node_nids, $nids);
      }
    }
    $node_nids = array_unique($node_nids);
    $node_count = count($node_nids);

    if ($node_count > 0) {
      $this->io()->text("$node_count associated node(s) will also be deleted.");
    }

    if ($dry_run) {
      $this->io()->warning('Dry run — nothing was deleted.');
      return;
    }

    // Confirm.
    if (!$this->io()->confirm("Delete " . count($feeds) . " feed(s) and $node_count node(s)?")) {
      $this->io()->text('Cancelled.');
      return;
    }

    // Delete nodes.
    $deleted_nodes = 0;
    $node_storage = $this->entityTypeManager->getStorage('node');
    if (!empty($node_nids)) {
      foreach (array_chunk($node_nids, 50) as $chunk) {
        $nodes = $node_storage->loadMultiple($chunk);
        foreach ($nodes as $node) {
          $node->delete();
          $deleted_nodes++;
        }
      }
    }

    // Delete feed items.
    $deleted_items = 0;
    $item_storage = $this->entityTypeManager->getStorage('aggregator_item');
    foreach ($feeds as $feed) {
      $item_ids = $item_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('fid', $feed->id())
        ->execute();
      if ($item_ids) {
        $items = $item_storage->loadMultiple($item_ids);
        foreach ($items as $item) {
          $item->delete();
          $deleted_items++;
        }
      }
    }

    // Delete feeds.
    $deleted_feeds = 0;
    foreach ($feeds as $feed) {
      $this->io()->text("  Deleted feed: {$feed->label()}");
      $feed->delete();
      $deleted_feeds++;
    }

    $this->io()->success("Deleted $deleted_feeds feed(s), $deleted_items item(s), $deleted_nodes node(s).");
  }

  /**
   * Find and update aggregator feed URLs matching a pattern.
   */
  #[CLI\Command(name: 'shared_content:feed-update', aliases: ['sc:feed-update'])]
  #[CLI\Option(name: 'find', description: 'URL string to find (e.g. "eps.wustl.edu")')]
  #[CLI\Option(name: 'replace', description: 'URL string to replace with (e.g. "eeps.wustl.edu")')]
  #[CLI\Option(name: 'dry-run', description: 'Preview matches without changing')]
  #[CLI\Usage(name: 'drush sc:feed-update --find=eps.wustl.edu --replace=eeps.wustl.edu', description: 'Update feed URLs from old domain to new.')]
  #[CLI\Usage(name: 'drush sc:feed-update --find=artsci.wustl.edu --replace=artsci.washu.edu --dry-run', description: 'Preview domain change.')]
  public function feedUpdate(
    array $options = [
      'find' => NULL,
      'replace' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $find = trim((string) ($options['find'] ?? ''));
    $replace = trim((string) ($options['replace'] ?? ''));
    $dry_run = (bool) $options['dry-run'];

    if ($find === '' || $replace === '') {
      $this->io()->error('Both --find and --replace are required.');
      return;
    }

    $this->io()->title('Feed URL Update' . ($dry_run ? ' (DRY RUN)' : ''));
    $this->io()->text("Find:    $find");
    $this->io()->text("Replace: $replace");
    $this->io()->newLine();

    // Update aggregator feeds.
    $feed_storage = $this->entityTypeManager->getStorage('aggregator_feed');
    $all_feed_ids = $feed_storage->getQuery()->accessCheck(FALSE)->execute();
    $feeds = $feed_storage->loadMultiple($all_feed_ids);

    $feed_changes = 0;
    foreach ($feeds as $feed) {
      $url = (string) $feed->get('url')->value;
      if (str_contains($url, $find)) {
        $new_url = str_replace($find, $replace, $url);
        $this->io()->text("  Feed {$feed->id()}: {$feed->label()}");
        $this->io()->text("    $url");
        $this->io()->text("    => $new_url");

        if (!$dry_run) {
          $feed->set('url', $new_url);
          $feed->save();
        }
        $feed_changes++;
      }
    }

    // Update node XML URLs.
    $node_changes = 0;
    $node_storage = $this->entityTypeManager->getStorage('node');
    if ($this->database->schema()->tableExists('node__field_shared_content_xml')) {
      $affected = $this->database->query("
        SELECT entity_id, field_shared_content_xml_value AS url
        FROM {node__field_shared_content_xml}
        WHERE field_shared_content_xml_value LIKE :pattern
      ", [':pattern' => "%$find%"])->fetchAll();

      if (!empty($affected)) {
        $this->io()->newLine();
        $this->io()->text("Found " . count($affected) . " node(s) with matching XML URLs.");

        foreach (array_chunk($affected, 50) as $chunk) {
          $nids = array_column($chunk, 'entity_id');
          $nodes = $node_storage->loadMultiple($nids);

          foreach ($nodes as $node) {
            $old_url = (string) $node->get('field_shared_content_xml')->value;
            $new_url = str_replace($find, $replace, $old_url);

            $this->io()->text("  NID {$node->id()}: $old_url => $new_url");

            if (!$dry_run) {
              $node->set('field_shared_content_xml', $new_url);
              $node->setNewRevision(TRUE);
              $node->setRevisionLogMessage("Updated XML URL: $find => $replace");
              $node->save();
            }
            $node_changes++;
          }
        }
      }
    }

    $this->io()->newLine();
    if ($feed_changes + $node_changes === 0) {
      $this->io()->text("No matches found for '$find'.");
    }
    else {
      $action = $dry_run ? 'Would update' : 'Updated';
      $this->io()->success("$action $feed_changes feed(s) and $node_changes node(s).");
    }

    if ($dry_run) {
      $this->io()->warning('Dry run — nothing was changed. Run without --dry-run to apply.');
    }
  }

  /**
   * Process event: delete old event, update aliases on past event.
   *
   * Same logic as olympian_seo_cron but runnable on demand with options.
   */
  #[CLI\Command(name: 'shared_content:process-event', aliases: ['sc:event'])]
  #[CLI\Option(name: 'max-age', description: 'Delete event older than N days (default: 365)')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush sc:event', description: 'Process all event (delete old, update aliases).')]
  #[CLI\Usage(name: 'drush sc:event --dry-run', description: 'Preview what would happen.')]
  #[CLI\Usage(name: 'drush sc:event --max-age=180', description: 'Delete event older than 6 months.')]
  public function processEvents(
    array $options = [
      'max-age' => 365,
      'dry-run' => FALSE,
    ],
  ): void {
    $max_age_days = (int) $options['max-age'];
    $dry_run = (bool) $options['dry-run'];

    $now = new \DateTimeImmutable();
    $cutoff = $now->sub(new \DateInterval("P{$max_age_days}D"));

    $this->io()->title('Process Events' . ($dry_run ? ' (DRY RUN)' : ''));
    $this->io()->text("Cutoff date: {$cutoff->format('Y-m-d')} ($max_age_days days ago)");
    $this->io()->newLine();

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->condition('type', 'event')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      $this->io()->text('No event nodes found.');
      return;
    }

    $deleted = 0;
    $updated = 0;
    $skipped = 0;
    $recovered = 0;
    $errors = 0;

    $batches = array_chunk($nids, 50, TRUE);

    foreach ($batches as $batch) {
      $nodes = $node_storage->loadMultiple($batch);

      foreach ($nodes as $node) {
        $nid = $node->id();

        // Skip TBD event.
        // if ($node->get('field_event_date_tbd')->value == 1) {
        //   $skipped++;
        //   continue;
        // }

        // Get Smart Date value.
        $smart_date_field = $node->get('field_event_when')->first();
        $smart_date_value = $smart_date_field?->get('value')->getString();

        // Try to recover from XML if missing.
        if (!$smart_date_value) {
          $smart_date_value = $this->recoverSmartDate($node);
          if ($smart_date_value) {
            $recovered++;
          }
          else {
            $this->io()->text("  NID $nid: Missing Smart Date, skipping");
            $skipped++;
            continue;
          }
        }

        // Parse the date.
        try {
          $event_date = ctype_digit($smart_date_value)
            ? (new \DateTimeImmutable())->setTimestamp((int) $smart_date_value)
            : new \DateTimeImmutable($smart_date_value);
        }
        catch (\Exception $e) {
          $this->io()->text("  NID $nid: Invalid date '$smart_date_value'");
          $errors++;
          continue;
        }

        // Delete event older than cutoff.
        if ($event_date < $cutoff) {
          $this->io()->text("  NID $nid: Deleting — event date {$event_date->format('Y-m-d')} exceeds max age");
          if (!$dry_run) {
            $node->delete();
          }
          $deleted++;
        }
        // Update alias on past event (but not future ones).
        elseif ($event_date < $now) {
          $field_value = $node->get('field_event_date_alias')->value;

          if ($field_value && str_contains($field_value, 'current event')) {
            $new_value = str_replace('current event', 'past event', $field_value);
            $this->io()->text("  NID $nid: Updating alias — current event => past event");

            if (!$dry_run) {
              $node->set('field_event_date_alias', $new_value);
              $node->setNewRevision(TRUE);
              $node->setRevisionCreationTime($this->time->getRequestTime());
              $node->setRevisionUserId(1);
              $node->setRevisionLogMessage('Updated event alias: current event => past event.');
              $node->save();
            }
            $updated++;
          }
          else {
            $skipped++;
          }
        }
        // Future event — leave alone.
        else {
          $skipped++;
        }
      }

      $node_storage->resetCache(array_keys($nodes));
    }

    // Summary.
    $this->io()->newLine();
    $rows = [
      ['Total event', number_format(count($nids))],
      ['Deleted (past cutoff)', number_format($deleted)],
      ['Alias updated', number_format($updated)],
      ['Smart Date recovered', number_format($recovered)],
      ['Skipped (no change)', number_format($skipped)],
      ['Errors', number_format($errors)],
    ];
    $this->io()->table(['', 'Count'], $rows);

    if ($dry_run) {
      $this->io()->warning('Dry run — no changes were made.');
    }
  }

  /**
   * Run shared content monitoring: snapshot node counts, check for drops.
   *
   * Same logic as shared_content_cron but runnable on demand.
   */
  #[CLI\Command(name: 'shared_content:monitor', aliases: ['sc:monitor'])]
  #[CLI\Option(name: 'threshold', description: 'Alert if a type drops by more than N nodes (default: 5)')]
  #[CLI\Option(name: 'percent', description: 'Alert if a type drops by more than N percent (default: 10)')]
  #[CLI\Option(name: 'save', description: 'Save current counts as the new baseline')]
  #[CLI\Usage(name: 'drush sc:monitor', description: 'Compare current counts against last snapshot.')]
  #[CLI\Usage(name: 'drush sc:monitor --save', description: 'Save current counts as baseline.')]
  #[CLI\Usage(name: 'drush sc:monitor --threshold=3 --percent=5', description: 'Use tighter thresholds.')]
  public function monitor(
    array $options = [
      'threshold' => 5,
      'percent' => 10,
      'save' => FALSE,
    ],
  ): void {
    $threshold = (int) $options['threshold'];
    $pct_threshold = (float) $options['percent'];
    $bundles = $this->bundleInfo->getBundleInfo('node');

    $this->io()->title('Shared Content Monitor');

    // Current counts.
    $current = $this->database->query("
      SELECT type, COUNT(*) AS count
      FROM {node_field_data}
      GROUP BY type
      ORDER BY count DESC
    ")->fetchAllKeyed();
    $current = array_map('intval', $current);

    // Previous snapshot.
    $previous = $this->state->get('shared_content.node_count_snapshot');
    $previous_time = $this->state->get('shared_content.node_count_snapshot_time');

    if (empty($previous)) {
      $this->io()->text('No previous snapshot. Current counts:');
      $this->io()->newLine();
      $this->displayCounts($current, $bundles);

      if ($options['save']) {
        $this->saveSnapshot($current);
        $this->io()->success('Snapshot saved as baseline.');
      }
      else {
        $this->io()->text('Run with --save to establish a baseline.');
      }
      return;
    }

    $since = $previous_time ? date('Y-m-d H:i:s', $previous_time) : 'unknown';
    $this->io()->text("Comparing against snapshot from: $since");
    $this->io()->newLine();

    // Compare.
    $all_types = array_unique(array_merge(array_keys($current), array_keys($previous)));
    sort($all_types);

    $rows = [];
    $alerts = [];

    foreach ($all_types as $type) {
      $prev = $previous[$type] ?? 0;
      $curr = $current[$type] ?? 0;
      $diff = $curr - $prev;
      $pct = ($prev > 0) ? round(($diff / $prev) * 100, 1) : ($curr > 0 ? 100 : 0);

      $label = $bundles[$type]['label'] ?? $type;

      $delta_str = $diff > 0 ? "+$diff (+{$pct}%)" : ($diff < 0 ? "$diff ({$pct}%)" : '—');

      $status = '';
      if ($diff < 0) {
        $abs_diff = abs($diff);
        $abs_pct = abs($pct);
        if ($abs_diff >= $threshold && $abs_pct >= $pct_threshold) {
          $status = 'ALERT';
          $alerts[] = "$label: $prev => $curr ($diff, {$pct}%)";
        }
        elseif ($abs_diff >= $threshold || $abs_pct >= $pct_threshold) {
          $status = 'WARN';
        }
      }
      elseif ($prev > 0 && $curr === 0) {
        $status = 'GONE';
        $alerts[] = "$label: ALL $prev nodes deleted!";
      }

      if ($diff !== 0 || $status !== '') {
        $rows[] = [$label, $type, $prev, $curr, $delta_str, $status];
      }
    }

    if (empty($rows)) {
      $this->io()->success('No changes since last snapshot.');
    }
    else {
      $this->io()->table(['Label', 'Type', 'Previous', 'Current', 'Delta', 'Status'], $rows);
    }

    if (!empty($alerts)) {
      $this->io()->error('Content drops detected:');
      foreach ($alerts as $alert) {
        $this->io()->text("  $alert");
      }
      $this->io()->newLine();
      $this->io()->text('Run drush sc:audit to investigate deletions.');
    }

    if ($options['save']) {
      $this->saveSnapshot($current);
      $this->io()->newLine();
      $this->io()->success('Snapshot updated.');
    }
  }

  /**
   * Apply type-specific XML field values to a node.
   */
  private function applyXmlFields($node, string $type, \SimpleXMLElement $xmlElement, string $url): void {
    // External link (shared across types).
    if ($node->hasField('field_event_series_link') && isset($xmlElement->externalURL) && !empty((string) $xmlElement->externalURL)) {
      $node->set('field_event_series_link', ['uri' => (string) $xmlElement->externalURL]);
    }

    if ($type === 'event' && isset($xmlElement->eventDate)) {
      // $node->set('field_event_date_tbd', (int) ($xmlElement->eventTBD ?? 0));

      $dateStart = isset($xmlElement->eventDateStart) ? (string) $xmlElement->eventDateStart : NULL;
      $dateEnd = isset($xmlElement->eventDateEnd) ? (string) $xmlElement->eventDateEnd : NULL;

      if ($dateStart) {
        $start = strtotime($dateStart);
        $end = $dateEnd ? strtotime($dateEnd) : NULL;
        if ($start) {
          // $node->set('field_show_end_date', TRUE);
          $node->set('field_event_when', ['value' => $start, 'end_value' => $end]);
        }
      }
      else {
        $dateField = (string) $xmlElement->eventDate;
        $dates = explode(' to ', $dateField);
        if (count($dates) === 2) {
          $start = strtotime(trim($dates[0]));
          $end = strtotime(trim($dates[1]));
          if ($start) {
            // $node->set('field_show_end_date', TRUE);
            $node->set('field_event_when', ['value' => $start, 'end_value' => $end ?: NULL]);
          }
        }
      }
    }

    if ($type === 'article' && isset($xmlElement->postDate)) {
      $date = \DateTime::createFromFormat('n.j.y', (string) $xmlElement->postDate);
      $node->setCreatedTime($date ? $date->getTimestamp() : $this->time->getCurrentTime());
    }

    if ($type === 'person') {
      $firstName = isset($xmlElement->firstName) ? (string) $xmlElement->firstName : '';
      $lastName = isset($xmlElement->lastName) ? (string) $xmlElement->lastName : '';

      if (!empty($firstName) || !empty($lastName)) {
        $node->set('field_person_first_name', $firstName);
        $node->set('field_person_last_name', $lastName);
        $node->setTitle(trim("$firstName $lastName"));
      }
      else {
        $node->setTitle((string) $xmlElement->title);
      }
    }
  }

  /**
   * Try to recover Smart Date from shared content XML.
   */
  private function recoverSmartDate($node): ?string {
    if (!$node->hasField('field_shared_content_xml') || $node->get('field_shared_content_xml')->isEmpty()) {
      return NULL;
    }

    $url = $node->get('field_shared_content_xml')->value;

    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
      if ($response->getStatusCode() !== 200) {
        return NULL;
      }

      $xml = simplexml_load_string((string) $response->getBody());
      if (!$xml || !isset($xml->node)) {
        return NULL;
      }

      $el = $xml->node;
      $dateStart = isset($el->eventDateStart) ? (string) $el->eventDateStart : NULL;
      $dateEnd = isset($el->eventDateEnd) ? (string) $el->eventDateEnd : NULL;
      $dateField = isset($el->eventDate) ? (string) $el->eventDate : NULL;

      $start_ts = NULL;
      $end_ts = NULL;

      if ($dateStart) {
        $start_ts = strtotime($dateStart);
        $end_ts = $dateEnd ? strtotime($dateEnd) : NULL;
      }
      elseif ($dateField && str_contains($dateField, 'to')) {
        [$s, $e] = array_map('trim', explode('to', $dateField));
        $start_ts = strtotime($s);
        $end_ts = strtotime($e);
      }
      elseif ($dateField) {
        $start_ts = strtotime($dateField);
      }

      if ($start_ts) {
        $values = ['value' => $start_ts];
        if ($end_ts) {
          $values['end_value'] = $end_ts;
        }
        $node->set('field_event_when', $values);
        $node->save();
        return (string) $start_ts;
      }
    }
    catch (\Exception $e) {
      // Silently fail — caller handles the NULL.
    }

    return NULL;
  }

  /**
   * Find aggregator feeds matching URL and/or title patterns.
   */
  private function findMatchingFeeds(string $url_match, string $title_match): array {
    $feed_storage = $this->entityTypeManager->getStorage('aggregator_feed');
    $all_ids = $feed_storage->getQuery()->accessCheck(FALSE)->execute();
    $all_feeds = $feed_storage->loadMultiple($all_ids);

    $matched = [];
    foreach ($all_feeds as $feed) {
      $title = (string) $feed->label();
      $url = (string) $feed->get('url')->value;

      $title_ok = ($title_match === '') || stripos($title, $title_match) !== FALSE;
      $url_ok = ($url_match === '') || stripos($url, $url_match) !== FALSE;

      if ($title_ok && $url_ok) {
        $matched[] = $feed;
      }
    }

    return $matched;
  }

  /**
   * Display node counts as a table.
   */
  private function displayCounts(array $counts, array $bundles): void {
    arsort($counts);
    $rows = [];
    foreach ($counts as $type => $count) {
      $label = $bundles[$type]['label'] ?? $type;
      $rows[] = [$label, $type, number_format($count)];
    }
    $rows[] = ['', 'TOTAL', number_format(array_sum($counts))];
    $this->io()->table(['Label', 'Type', 'Count'], $rows);
  }

  /**
   * Save node count snapshot to state.
   */
  private function saveSnapshot(array $counts): void {
    $this->state->set('shared_content.node_count_snapshot', $counts);
    $this->state->set('shared_content.node_count_snapshot_time', $this->time->getRequestTime());

    // Also snapshot imported counts.
    if ($this->database->schema()->tableExists('node__field_content_hash')) {
      $imported = $this->database->query("
        SELECT n.type, COUNT(*) AS count
        FROM {node_field_data} n
        INNER JOIN {node__field_content_hash} h ON h.entity_id = n.nid
        GROUP BY n.type
      ")->fetchAllKeyed();
      $this->state->set('shared_content.node_count_snapshot.imported', array_map('intval', $imported));
    }
  }

}
