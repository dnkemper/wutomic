<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for site content reporting.
 */
final class ContentReportCommands extends DrushCommands {

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
   * Display a content report with node counts, imported, and shared content.
   */
  #[CLI\Command(name: 'shared_content:report', aliases: ['sc:report'])]
  #[CLI\Usage(name: 'drush shared_content:report', description: 'Print the full site content report.')]
  #[CLI\Usage(name: 'drush sc:report', description: 'Short alias for the report.')]
  public function report(): void {
    $bundles = $this->bundleInfo->getBundleInfo('node');

    $this->sectionNodeCounts($bundles);
    $this->sectionImportedContent($bundles);
    $this->sectionSharedContent($bundles);

    $this->io()->text('Report generated: ' . date('Y-m-d H:i:s'));
  }

  /**
   * Print node counts per content type.
   */
  protected function sectionNodeCounts(array $bundles): void {
    $results = $this->database->query("
      SELECT type, COUNT(*) AS count
      FROM {node_field_data}
      GROUP BY type
      ORDER BY count DESC
    ")->fetchAllKeyed();

    $total = array_sum($results);

    $this->io()->title('Node Counts by Content Type');

    $rows = [];
    foreach ($results as $type => $count) {
      $rows[] = [
        $bundles[$type]['label'] ?? $type,
        $type,
        number_format($count),
      ];
    }
    $rows[] = ['', 'TOTAL', number_format($total)];

    $this->io()->table(['Label', 'Machine Name', 'Count'], $rows);
  }

  /**
   * Print imported content counts (field_shared_content_xml or field_content_hash).
   */
  protected function sectionImportedContent(array $bundles): void {
    $has_xml = $this->database->schema()->tableExists('node__field_shared_content_xml');
    $has_hash = $this->database->schema()->tableExists('node__field_content_hash');

    $this->io()->title('Imported Content (shared_content)');

    if (!$has_xml && !$has_hash) {
      $this->io()->text('No shared_content fields found on this site.');
      return;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['type']);
    $query->addExpression('COUNT(*)', 'count');

    if ($has_xml && $has_hash) {
      $xml_sub = $this->database->select('node__field_shared_content_xml', 'x')
        ->fields('x', ['entity_id']);
      $hash_sub = $this->database->select('node__field_content_hash', 'h')
        ->fields('h', ['entity_id']);
      $xml_sub->union($hash_sub);
      $query->condition('n.nid', $xml_sub, 'IN');
    }
    elseif ($has_xml) {
      $query->join('node__field_shared_content_xml', 'x', 'n.nid = x.entity_id');
    }
    else {
      $query->join('node__field_content_hash', 'h', 'n.nid = h.entity_id');
    }

    $query->groupBy('n.type');
    $query->orderBy('count', 'DESC');
    $imported = $query->execute()->fetchAllKeyed();
    $total = array_sum($imported);

    $rows = [];
    foreach ($imported as $type => $count) {
      $rows[] = [
        $bundles[$type]['label'] ?? $type,
        $type,
        number_format($count),
      ];
    }
    $rows[] = ['', 'TOTAL', number_format($total)];

    $this->io()->table(['Label', 'Machine Name', 'Imported'], $rows);
  }

  /**
   * Print shared content counts (field_is_shared = 1).
   */
  protected function sectionSharedContent(array $bundles): void {
    $has_shared = $this->database->schema()->tableExists('node__field_is_shared');

    $this->io()->title('Shared Content (field_is_shared)');

    if (!$has_shared) {
      $this->io()->text('field_is_shared not found on this site.');
      return;
    }

    $query = $this->database->select('node__field_is_shared', 's');
    $query->join('node_field_data', 'n', 'n.nid = s.entity_id');
    $query->fields('n', ['type']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('s.field_is_shared_value', 1);
    $query->groupBy('n.type');
    $query->orderBy('count', 'DESC');
    $shared = $query->execute()->fetchAllKeyed();
    $total = array_sum($shared);

    $rows = [];
    foreach ($shared as $type => $count) {
      $rows[] = [
        $bundles[$type]['label'] ?? $type,
        $type,
        number_format($count),
      ];
    }
    $rows[] = ['', 'TOTAL', number_format($total)];

    $this->io()->table(['Label', 'Machine Name', 'Shared'], $rows);
  }

}
