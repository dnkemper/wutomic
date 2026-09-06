<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Drush commands for shared content migration control.
 */
class SharedContentMigrationCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Constructs a new SharedContentMigrationCommands object.
   */
  public function __construct(
    Connection $database,
    StateInterface $state,
    ClientInterface $http_client,
  ) {
    parent::__construct();
    $this->database = $database;
    $this->state = $state;
    $this->httpClient = $http_client;
  }

  /**
   * Pause shared content cron processing.
   *
   * @command shared-content:pause
   * @aliases sc-pause
   */
  public function pause(): void {
    $this->state->set('shared_content.migration_pause', TRUE);
    $this->state->set('shared_content.migration_pause_time', \Drupal::time()->getRequestTime());
    $this->logger()->success('Shared content cron paused.');
  }

  /**
   * Force refresh all feeds after migration.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:refresh-all
   * @aliases sc-refresh-all
   * @option batch-size Number of items per batch.
   * @option passes Number of passes to run (0 = until complete).
   */
  public function refreshAll(array $options = ['batch-size' => 100, 'passes' => 0]): void {
    if ($this->state->get('shared_content.migration_pause', FALSE)) {
      $this->logger()->error('Cannot refresh while migration is paused. Run sc-resume first.');
      return;
    }

    /** @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce $refresher */
    $refresher = \Drupal::service('shared_content.feed_refresher_force');
    $batch_size = (int) $options['batch-size'];
    $max_passes = (int) $options['passes'];

    $pass = 0;
    $total_processed = 0;

    while (TRUE) {
      $pass++;
      $count = $refresher->runChunked($batch_size);
      $total_processed += $count;

      $this->output()->writeln(dt('  Pass @pass: processed @count items (@total total)', [
        '@pass' => $pass,
        '@count' => $count,
        '@total' => $total_processed,
      ]));

      // Stop if nothing left to process.
      if ($count === 0) {
        $this->logger()->success(dt('Complete. Processed @total items in @passes passes.', [
          '@total' => $total_processed,
          '@passes' => $pass,
        ]));
        break;
      }

      // Stop if we hit max passes.
      if ($max_passes > 0 && $pass >= $max_passes) {
        $this->logger()->notice(dt('Stopped after @passes passes. @total items processed. Run again to continue.', [
          '@passes' => $pass,
          '@total' => $total_processed,
        ]));
        break;
      }

      // Brief pause to avoid hammering the server.
      // 0.5 seconds.
      usleep(500000);
    }
  }

  /**
   * Resume shared content cron processing.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:resume
   * @aliases sc-resume
   * @option force Resume even if feed verification fails.
   * @option skip-verify Skip feed URL verification entirely.
   * @option sample Number of random feeds to check (default: check all).
   */
  public function resume(
    array $options = [
      'force' => FALSE,
      'skip-verify' => FALSE,
      'sample' => NULL,
    ],
  ): void {
    if (!$this->state->get('shared_content.migration_pause', FALSE)) {
      $this->logger()->warning('Shared content cron is not paused.');
      return;
    }

    if (!$options['skip-verify']) {
      $sample_size = $options['sample'] ? (int) $options['sample'] : NULL;

      if ($sample_size) {
        $this->output()->writeln(dt('Verifying @count random feed URLs before resuming...', [
          '@count' => $sample_size,
        ]));
      }
      else {
        $this->output()->writeln('Verifying all feed URLs before resuming...');
      }
      $this->output()->writeln('');

      $results = $this->verifyFeedUrls(10, FALSE, $sample_size);

      if ($results['failed'] > 0) {
        $this->logger()->error(dt('@failed of @checked feed URLs are unreachable.', [
          '@failed' => $results['failed'],
          '@checked' => $results['checked'],
        ]));

        if (!$options['force']) {
          $this->logger()->error('Use --force to resume anyway, or wait for DNS propagation.');
          return;
        }

        $this->logger()->warning('Resuming anyway due to --force flag.');
      }
      else {
        if ($sample_size && $results['checked'] < $results['total']) {
          $this->logger()->success(dt('All @checked sampled feed URLs are reachable (@total total feeds).', [
            '@checked' => $results['checked'],
            '@total' => $results['total'],
          ]));
        }
        else {
          $this->logger()->success(dt('All @total feed URLs are reachable.', [
            '@total' => $results['total'],
          ]));
        }
      }
    }

    $this->state->delete('shared_content.migration_pause');
    $this->state->delete('shared_content.migration_pause_time');
    $this->logger()->success('Shared content cron resumed.');
  }

  /**
   * Check migration pause status and feed health.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:status
   * @aliases sc-status
   * @option verify Also verify feed URLs are reachable.
   * @option sample Number of random feeds to check (default: check all).
   */
  public function status(array $options = ['verify' => FALSE, 'sample' => NULL]): void {
    $paused = $this->state->get('shared_content.migration_pause', FALSE);
    $pause_time = $this->state->get('shared_content.migration_pause_time');

    if ($paused) {
      $this->output()->writeln('<comment>Status: PAUSED</comment>');

      if ($pause_time) {
        $duration = \Drupal::time()->getRequestTime() - $pause_time;
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $this->output()->writeln(dt('Paused for: @hours hours, @minutes minutes', [
          '@hours' => $hours,
          '@minutes' => $minutes,
        ]));
      }
    }
    else {
      $this->output()->writeln('<info>Status: Running normally</info>');
    }

    // Show feed count.
    $feed_count = $this->database->select('aggregator_feed', 'f')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->output()->writeln(dt('Total aggregator feeds: @count', ['@count' => $feed_count]));

    if ($options['verify']) {
      $this->output()->writeln('');
      $sample_size = $options['sample'] ? (int) $options['sample'] : NULL;
      $this->verifyFeeds([
        'show-all' => FALSE,
        'timeout' => 10,
        'sample' => $sample_size,
      ]);
    }
  }

  /**
   * Verify all feed URLs are reachable.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:verify-feeds
   * @aliases sc-verify
   * @option show-all Show all feeds, not just failures.
   * @option timeout HTTP timeout in seconds.
   * @option sample Number of random feeds to check (default: check all).
   */
  public function verifyFeeds(
    array $options = [
      'show-all' => FALSE,
      'timeout' => 10,
      'sample' => NULL,
    ],
  ): void {
    $sample_size = $options['sample'] ? (int) $options['sample'] : NULL;
    $results = $this->verifyFeedUrls($options['timeout'], $options['show-all'], $sample_size);

    $this->output()->writeln('');

    if ($sample_size && $results['checked'] < $results['total']) {
      $this->output()->writeln(dt('Results: @passed passed, @failed failed (@checked of @total feeds sampled)', [
        '@passed' => $results['passed'],
        '@failed' => $results['failed'],
        '@checked' => $results['checked'],
        '@total' => $results['total'],
      ]));
    }
    else {
      $this->output()->writeln(dt('Results: @passed passed, @failed failed, @total total', [
        '@passed' => $results['passed'],
        '@failed' => $results['failed'],
        '@total' => $results['total'],
      ]));
    }

    if ($results['failed'] > 0) {
      $this->output()->writeln('');
      $this->logger()->warning('Some feeds are unreachable. DNS may not have propagated yet.');
    }
  }

  /**
   * Check for any remaining wustl.edu URLs in the database.
   *
   * @command shared-content:audit-urls
   * @aliases sc-audit
   */
  public function auditUrls(): void {
    $this->output()->writeln('Scanning for remaining .wustl.edu URLs...');
    $this->output()->writeln('');

    $tables_to_check = [
      ['aggregator_feed', 'url'],
      ['aggregator_feed', 'link'],
      ['aggregator_item', 'link'],
      ['aggregator_item', 'guid'],
      ['shared_content_items', 'url'],
      ['menu_link_content_data', 'link__uri'],
      ['redirect', 'redirect_redirect__uri'],
      ['file_managed', 'uri'],
    ];

    $found = FALSE;

    foreach ($tables_to_check as [$table, $column]) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      if (!$this->database->schema()->fieldExists($table, $column)) {
        continue;
      }

      try {
        $count = $this->database->select($table, 't')
          ->condition($column, '%.wustl.edu%', 'LIKE')
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($count > 0) {
          $found = TRUE;
          $this->output()->writeln("<error>  ✗ $table.$column: $count remaining</error>");

          // Show sample values.
          $samples = $this->database->select($table, 't')
            ->fields('t', [$column])
            ->condition($column, '%.wustl.edu%', 'LIKE')
            ->range(0, 3)
            ->execute()
            ->fetchCol();

          foreach ($samples as $sample) {
            $this->output()->writeln("      → $sample");
          }
        }
      }
      catch (\Exception $e) {
        // Skip tables we can't query.
      }
    }

    if (!$found) {
      $this->logger()->success('No .wustl.edu URLs found in checked tables.');
    }
  }

  /**
   * Quick spot check of a few random feeds.
   *
   * @param int $count
   *   Number of feeds to check.
   * @param array $options
   *   Command options.
   *
   * @command shared-content:spot-check
   * @aliases sc-spot
   * @option timeout HTTP timeout in seconds.
   */
  public function spotCheck(int $count = 5, array $options = ['timeout' => 5]): void {
    $this->output()->writeln(dt('Quick spot check of @count random feeds...', ['@count' => $count]));
    $this->output()->writeln('');

    $results = $this->verifyFeedUrls($options['timeout'], TRUE, $count);

    $this->output()->writeln('');

    if ($results['failed'] === 0) {
      $this->logger()->success(dt('All @count sampled feeds OK.', ['@count' => $results['checked']]));
    }
    else {
      $this->logger()->warning(dt('@failed of @count sampled feeds failed.', [
        '@failed' => $results['failed'],
        '@count' => $results['checked'],
      ]));
    }
  }

  /**
   * Helper to verify feed URLs.
   *
   * @param int $timeout
   *   HTTP timeout in seconds.
   * @param bool $show_all
   *   Whether to show all results or just failures.
   * @param int|null $sample_size
   *   Number of random feeds to check, or NULL for all.
   *
   * @return array
   *   Results array with 'passed', 'failed', 'checked', 'total' counts.
   */
  protected function verifyFeedUrls(int $timeout = 10, bool $show_all = FALSE, ?int $sample_size = NULL): array {
    $query = $this->database->select('aggregator_feed', 'f')
      ->fields('f', ['fid', 'title', 'url']);

    // Get total count first.
    $total = $this->database->select('aggregator_feed', 'f')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Apply random sampling if requested.
    if ($sample_size && $sample_size < $total) {
      $query->orderRandom();
      $query->range(0, $sample_size);
    }

    $feeds = $query->execute()->fetchAll();

    $results = [
      'passed' => 0,
      'failed' => 0,
      'checked' => count($feeds),
      'total' => (int) $total,
      'failures' => [],
    ];

    foreach ($feeds as $feed) {
      $status = $this->checkUrl($feed->url, $timeout);

      if ($status['reachable']) {
        $results['passed']++;
        if ($show_all) {
          $this->output()->writeln(dt('<info>  ✓ @title</info>', [
            '@title' => $feed->title,
          ]));
          $this->output()->writeln("      {$feed->url}");
        }
      }
      else {
        $results['failed']++;
        $results['failures'][] = $feed;
        $this->output()->writeln(dt('<error>  ✗ @title</error>', [
          '@title' => $feed->title,
        ]));
        $this->output()->writeln("      {$feed->url}");
        $this->output()->writeln("      Error: {$status['error']}");
      }
    }

    return $results;
  }

  /**
   * Check if a URL is reachable.
   *
   * @param string $url
   *   The URL to check.
   * @param int $timeout
   *   Timeout in seconds.
   *
   * @return array
   *   Array with 'reachable' bool and 'error' message.
   */
  protected function checkUrl(string $url, int $timeout = 10): array {
    try {
      $response = $this->httpClient->request('HEAD', $url, [
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'allow_redirects' => TRUE,
        'http_errors' => FALSE,
      ]);

      $code = $response->getStatusCode();

      // Accept 2xx and 3xx as success.
      if ($code >= 200 && $code < 400) {
        return ['reachable' => TRUE, 'error' => NULL];
      }

      return [
        'reachable' => FALSE,
        'error' => "HTTP $code",
      ];
    }
    catch (RequestException $e) {
      $message = $e->getMessage();

      // Clean up common Guzzle error messages.
      if (str_contains($message, 'Could not resolve host')) {
        $message = 'DNS resolution failed';
      }
      elseif (str_contains($message, 'Connection timed out')) {
        $message = 'Connection timed out';
      }
      elseif (str_contains($message, 'Connection refused')) {
        $message = 'Connection refused';
      }

      return [
        'reachable' => FALSE,
        'error' => $message,
      ];
    }
    catch (\Exception $e) {
      return [
        'reachable' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

}
