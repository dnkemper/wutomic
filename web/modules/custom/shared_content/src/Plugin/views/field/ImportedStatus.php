<?php

namespace Drupal\shared_content\Plugin\views\field;

use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Defines a field to show the imported status of an aggregator item.
 *
 * @ViewsField("imported_status")
 */
class ImportedStatus extends FieldPluginBase {

  /**
   * Pre-built map of shared_content_xml URL => [nid, title].
   *
   * Built once in preRender() via a single DB query, then looked up per row
   * in render(). Avoids N entity queries + N node loads per page.
   *
   * @var array
   */
  protected array $importedUrlMap = [];

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query alterations needed — we pre-fetch in preRender().
  }

  /**
   * {@inheritdoc}
   *
   * Pre-fetches all imported node data for the current page in one query.
   */
  public function preRender(&$values) {
    // Build the list of shared URLs for all rows on this page.
    $urls = [];
    foreach ($values as $row) {
      $url = $this->buildSharedUrl($row);
      if ($url) {
        $urls[] = $url;
      }
    }

    if (empty($urls)) {
      return;
    }

    // Single query: find all nodes whose field_shared_content_xml matches
    // any URL on this page.
    $results = \Drupal::database()->select('node__field_shared_content_xml', 'f')
      ->fields('f', ['field_shared_content_xml_value', 'entity_id'])
      ->condition('f.field_shared_content_xml_value', $urls, 'IN')
      ->execute()
      ->fetchAllKeyed();
    // $results is [url => nid].
    if (empty($results)) {
      return;
    }

    // Single query: get titles for all matching nodes.
    $nids = array_values($results);
    $titles = \Drupal::database()->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title'])
      ->condition('n.nid', $nids, 'IN')
      ->execute()
      ->fetchAllKeyed();
    // $titles is [nid => title].
    // Build the lookup map: url => [nid, title].
    foreach ($results as $url => $nid) {
      $this->importedUrlMap[$url] = [
        'nid' => $nid,
        'title' => $titles[$nid] ?? '',
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $url = $this->buildSharedUrl($values);
    if (!$url || !isset($this->importedUrlMap[$url])) {
      return ['#markup' => ''];
    }

    $nid = $this->importedUrlMap[$url]['nid'];
    $title = $this->importedUrlMap[$url]['title'];

    if (!$nid || !$title) {
      return ['#markup' => ''];
    }

    $link = Link::fromTextAndUrl(
      $title,
      Url::fromRoute('entity.node.canonical', ['node' => $nid])
    );

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['imported-status']],
      'link' => $link->toRenderable(),
    ];
  }

  /**
   * Builds the shared XML URL for a result row.
   *
   * @return string|null
   *   The shared XML URL, or NULL if unavailable.
   */
  protected function buildSharedUrl(ResultRow $row): ?string {
    $entity = $row->_entity ?? NULL;
    if (!$entity) {
      return NULL;
    }

    $guid = $entity->get('guid')->value ?? '';
    $guid_parts = explode(' at ', $guid);
    if (count($guid_parts) !== 2) {
      return NULL;
    }

    [$node_id, $base_url] = $guid_parts;

    $fid_entity = $entity->get('fid')->entity ?? NULL;
    if (!$fid_entity) {
      return NULL;
    }

    $fid_title = $fid_entity->label();
    $fid_parts = explode(' ', $fid_title);
    $content_type = $this->getContentType($fid_parts[1] ?? '');
    if (empty($content_type)) {
      return NULL;
    }

    return $base_url . '/xml/' . strtolower($content_type) . '/' . $node_id . '/rss.xml';
  }

  /**
   * Helper to determine content type from feed title word.
   */
  protected function getContentType(string $fid_part): string {
    return match ($fid_part) {
      'Articles' => 'article',
      'Events' => 'event',
      'Person' => 'person',
      'Books' => 'book',
      default => '',
    };
  }

}
