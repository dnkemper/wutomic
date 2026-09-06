<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content integrity checker for shared_content.
 *
 * Detects missing, orphaned, and broken content across the site.
 */
final class ContentIntegrityCommands extends DrushCommands {

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Run all content integrity checks.
   */
  #[CLI\Command(name: 'shared_content:integrity', aliases: ['sc:integrity'])]
  #[CLI\Option(name: 'check', description: 'Run a specific check: deleted, orphaned, broken-links, hashes, unpublished, all')]
  #[CLI\Option(name: 'days', description: 'How many days back to check deletion logs (default: 30)')]
  #[CLI\Option(name: 'fix', description: 'Attempt to fix issues where possible (currently: broken links report only)')]
  #[CLI\Usage(name: 'drush sc:integrity', description: 'Run all integrity checks.')]
  #[CLI\Usage(name: 'drush sc:integrity --check=deleted --days=7', description: 'Check for deletions in the last 7 days.')]
  #[CLI\Usage(name: 'drush sc:integrity --check=broken-links', description: 'Scan for broken internal links only.')]
  public function integrity(
    array $options = [
      'check' => 'all',
      'days' => 30,
      'fix' => FALSE,
    ],
  ): void {
    $check = $options['check'];
    $days = (int) $options['days'];
    $bundles = $this->bundleInfo->getBundleInfo('node');

    $checks = [
      'deleted' => 'checkRecentDeletions',
      'orphaned' => 'checkOrphanedContent',
      'broken-links' => 'checkBrokenInternalLinks',
      'hashes' => 'checkDuplicateHashes',
      'unpublished' => 'checkUnexpectedUnpublished',
    ];

    $to_run = ($check === 'all') ? $checks : array_intersect_key($checks, [$check => TRUE]);

    if (empty($to_run)) {
      $this->io()->error("Unknown check: $check. Valid options: " . implode(', ', array_keys($checks)) . ', all');
      return;
    }

    $issue_count = 0;
    foreach ($to_run as $method) {
      $issue_count += $this->{$method}($bundles, $days, $options['fix']);
    }

    $this->io()->newLine();
    if ($issue_count > 0) {
      $this->io()->warning("Total issues found: $issue_count");
    }
    else {
      $this->io()->success('All integrity checks passed.');
    }

    $this->io()->text('Integrity check completed: ' . date('Y-m-d H:i:s'));
  }

  /**
   * Check watchdog for recent node deletions.
   */
  protected function checkRecentDeletions(array $bundles, int $days, bool $fix): int {
    $this->io()->title("Recently Deleted Nodes (last $days days)");

    if (!$this->database->schema()->tableExists('watchdog')) {
      $this->io()->text('Watchdog table not found (dblog module not enabled).');
      return 0;
    }

    $since = strtotime("-{$days} days");

    // Look for node deletion log entries.
    $results = $this->database->query("
      SELECT w.timestamp, w.uid, w.variables, w.message, w.link
      FROM {watchdog} w
      WHERE w.type = 'content'
        AND (w.message LIKE '%deleted%' OR w.message LIKE '%Deleted%')
        AND w.timestamp >= :since
      ORDER BY w.timestamp DESC
    ", [':since' => $since])->fetchAll();

    if (empty($results)) {
      $this->io()->text('No node deletions found in watchdog logs.');
      return 0;
    }

    $rows = [];
    foreach ($results as $row) {
      $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
      $message = $row->message;
      if (is_array($variables)) {
        $message = strtr($message, $variables);
      }
      $message = strip_tags($message);

      $rows[] = [
        date('Y-m-d H:i:s', $row->timestamp),
        $row->uid,
        mb_substr($message, 0, 80),
      ];
    }

    $this->io()->table(['Timestamp', 'UID', 'Message'], $rows);
    $this->io()->text(count($results) . ' deletion(s) found.');

    // Also check for shared_content-specific deletions.
    $shared_deletions = $this->database->query("
      SELECT w.timestamp, w.uid, w.variables, w.message
      FROM {watchdog} w
      WHERE w.type = 'shared_content'
        AND (w.message LIKE '%delet%' OR w.message LIKE '%remov%' OR w.message LIKE '%purg%')
        AND w.timestamp >= :since
      ORDER BY w.timestamp DESC
    ", [':since' => $since])->fetchAll();

    if (!empty($shared_deletions)) {
      $this->io()->newLine();
      $this->io()->text('shared_content module deletions:');
      $rows = [];
      foreach ($shared_deletions as $row) {
        $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
        $message = $row->message;
        if (is_array($variables)) {
          $message = strtr($message, $variables);
        }
        $rows[] = [
          date('Y-m-d H:i:s', $row->timestamp),
          $row->uid,
          mb_substr(strip_tags($message), 0, 80),
        ];
      }
      $this->io()->table(['Timestamp', 'UID', 'Message'], $rows);
    }

    return count($results);
  }

  /**
   * Check for orphaned shared content (hash/xml field but node is incomplete).
   */
  protected function checkOrphanedContent(array $bundles, int $days, bool $fix): int {
    $this->io()->title('Orphaned Shared Content');

    $issues = 0;
    $has_hash = $this->database->schema()->tableExists('node__field_content_hash');
    $has_xml = $this->database->schema()->tableExists('node__field_shared_content_xml');

    if (!$has_hash && !$has_xml) {
      $this->io()->text('No shared_content fields found on this site.');
      return 0;
    }

    // 1. Check for field table entries pointing to nonexistent nodes.
    $orphan_tables = [];
    if ($has_hash) {
      $orphan_tables['node__field_content_hash'] = 'field_content_hash';
    }
    if ($has_xml) {
      $orphan_tables['node__field_shared_content_xml'] = 'field_shared_content_xml';
    }

    foreach ($orphan_tables as $table => $field_name) {
      $orphaned = $this->database->query("
        SELECT f.entity_id, f.bundle
        FROM {{$table}} f
        LEFT JOIN {node} n ON n.nid = f.entity_id
        WHERE n.nid IS NULL
      ")->fetchAll();

      if (!empty($orphaned)) {
        $this->io()->warning(count($orphaned) . " orphaned rows in $table (node no longer exists):");
        $rows = [];
        foreach ($orphaned as $row) {
          $rows[] = [$row->entity_id, $row->bundle];
        }
        $this->io()->table(['Entity ID', 'Bundle'], $rows);
        $issues += count($orphaned);
      }
    }

    // 2. Check for imported nodes missing their body or title.
    if ($has_hash) {
      $incomplete = $this->database->query("
        SELECT n.nid, n.type, n.title
        FROM {node_field_data} n
        INNER JOIN {node__field_content_hash} h ON h.entity_id = n.nid
        LEFT JOIN {node__body} b ON b.entity_id = n.nid
        WHERE (n.title IS NULL OR n.title = '')
           OR (b.entity_id IS NULL AND n.type NOT IN ('page'))
      ")->fetchAll();

      if (!empty($incomplete)) {
        $this->io()->warning(count($incomplete) . ' imported node(s) with missing title or body:');
        $rows = [];
        foreach ($incomplete as $row) {
          $label = $bundles[$row->type]['label'] ?? $row->type;
          $rows[] = [$row->nid, $label, $row->title ?: '(empty)'];
        }
        $this->io()->table(['NID', 'Type', 'Title'], $rows);
        $issues += count($incomplete);
      }
    }

    if ($issues === 0) {
      $this->io()->text('No orphaned content found.');
    }

    return $issues;
  }

  /**
   * Scan content fields for broken internal links.
   */
  protected function checkBrokenInternalLinks(array $bundles, int $days, bool $fix): int {
    $this->io()->title('Broken Internal Links');

    // Gather all text fields that might contain links.
    $text_tables = [];
    $field_storage_configs = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'type' => 'text_long',
      ]);

    foreach ($field_storage_configs as $field_storage) {
      $field_name = $field_storage->getName();
      $table = 'node__' . $field_name;
      if ($this->database->schema()->tableExists($table)) {
        $text_tables[$table] = $field_name . '_value';
      }
    }

    // Also check text_with_summary (body, etc).
    $summary_fields = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'type' => 'text_with_summary',
      ]);

    foreach ($summary_fields as $field_storage) {
      $field_name = $field_storage->getName();
      $table = 'node__' . $field_name;
      if ($this->database->schema()->tableExists($table)) {
        $text_tables[$table] = $field_name . '_value';
      }
    }

    if (empty($text_tables)) {
      $this->io()->text('No text fields found to scan.');
      return 0;
    }

    $broken = [];

    $this->io()->text('Scanning text fields for internal links...');
    $progress_count = 0;

    foreach ($text_tables as $table => $column) {
      $rows = $this->database->query("
        SELECT entity_id, bundle, $column AS content
        FROM {{$table}}
        WHERE $column LIKE '%/node/%'
          OR $column LIKE '%entity:node/%'
      ")->fetchAll();

      foreach ($rows as $row) {
        $progress_count++;
        if ($progress_count % 100 === 0) {
          $this->io()->text("  Scanned $progress_count fields...");
        }

        // Check /node/NID and entity:node/NID references.
        if (preg_match_all('/(?:\/node\/|entity:node\/)(\d+)/', $row->content, $matches)) {
          foreach (array_unique($matches[1]) as $target_nid) {
            $exists = $this->database->query(
              "SELECT nid FROM {node} WHERE nid = :nid",
              [':nid' => $target_nid]
            )->fetchField();

            if (!$exists) {
              $broken[] = [
                'source_nid' => $row->entity_id,
                'source_bundle' => $row->bundle,
                'target' => "node/$target_nid",
                'field' => str_replace('_value', '', $column),
              ];
            }
          }
        }
      }
    }

    // Also check entity reference fields for dangling references.
    $this->io()->text('Checking entity reference fields...');
    $er_fields = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'type' => 'entity_reference',
      ]);

    foreach ($er_fields as $field_storage) {
      $settings = $field_storage->getSettings();
      if (($settings['target_type'] ?? '') !== 'node') {
        continue;
      }
      $field_name = $field_storage->getName();
      $table = 'node__' . $field_name;
      $target_col = $field_name . '_target_id';

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $dangling = $this->database->query("
        SELECT f.entity_id, f.bundle, f.$target_col AS target_nid
        FROM {{$table}} f
        LEFT JOIN {node} n ON n.nid = f.$target_col
        WHERE n.nid IS NULL
      ")->fetchAll();

      foreach ($dangling as $row) {
        $broken[] = [
          'source_nid' => $row->entity_id,
          'source_bundle' => $row->bundle,
          'target' => "node/{$row->target_nid} (entity ref)",
          'field' => $field_name,
        ];
      }
    }

    // Check link fields too.
    $link_fields = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'type' => 'link',
      ]);

    foreach ($link_fields as $field_storage) {
      $field_name = $field_storage->getName();
      $table = 'node__' . $field_name;
      $uri_col = $field_name . '_uri';

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $results = $this->database->query("
        SELECT f.entity_id, f.bundle, f.$uri_col AS uri
        FROM {{$table}} f
        WHERE f.$uri_col LIKE 'entity:node/%'
          OR f.$uri_col LIKE 'internal:/node/%'
      ")->fetchAll();

      foreach ($results as $row) {
        if (preg_match('/(\d+)/', $row->uri, $m)) {
          $exists = $this->database->query(
            "SELECT nid FROM {node} WHERE nid = :nid",
            [':nid' => $m[1]]
          )->fetchField();

          if (!$exists) {
            $broken[] = [
              'source_nid' => $row->entity_id,
              'source_bundle' => $row->bundle,
              'target' => "node/{$m[1]} (link field)",
              'field' => $field_name,
            ];
          }
        }
      }
    }

    if (empty($broken)) {
      $this->io()->text('No broken internal links found.');
      return 0;
    }

    $rows = [];
    foreach ($broken as $item) {
      $label = $bundles[$item['source_bundle']]['label'] ?? $item['source_bundle'];
      $rows[] = [
        $item['source_nid'],
        $label,
        $item['field'],
        $item['target'],
      ];
    }

    $this->io()->table(['Source NID', 'Type', 'Field', 'Broken Target'], $rows);
    $this->io()->warning(count($broken) . ' broken link(s) found.');

    return count($broken);
  }

  /**
   * Check for duplicate content hashes (possible duplication bugs).
   */
  protected function checkDuplicateHashes(array $bundles, int $days, bool $fix): int {
    $this->io()->title('Duplicate Content Hashes');

    if (!$this->database->schema()->tableExists('node__field_content_hash')) {
      $this->io()->text('field_content_hash not found on this site.');
      return 0;
    }

    $dupes = $this->database->query("
      SELECT h.field_content_hash_value AS hash, COUNT(*) AS count
      FROM {node__field_content_hash} h
      WHERE h.field_content_hash_value IS NOT NULL
        AND h.field_content_hash_value != ''
      GROUP BY h.field_content_hash_value
      HAVING COUNT(*) > 1
      ORDER BY count DESC
    ")->fetchAll();

    if (empty($dupes)) {
      $this->io()->text('No duplicate hashes found.');
      return 0;
    }

    $issue_count = 0;
    foreach ($dupes as $dupe) {
      $nodes = $this->database->query("
        SELECT n.nid, n.type, n.title, n.status, n.changed
        FROM {node_field_data} n
        INNER JOIN {node__field_content_hash} h ON h.entity_id = n.nid
        WHERE h.field_content_hash_value = :hash
        ORDER BY n.changed DESC
      ", [':hash' => $dupe->hash])->fetchAll();

      $this->io()->text("Hash: {$dupe->hash} ({$dupe->count} nodes)");
      $rows = [];
      foreach ($nodes as $node) {
        $label = $bundles[$node->type]['label'] ?? $node->type;
        $rows[] = [
          $node->nid,
          $label,
          mb_substr($node->title, 0, 50),
          $node->status ? 'Published' : 'Unpublished',
          date('Y-m-d H:i', $node->changed),
        ];
      }
      $this->io()->table(['NID', 'Type', 'Title', 'Status', 'Last Changed'], $rows);
      // Count extras beyond the first.
      $issue_count += $dupe->count - 1;
    }

    $this->io()->warning("$issue_count duplicate node(s) found across " . count($dupes) . " hash(es).");

    return $issue_count;
  }

  /**
   * Check for shared content that is unexpectedly unpublished.
   */
  protected function checkUnexpectedUnpublished(array $bundles, int $days, bool $fix): int {
    $this->io()->title('Unexpectedly Unpublished Shared Content');

    $has_hash = $this->database->schema()->tableExists('node__field_content_hash');

    if (!$has_hash) {
      $this->io()->text('No shared_content fields found on this site.');
      return 0;
    }

    // Find imported nodes that are unpublished — these are usually expected
    // to be published since they come from a curated feed.
    $unpublished = $this->database->query("
      SELECT n.nid, n.type, n.title, n.changed,
        h.field_content_hash_value AS hash
      FROM {node_field_data} n
      INNER JOIN {node__field_content_hash} h ON h.entity_id = n.nid
      WHERE n.status = 0
      ORDER BY n.changed DESC
    ")->fetchAll();

    if (empty($unpublished)) {
      $this->io()->text('No unexpectedly unpublished shared content found.');
      return 0;
    }

    $rows = [];
    foreach ($unpublished as $node) {
      $label = $bundles[$node->type]['label'] ?? $node->type;
      $rows[] = [
        $node->nid,
        $label,
        mb_substr($node->title, 0, 50),
        date('Y-m-d H:i', $node->changed),
        mb_substr($node->hash, 0, 16) . '...',
      ];
    }

    $this->io()->table(['NID', 'Type', 'Title', 'Last Changed', 'Hash'], $rows);
    $this->io()->warning(count($unpublished) . ' imported node(s) are unpublished.');

    return count($unpublished);
  }

}
