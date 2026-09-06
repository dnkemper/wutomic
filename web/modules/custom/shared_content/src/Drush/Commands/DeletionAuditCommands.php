<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for querying the node deletion audit log.
 */
final class DeletionAuditCommands extends DrushCommands {

  /**
   * The audit log table name.
   */
  const TABLE = 'shared_content_deletion_log';

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
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
    );
  }

  /**
   * Query the node deletion audit log.
   */
  #[CLI\Command(name: 'shared_content:audit', aliases: ['sc-audit'])]
  #[CLI\Option(name: 'days', description: 'How many days back to query (default: 30)')]
  #[CLI\Option(name: 'trigger', description: 'Filter by trigger type (e.g. cron, webhook, manual_ui, drush, batch, bulk_operation)')]
  #[CLI\Option(name: 'bundle', description: 'Filter by content type machine name')]
  #[CLI\Option(name: 'shared-only', description: 'Only show shared/imported content deletions')]
  #[CLI\Option(name: 'nid', description: 'Look up a specific node ID')]
  #[CLI\Option(name: 'limit', description: 'Max number of results (default: 50)')]
  #[CLI\Option(name: 'detail', description: 'Show full trace for each entry')]
  #[CLI\Usage(name: 'drush sc-audit', description: 'Show all deletions from the last 30 days.')]
  #[CLI\Usage(name: 'drush sc-audit --days=7 --trigger=cron', description: 'Cron-triggered deletions in the last week.')]
  #[CLI\Usage(name: 'drush sc-audit --shared-only --days=14', description: 'Shared content deletions in the last 2 weeks.')]
  #[CLI\Usage(name: 'drush sc-audit --nid=12345 --detail', description: 'Full detail for a specific node deletion.')]
  #[CLI\Usage(name: 'drush sc-audit --trigger=shared_content:webhook', description: 'Deletions triggered by shared_content webhooks.')]
  public function audit(
    array $options = [
      'days' => 30,
      'trigger' => NULL,
      'bundle' => NULL,
      'shared-only' => FALSE,
      'nid' => NULL,
      'limit' => 50,
      'detail' => FALSE,
    ],
  ): void {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->io()->warning('Audit log table does not exist yet. No deletions have been recorded.');
      $this->io()->text('The table is created automatically when the first node is deleted.');
      return;
    }

    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->orderBy('timestamp', 'DESC')
      ->range(0, (int) $options['limit']);

    // Time filter.
    $since = strtotime("-{$options['days']} days");
    $query->condition('timestamp', $since, '>=');

    // Trigger filter.
    if ($options['trigger']) {
      $query->condition('trigger_type', $options['trigger']);
    }

    // Bundle filter.
    if ($options['bundle']) {
      $query->condition('bundle', $options['bundle']);
    }

    // Shared content only.
    if ($options['shared-only']) {
      $or = $query->orConditionGroup()
        ->condition('is_shared', 1)
        ->isNotNull('content_hash');
      $query->condition($or);
    }

    // Specific NID.
    if ($options['nid']) {
      $query->condition('nid', (int) $options['nid']);
    }

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->io()->text('No deletions found matching the given criteria.');
      return;
    }

    $bundles = $this->bundleInfo->getBundleInfo('node');

    if ($options['detail']) {
      $this->renderDetailView($results, $bundles);
    }
    else {
      $this->renderTableView($results, $bundles);
    }

    // Summary stats.
    $this->io()->newLine();
    $this->renderSummary($results);
  }

  /**
   * Show summary statistics for deletions.
   */
  #[CLI\Command(name: 'shared_content:audit-summary', aliases: ['sc-audit-summary'])]
  #[CLI\Option(name: 'days', description: 'How many days back to summarize (default: 30)')]
  #[CLI\Usage(name: 'drush sc-audit-summary', description: 'Deletion summary for the last 30 days.')]
  #[CLI\Usage(name: 'drush sc-audit-summary --days=7', description: 'Deletion summary for the last week.')]
  public function auditSummary(array $options = ['days' => 30]): void {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->io()->warning('Audit log table does not exist yet.');
      return;
    }

    $since = strtotime("-{$options['days']} days");
    $bundles = $this->bundleInfo->getBundleInfo('node');
    $days = $options['days'];

    $this->io()->title("Deletion Audit Summary (last $days days)");

    // By trigger type.
    $by_trigger = $this->database->query("
      SELECT trigger_type, COUNT(*) AS count
      FROM {" . self::TABLE . "}
      WHERE timestamp >= :since
      GROUP BY trigger_type
      ORDER BY count DESC
    ", [':since' => $since])->fetchAll();

    if (!empty($by_trigger)) {
      $this->io()->section('Deletions by Trigger');
      $rows = [];
      foreach ($by_trigger as $row) {
        $rows[] = [$row->trigger_type, number_format($row->count)];
      }
      $this->io()->table(['Trigger', 'Count'], $rows);
    }

    // By content type.
    $by_bundle = $this->database->query("
      SELECT bundle, COUNT(*) AS count
      FROM {" . self::TABLE . "}
      WHERE timestamp >= :since
      GROUP BY bundle
      ORDER BY count DESC
    ", [':since' => $since])->fetchAll();

    if (!empty($by_bundle)) {
      $this->io()->section('Deletions by Content Type');
      $rows = [];
      foreach ($by_bundle as $row) {
        $label = $bundles[$row->bundle]['label'] ?? $row->bundle;
        $rows[] = [$label, $row->bundle, number_format($row->count)];
      }
      $this->io()->table(['Label', 'Machine Name', 'Count'], $rows);
    }

    // Shared vs non-shared.
    $shared_counts = $this->database->query("
      SELECT
        SUM(CASE WHEN is_shared = 1 OR content_hash IS NOT NULL THEN 1 ELSE 0 END) AS shared,
        SUM(CASE WHEN is_shared = 0 AND content_hash IS NULL THEN 1 ELSE 0 END) AS non_shared,
        COUNT(*) AS total
      FROM {" . self::TABLE . "}
      WHERE timestamp >= :since
    ", [':since' => $since])->fetchObject();

    if ($shared_counts) {
      $this->io()->section('Shared Content Impact');
      $rows = [
        ['Shared/imported content deleted', number_format($shared_counts->shared)],
        ['Non-shared content deleted', number_format($shared_counts->non_shared)],
        ['Total', number_format($shared_counts->total)],
      ];
      $this->io()->table(['Category', 'Count'], $rows);
    }

    // By user.
    $by_user = $this->database->query("
      SELECT uid, username, COUNT(*) AS count
      FROM {" . self::TABLE . "}
      WHERE timestamp >= :since
      GROUP BY uid, username
      ORDER BY count DESC
      LIMIT 10
    ", [':since' => $since])->fetchAll();

    if (!empty($by_user)) {
      $this->io()->section('Deletions by User (top 10)');
      $rows = [];
      foreach ($by_user as $row) {
        $rows[] = [$row->uid, $row->username ?: 'anonymous', number_format($row->count)];
      }
      $this->io()->table(['UID', 'Username', 'Count'], $rows);
    }

    // Daily trend.
    $daily = $this->database->query("
      SELECT DATE(FROM_UNIXTIME(timestamp)) AS day, COUNT(*) AS count
      FROM {" . self::TABLE . "}
      WHERE timestamp >= :since
      GROUP BY day
      ORDER BY day DESC
      LIMIT 14
    ", [':since' => $since])->fetchAll();

    if (!empty($daily)) {
      $this->io()->section('Daily Deletion Trend');
      $rows = [];
      foreach ($daily as $row) {
        $bar = str_repeat('█', min((int) $row->count, 50));
        $rows[] = [$row->day, number_format($row->count), $bar];
      }
      $this->io()->table(['Date', 'Count', ''], $rows);
    }
  }

  /**
   * Purge old audit log entries.
   */
  #[CLI\Command(name: 'shared_content:audit-purge', aliases: ['sc-audit-purge'])]
  #[CLI\Option(name: 'older-than', description: 'Purge entries older than N days (default: 90)')]
  #[CLI\Usage(name: 'drush sc-audit-purge', description: 'Purge audit entries older than 90 days.')]
  #[CLI\Usage(name: 'drush sc-audit-purge --older-than=180', description: 'Purge entries older than 6 months.')]
  public function auditPurge(array $options = ['older-than' => 90]): void {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->io()->text('Audit log table does not exist.');
      return;
    }

    $days = (int) $options['older-than'];
    $before = strtotime("-{$days} days");

    $count = $this->database->query(
      "SELECT COUNT(*) FROM {" . self::TABLE . "} WHERE timestamp < :before",
      [':before' => $before]
    )->fetchField();

    if ($count == 0) {
      $this->io()->text("No entries older than $days days.");
      return;
    }

    if (!$this->io()->confirm("Delete $count audit log entries older than $days days?")) {
      $this->io()->text('Cancelled.');
      return;
    }

    $deleted = $this->database->delete(self::TABLE)
      ->condition('timestamp', $before, '<')
      ->execute();

    $this->io()->success("Purged $deleted audit log entries.");
  }

  /**
   * Render results as a table.
   */
  protected function renderTableView(array $results, array $bundles): void {
    $this->io()->title('Deletion Audit Log (' . count($results) . ' entries)');

    $rows = [];
    foreach ($results as $row) {
      $label = $bundles[$row->bundle]['label'] ?? $row->bundle;
      $shared_marker = '';
      if ($row->is_shared) {
        $shared_marker = ' [SHARED]';
      }
      elseif ($row->content_hash) {
        $shared_marker = ' [IMPORTED]';
      }

      $rows[] = [
        date('Y-m-d H:i:s', $row->timestamp),
        $row->nid,
        $label . $shared_marker,
        mb_substr($row->title, 0, 35),
        $row->trigger_type,
        $row->username . " ($row->uid)",
        mb_substr($row->trace_summary, 0, 50),
      ];
    }

    $this->io()->table(
      ['Timestamp', 'NID', 'Type', 'Title', 'Trigger', 'User', 'Trace'],
      $rows
    );
  }

  /**
   * Render detailed view of each deletion.
   */
  protected function renderDetailView(array $results, array $bundles): void {
    foreach ($results as $row) {
      $label = $bundles[$row->bundle]['label'] ?? $row->bundle;

      $this->io()->title("Node $row->nid — $row->title");
      $this->io()->definitionList(
        ['NID' => $row->nid],
        ['UUID' => $row->uuid],
        ['Type' => "$label ($row->bundle)"],
        ['Title' => $row->title],
        ['Published' => $row->status ? 'Yes' : 'No'],
        ['Deleted at' => date('Y-m-d H:i:s', $row->timestamp)],
        ['Deleted by' => "$row->username (uid: $row->uid)"],
        ['Trigger' => $row->trigger_type],
        ['Request' => "$row->request_method $row->request_uri"],
        ['Is shared' => $row->is_shared ? 'Yes' : 'No'],
        ['Content hash' => $row->content_hash ?: 'none'],
        ['XML source' => $row->xml_source ? mb_substr($row->xml_source, 0, 100) : 'none'],
      );

      $this->io()->section('Trace Summary');
      $this->io()->text($row->trace_summary);

      if ($row->trace_full) {
        $this->io()->section('Full Stack Trace');
        $this->io()->text($row->trace_full);
      }

      $this->io()->newLine();
    }
  }

  /**
   * Render a quick summary at the bottom of the results.
   */
  protected function renderSummary(array $results): void {
    $triggers = [];
    $shared = 0;
    foreach ($results as $row) {
      $triggers[$row->trigger_type] = ($triggers[$row->trigger_type] ?? 0) + 1;
      if ($row->is_shared || $row->content_hash) {
        $shared++;
      }
    }

    arsort($triggers);
    $trigger_parts = [];
    foreach ($triggers as $trigger => $count) {
      $trigger_parts[] = "$trigger: $count";
    }

    $total = count($results);
    $this->io()->text("$total deletion(s) shown. Shared/imported: $shared. By trigger: " . implode(', ', $trigger_parts));
  }

}
