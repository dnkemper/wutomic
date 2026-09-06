<?php

namespace Drupal\shared_content\Batch;

/**
 * Batch operations for shared content refresh.
 */
class SharedContentRefreshBatch {

  /**
   * Process a chunk of node IDs.
   *
   * @param array $nids
   *   Array of node IDs to process.
   * @param array $context
   *   Batch context.
   */
  public static function processChunk(array $nids, array &$context): void {
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
      $context['results']['errors'] = 0;
    }

    $refresher = \Drupal::service('shared_content.feed_refresher_force');

    foreach ($nids as $nid) {
      try {
        // force_remap = TRUE so each node actually re-runs the field mapper
        // even when the upstream feed hasn't changed since the last fetch.
        $refresher->processNode($nid, TRUE);
        $context['results']['processed']++;
        $context['message'] = t('Processed node @nid', ['@nid' => $nid]);
      }
      catch (\Exception $e) {
        $context['results']['errors']++;
        \Drupal::logger('shared_content')->error('Error processing node @nid: @message', [
          '@nid' => $nid,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Batch finished callback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $processed = $results['processed'] ?? 0;
    $errors = $results['errors'] ?? 0;

    if ($success) {
      \Drupal::messenger()->addStatus(t('Refreshed @count nodes (@errors errors).', [
        '@count' => $processed,
        '@errors' => $errors,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('Batch encountered an error.'));
    }
  }

}
