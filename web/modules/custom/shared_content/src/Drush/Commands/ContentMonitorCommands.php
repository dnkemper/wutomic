<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\State\StateInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Proactive monitoring commands for shared content health.
 */
final class ContentMonitorCommands extends DrushCommands {

  /**
   * State key for node count snapshots.
   */
  const SNAPSHOT_KEY = 'shared_content.node_count_snapshot';

  /**
   * State key for snapshot timestamp.
   */
  const SNAPSHOT_TIME_KEY = 'shared_content.node_count_snapshot_time';

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly TimeInterface $time,
    protected readonly StateInterface $state,
    protected readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('state'),
      $container->get('http_client'),
    );
  }

  /**
   * Check health of all shared content XML feed sources.
   */
  #[CLI\Command(name: 'shared_content:feed-health', aliases: ['sc-feed-health'])]
  #[CLI\Option(name: 'timeout', description: 'HTTP request timeout in seconds (default: 15)')]
  #[CLI\Option(name: 'concurrency', description: 'Number of concurrent requests (default: 5)')]
  #[CLI\Option(name: 'show-ok', description: 'Also show healthy feeds in the output')]
  #[CLI\Usage(name: 'drush sc-feed-health', description: 'Check all feed source URLs.')]
  #[CLI\Usage(name: 'drush sc-feed-health --timeout=30', description: 'Check with a longer timeout.')]
  #[CLI\Usage(name: 'drush sc-feed-health --show-ok', description: 'Show all feeds including healthy ones.')]
  public function feedHealth(
    array $options = [
      'timeout' => 15,
      'concurrency' => 5,
      'show-ok' => FALSE,
    ],
  ): void {
    $timeout = (int) $options['timeout'];
    $concurrency = (int) $options['concurrency'];
    $show_ok = $options['show-ok'];

    if (!$this->database->schema()->tableExists('node__field_shared_content_xml')) {
      $this->io()->warning('field_shared_content_xml not found on this site.');
      return;
    }

    $this->io()->title('Shared Content Feed Health Check');

    // Get all unique feed URLs with their node counts.
    $feeds = $this->database->query("
      SELECT
        x.field_shared_content_xml_value AS url,
        COUNT(*) AS node_count,
        GROUP_CONCAT(DISTINCT n.type SEPARATOR ', ') AS bundles
      FROM {node__field_shared_content_xml} x
      INNER JOIN {node_field_data} n ON n.nid = x.entity_id
      WHERE x.field_shared_content_xml_value IS NOT NULL
        AND x.field_shared_content_xml_value != ''
      GROUP BY x.field_shared_content_xml_value
      ORDER BY node_count DESC
    ")->fetchAll();

    if (empty($feeds)) {
      $this->io()->text('No shared content feed URLs found.');
      return;
    }

    $total_feeds = count($feeds);
    $this->io()->text("Checking $total_feeds unique feed URLs (concurrency: $concurrency, timeout: {$timeout}s)...");
    $this->io()->newLine();

    // Build results via concurrent requests.
    $results = $this->checkFeeds($feeds, $timeout, $concurrency);

    // Categorize results.
    $healthy = [];
    $unhealthy = [];
    $errors = [];

    foreach ($results as $result) {
      if ($result['status'] === 'ok') {
        $healthy[] = $result;
      }
      elseif ($result['status'] === 'error') {
        $errors[] = $result;
      }
      else {
        $unhealthy[] = $result;
      }
    }

    // Display errors (timeouts, connection failures).
    if (!empty($errors)) {
      $this->io()->section('Connection Errors (' . count($errors) . ')');
      $rows = [];
      foreach ($errors as $r) {
        $rows[] = [
          mb_substr($r['url'], 0, 70),
          $r['node_count'],
          $r['bundles'],
          mb_substr($r['message'], 0, 50),
        ];
      }
      $this->io()->table(['Feed URL', 'Nodes', 'Types', 'Error'], $rows);
    }

    // Display non-200 responses.
    if (!empty($unhealthy)) {
      $this->io()->section('Non-200 Responses (' . count($unhealthy) . ')');
      $rows = [];
      foreach ($unhealthy as $r) {
        $rows[] = [
          mb_substr($r['url'], 0, 70),
          $r['node_count'],
          $r['bundles'],
          $r['http_code'],
          mb_substr($r['message'], 0, 40),
        ];
      }
      $this->io()->table(['Feed URL', 'Nodes', 'Types', 'HTTP', 'Details'], $rows);
    }

    // Display healthy feeds if requested.
    if ($show_ok && !empty($healthy)) {
      $this->io()->section('Healthy Feeds (' . count($healthy) . ')');
      $rows = [];
      foreach ($healthy as $r) {
        $rows[] = [
          mb_substr($r['url'], 0, 70),
          $r['node_count'],
          $r['bundles'],
          $r['http_code'],
        ];
      }
      $this->io()->table(['Feed URL', 'Nodes', 'Types', 'HTTP'], $rows);
    }

    // XML validation on healthy feeds — check if the response is actually XML.
    $invalid_xml = [];
    foreach ($healthy as $r) {
      if (!empty($r['content_type']) && !str_contains($r['content_type'], 'xml') && !str_contains($r['content_type'], 'text/plain')) {
        $invalid_xml[] = $r;
      }
    }

    if (!empty($invalid_xml)) {
      $this->io()->section('Unexpected Content-Type (' . count($invalid_xml) . ')');
      $rows = [];
      foreach ($invalid_xml as $r) {
        $rows[] = [
          mb_substr($r['url'], 0, 60),
          $r['node_count'],
          mb_substr($r['content_type'], 0, 40),
        ];
      }
      $this->io()->table(['Feed URL', 'Nodes', 'Content-Type'], $rows);
    }

    // Summary.
    $this->io()->newLine();
    $problem_count = count($errors) + count($unhealthy) + count($invalid_xml);
    $total_affected_nodes = 0;
    foreach (array_merge($errors, $unhealthy) as $r) {
      $total_affected_nodes += $r['node_count'];
    }

    if ($problem_count > 0) {
      $this->io()->warning("$problem_count problem(s) found affecting $total_affected_nodes node(s). Healthy: " . count($healthy) . "/$total_feeds");
    }
    else {
      $this->io()->success("All $total_feeds feeds are healthy.");
    }
  }

  /**
   * Check feeds concurrently using Guzzle Pool.
   */
  protected function checkFeeds(array $feeds, int $timeout, int $concurrency): array {
    $results = [];
    $feed_map = [];

    // Build request generator.
    $requests = function () use ($feeds, &$feed_map) {
      foreach ($feeds as $i => $feed) {
        $feed_map[$i] = $feed;
        yield $i => new Request('HEAD', $feed->url);
      }
    };

    $pool = new Pool($this->httpClient, $requests(), [
      'concurrency' => $concurrency,
      'options' => [
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'allow_redirects' => ['max' => 5],
        'http_errors' => FALSE,
      ],
      'fulfilled' => function ($response, $index) use (&$results, &$feed_map) {
        $feed = $feed_map[$index];
        $code = $response->getStatusCode();
        $content_type = $response->getHeaderLine('Content-Type');

        $results[] = [
          'url' => $feed->url,
          'node_count' => $feed->node_count,
          'bundles' => $feed->bundles,
          'status' => ($code >= 200 && $code < 400) ? 'ok' : 'http_error',
          'http_code' => $code,
          'content_type' => $content_type,
          'message' => ($code >= 400) ? "HTTP $code" : '',
        ];
      },
      'rejected' => function ($reason, $index) use (&$results, &$feed_map) {
        $feed = $feed_map[$index];
        $message = ($reason instanceof RequestException)
          ? $reason->getMessage()
          : (string) $reason;

        // Truncate verbose Guzzle messages.
        if (str_contains($message, ':')) {
          $parts = explode(':', $message);
          $message = end($parts);
        }

        $results[] = [
          'url' => $feed->url,
          'node_count' => $feed->node_count,
          'bundles' => $feed->bundles,
          'status' => 'error',
          'http_code' => 0,
          'content_type' => '',
          'message' => trim($message),
        ];
      },
    ]);

    $pool->promise()->wait();

    return $results;
  }

  /**
   * Take a snapshot of current node counts, compare with previous, alert on drops.
   */
  #[CLI\Command(name: 'shared_content:node-delta', aliases: ['sc-node-delta'])]
  #[CLI\Option(name: 'threshold', description: 'Alert if a content type drops by more than this many nodes (default: 5)')]
  #[CLI\Option(name: 'percent', description: 'Alert if a content type drops by more than this percentage (default: 10)')]
  #[CLI\Option(name: 'save', description: 'Save current counts as the new baseline snapshot')]
  #[CLI\Option(name: 'reset', description: 'Reset the baseline (clear stored snapshot)')]
  #[CLI\Usage(name: 'drush sc-node-delta', description: 'Compare current counts against last snapshot.')]
  #[CLI\Usage(name: 'drush sc-node-delta --save', description: 'Save current counts as the new baseline.')]
  #[CLI\Usage(name: 'drush sc-node-delta --threshold=3 --percent=5', description: 'Alert on smaller drops.')]
  #[CLI\Usage(name: 'drush sc-node-delta --reset', description: 'Clear the stored snapshot.')]
  public function nodeDelta(
    array $options = [
      'threshold' => 5,
      'percent' => 10,
      'save' => FALSE,
      'reset' => FALSE,
    ],
  ): void {
    $threshold = (int) $options['threshold'];
    $percent_threshold = (float) $options['percent'];
    $bundles = $this->bundleInfo->getBundleInfo('node');

    $this->io()->title('Node Count Delta Tracker');

    // Handle reset.
    if ($options['reset']) {
      $this->state->delete(self::SNAPSHOT_KEY);
      $this->state->delete(self::SNAPSHOT_TIME_KEY);
      $this->io()->success('Snapshot cleared.');
      return;
    }

    // Get current counts.
    $current = $this->getCurrentCounts();
    $current_total = array_sum($current);

    // Get previous snapshot.
    $previous = $this->state->get(self::SNAPSHOT_KEY);
    $previous_time = $this->state->get(self::SNAPSHOT_TIME_KEY);

    if (empty($previous)) {
      $this->io()->text('No previous snapshot found. Current counts:');
      $this->io()->newLine();
      $this->displayCounts($current, $bundles);
      $this->io()->newLine();

      if ($options['save']) {
        $this->saveSnapshot($current);
        $this->io()->success('Snapshot saved as baseline.');
      }
      else {
        $this->io()->text('Run with --save to establish the baseline snapshot.');
      }
      return;
    }

    // Compare.
    $previous_total = array_sum($previous);
    $since = $previous_time ? date('Y-m-d H:i:s', $previous_time) : 'unknown';

    $this->io()->text("Comparing against snapshot from: $since");
    $this->io()->newLine();

    // Build comparison table.
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

      // Format the delta.
      if ($diff > 0) {
        $delta_str = "+$diff (+{$pct}%)";
      }
      elseif ($diff < 0) {
        $delta_str = "$diff ({$pct}%)";
      }
      else {
        $delta_str = '—';
      }

      // Detect alert conditions.
      $alert = '';
      if ($diff < 0) {
        $abs_diff = abs($diff);
        $abs_pct = abs($pct);

        if ($abs_diff >= $threshold && $abs_pct >= $percent_threshold) {
          $alert = 'ALERT';
          $alerts[] = [
            'type' => $type,
            'label' => $label,
            'previous' => $prev,
            'current' => $curr,
            'diff' => $diff,
            'pct' => $pct,
          ];
        }
        elseif ($abs_diff >= $threshold || $abs_pct >= $percent_threshold) {
          $alert = 'WARN';
        }
      }
      // Also flag new types or types that disappeared entirely.
      elseif ($prev === 0 && $curr > 0) {
        $alert = 'NEW';
      }
      elseif ($prev > 0 && $curr === 0) {
        $alert = 'GONE';
        $alerts[] = [
          'type' => $type,
          'label' => $label,
          'previous' => $prev,
          'current' => $curr,
          'diff' => $diff,
          'pct' => -100,
        ];
      }

      $rows[] = [
        $label,
        $type,
        number_format($prev),
        number_format($curr),
        $delta_str,
        $alert,
      ];
    }

    // Add totals row.
    $total_diff = $current_total - $previous_total;
    $total_pct = ($previous_total > 0) ? round(($total_diff / $previous_total) * 100, 1) : 0;
    $total_delta = ($total_diff >= 0 ? "+$total_diff" : "$total_diff") . " ({$total_pct}%)";

    $rows[] = ['', 'TOTAL', number_format($previous_total), number_format($current_total), $total_delta, ''];

    $this->io()->table(['Label', 'Machine Name', 'Previous', 'Current', 'Delta', 'Status'], $rows);

    // Display alerts prominently.
    if (!empty($alerts)) {
      $this->io()->newLine();
      $this->io()->error('Content drops detected:');
      foreach ($alerts as $a) {
        $this->io()->text("  {$a['label']} ({$a['type']}): {$a['previous']} → {$a['current']} ({$a['diff']} nodes, {$a['pct']}%)");
      }
      $this->io()->newLine();
      $this->io()->text('Run drush sc-audit to investigate what deleted these nodes.');
      $this->io()->text('Run drush sc-integrity --check=deleted to check watchdog for details.');
    }
    else {
      $this->io()->success('No significant content drops detected.');
    }

    // Also check imported content specifically.
    $this->checkImportedDelta($previous, $current, $bundles, $threshold);

    // Save if requested.
    if ($options['save']) {
      $this->saveSnapshot($current);
      $this->io()->newLine();
      $this->io()->success('Snapshot updated.');
    }
  }

  /**
   * Show the current snapshot without comparing.
   */
  #[CLI\Command(name: 'shared_content:node-snapshot', aliases: ['sc-node-snapshot'])]
  #[CLI\Usage(name: 'drush sc-node-snapshot', description: 'Display the stored baseline snapshot.')]
  public function nodeSnapshot(): void {
    $bundles = $this->bundleInfo->getBundleInfo('node');
    $previous = $this->state->get(self::SNAPSHOT_KEY);
    $previous_time = $this->state->get(self::SNAPSHOT_TIME_KEY);

    $this->io()->title('Stored Node Count Snapshot');

    if (empty($previous)) {
      $this->io()->text('No snapshot stored. Run drush sc-node-delta --save to create one.');
      return;
    }

    $since = $previous_time ? date('Y-m-d H:i:s', $previous_time) : 'unknown';
    $this->io()->text("Snapshot taken: $since");
    $this->io()->newLine();

    $this->displayCounts($previous, $bundles);

    $this->io()->newLine();
    $this->io()->text('Current counts:');
    $this->io()->newLine();

    $current = $this->getCurrentCounts();
    $this->displayCounts($current, $bundles);
  }

  /**
   * Get current node counts per type.
   */
  protected function getCurrentCounts(): array {
    $results = $this->database->query("
      SELECT type, COUNT(*) AS count
      FROM {node_field_data}
      GROUP BY type
      ORDER BY count DESC
    ")->fetchAllKeyed();

    return array_map('intval', $results);
  }

  /**
   * Get current imported node counts per type.
   */
  protected function getImportedCounts(): array {
    $has_hash = $this->database->schema()->tableExists('node__field_content_hash');
    if (!$has_hash) {
      return [];
    }

    $results = $this->database->query("
      SELECT n.type, COUNT(*) AS count
      FROM {node_field_data} n
      INNER JOIN {node__field_content_hash} h ON h.entity_id = n.nid
      GROUP BY n.type
      ORDER BY count DESC
    ")->fetchAllKeyed();

    return array_map('intval', $results);
  }

  /**
   * Save current counts as the baseline snapshot.
   */
  protected function saveSnapshot(array $counts): void {
    $this->state->set(self::SNAPSHOT_KEY, $counts);
    $this->state->set(self::SNAPSHOT_TIME_KEY, $this->time->getRequestTime());

    // Also save imported counts separately.
    $imported = $this->getImportedCounts();
    $this->state->set(self::SNAPSHOT_KEY . '.imported', $imported);
  }

  /**
   * Display counts as a table.
   */
  protected function displayCounts(array $counts, array $bundles): void {
    arsort($counts);
    $rows = [];
    foreach ($counts as $type => $count) {
      $label = $bundles[$type]['label'] ?? $type;
      $rows[] = [$label, $type, number_format($count)];
    }
    $rows[] = ['', 'TOTAL', number_format(array_sum($counts))];
    $this->io()->table(['Label', 'Machine Name', 'Count'], $rows);
  }

  /**
   * Check imported content specifically for drops.
   */
  protected function checkImportedDelta(array $previous_total, array $current_total, array $bundles, int $threshold): void {
    $current_imported = $this->getImportedCounts();
    $previous_imported = $this->state->get(self::SNAPSHOT_KEY . '.imported', []);

    if (empty($previous_imported) && empty($current_imported)) {
      return;
    }

    if (empty($previous_imported)) {
      return;
    }

    $this->io()->newLine();
    $this->io()->section('Imported Content Delta');

    $all_types = array_unique(array_merge(array_keys($current_imported), array_keys($previous_imported)));
    sort($all_types);

    $rows = [];
    $import_alerts = [];

    foreach ($all_types as $type) {
      $prev = $previous_imported[$type] ?? 0;
      $curr = $current_imported[$type] ?? 0;
      $diff = $curr - $prev;

      if ($diff === 0) {
        continue;
      }

      $label = $bundles[$type]['label'] ?? $type;
      $pct = ($prev > 0) ? round(($diff / $prev) * 100, 1) : 0;

      $delta_str = ($diff > 0 ? "+$diff" : "$diff") . " ({$pct}%)";

      $alert = '';
      if ($diff < 0 && abs($diff) >= $threshold) {
        $alert = 'ALERT';
        $import_alerts[] = "$label: $prev → $curr ($diff)";
      }

      $rows[] = [
        $label,
        number_format($prev),
        number_format($curr),
        $delta_str,
        $alert,
      ];
    }

    if (empty($rows)) {
      $this->io()->text('No changes in imported content counts.');
      return;
    }

    $this->io()->table(['Type', 'Previous', 'Current', 'Delta', 'Status'], $rows);

    if (!empty($import_alerts)) {
      $this->io()->warning('Imported content drops — these may indicate feed or sync issues:');
      foreach ($import_alerts as $a) {
        $this->io()->text("  $a");
      }
    }
  }

}
