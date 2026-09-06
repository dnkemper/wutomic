<?php

namespace Drupal\shared_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\shared_content\Service\SharedContentFeedRefresherForce;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for shared_content routes.
 */
class SharedContentController extends ControllerBase {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The shared content feed refresher service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce
   */
  protected $feedRefresher;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SharedContentController object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\shared_content\Service\SharedContentFeedRefresherForce $feed_refresher
   *   The feed refresher service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    SharedContentFeedRefresherForce $feed_refresher,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    TranslationInterface $string_translation,
  ) {
    $this->loggerFactory = $logger_factory;
    $this->logger = $logger_factory->get('shared_content');
    $this->feedRefresher = $feed_refresher;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->setMessenger($messenger);
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('shared_content.feed_refresher_force'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('string_translation'),
    );
  }

  /**
   * Display aggregator items view.
   *
   * @return array
   *   Render array for the view.
   */
  public function display() {
    // Load and render the view programmatically.
    $view = $this->entityTypeManager
      ->getStorage('view')
      ->load('aggregator_item');

    if (!$view) {
      return [
        '#markup' => $this->t('View not found.'),
      ];
    }

    $view->setDisplay('page_1');
    $view->preExecute();
    $view->execute();

    return $view->render();
  }

  /**
   * Triggers a refresh of aggregator feeds for a given remote and content type.
   */
  public function triggerRefresh($remote, $content_type) {
    // Load feeds based on the custom field and content type.
    $query = $this->entityTypeManager->getStorage('feeds_feed')->getQuery()
      ->condition('field_feed_remote', $remote)
      ->condition('field_feed_content_type', $content_type)
      ->accessCheck(FALSE);

    $feed_ids = $query->execute();

    if (!empty($feed_ids)) {
      $feeds = $this->entityTypeManager->getStorage('feeds_feed')->loadMultiple($feed_ids);
      foreach ($feeds as $feed) {
        // Borrowed from FeedScheduleImportForm.
        /** @var \Drupal\aggregator\Entity\Feed $feed */
        if (!$feed->isLocked()) {
          $feed->startCronImport();
        }

        $args = [
          '@type'  => $feed->getType()->label(),
          '%title' => $feed->label(),
          '%remote' => $remote,
        ];
        $this->logger->notice('%remote queued import for %title of type @type.', $args);
      }
      return new JsonResponse(['status' => 'success']);
    }
    else {
      $args = [
        '%title' => $content_type,
        '%remote' => $remote,
      ];
      $this->logger->warning('%remote tried to queue import for %title but could not find feed.', $args);
    }
    return new JsonResponse(['status' => 'error']);
  }

  /**
   * Force update a single node from its XML source.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the node.
   */
  public function forceUpdate(NodeInterface $node) {
    $this->loggerFactory->get('shared_content');
    if (!$node->hasField('field_shared_content_xml') || $node->get('field_shared_content_xml')->isEmpty()) {
      $this->messenger()->addError($this->t('This node does not have a shared content XML source.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    try {
      // Clear the hash to force update.
      if ($node->hasField('field_content_hash')) {
        $node->set('field_content_hash', NULL);
        $node->save();
      }

      // Process the node. force_remap = TRUE bypasses both the conditional
      // request short-circuit (304 from upstream) and the local hash-equality
      // short-circuit, so the field mapper actually runs against the fetched
      // XML regardless of whether the source content is byte-identical.
      $this->feedRefresher->processNode($node->id(), TRUE);

      $this->messenger()->addStatus($this->t('Node @nid has been force updated from its XML source.', [
        '@nid' => $node->id(),
      ]));

      $this->logger->info('Force updated node @nid via direct URL.', [
        '@nid' => $node->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error updating node: @message', [
        '@message' => $e->getMessage(),
      ]));

      $this->logger->error('Force update failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    // Redirect back to the node.
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Displays the shared content admin page.
   *
   * @return array
   *   A render array.
   */
  public function adminPage() {
    $build = [];

    // Check if Aggregator is available.
    if (!$this->aggregatorTablesExist()) {
      // Show alternative import method.
      $build['no_aggregator'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];

      $build['no_aggregator']['message'] = [
        '#markup' => '<h3>' . $this->t('Aggregator Not Available') . '</h3>' .
        '<p>' . $this->t('The Aggregator module is not installed or configured. You can still import content using direct RSS URLs.') . '</p>',
      ];

      $build['no_aggregator']['link'] = [
        '#type' => 'link',
        '#title' => $this->t('Import from RSS URL →'),
        '#url' => Url::fromRoute('shared_content.direct_import'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];

      $build['setup_info'] = [
        '#type' => 'details',
        '#title' => $this->t('Set up Aggregator (Optional)'),
        '#open' => FALSE,
      ];

      $build['setup_info']['content'] = [
        '#markup' => '<p>' . $this->t('To use the Aggregator-based import:') . '</p>' .
        '<ol>' .
        '<li>' . $this->t('Check if Aggregator is available: <code>drush pm:list | grep aggregator</code>') . '</li>' .
        '<li>' . $this->t('If not available in Drupal 10 core, you may need a contrib alternative') . '</li>' .
        '<li>' . $this->t('See <a href="@url">DRUPAL_10_AGGREGATOR_FIX.md</a> for details', [
          '@url' => 'https://github.com/your-repo/shared_content/blob/main/DRUPAL_10_AGGREGATOR_FIX.md',
        ]) . '</li>' .
        '</ol>' .
        '<p><strong>' . $this->t('Direct RSS import works without Aggregator!') . '</strong></p>',
      ];

      return $build;
    }

    // Aggregator is available - show standard forms.
    $build['update_form'] = $this->formBuilder()->getForm(
      'Drupal\shared_content\Form\SharedContentUpdateForm'
    );

    $build['filter_form'] = $this->formBuilder()->getForm(
      'Drupal\shared_content\Form\SharedContentFilterForm'
    );

    $build['import_form'] = $this->formBuilder()->getForm(
      'Drupal\shared_content\Form\SharedContentImportForm'
    );

    // Add link to direct import as alternative.
    $build['direct_import_link'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['direct-import-link']],
      '#weight' => -10,
    ];

    $build['direct_import_link']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Or import directly from RSS URL →'),
      '#url' => Url::fromRoute('shared_content.direct_import'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

  /**
   * Checks if Aggregator module tables exist.
   */
  protected function aggregatorTablesExist() {
    try {
      return $this->database->schema()->tableExists('aggregator_feed') &&
             $this->database->schema()->tableExists('aggregator_item');
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Force refreshes all shared content nodes via Batch API.
   *
   * Replaces the old synchronous run(TRUE) call so that sites with hundreds
   * of shared content nodes don't hit PHP's max_execution_time. Each batch
   * operation processes a small chunk of nodes, giving the user a progress
   * bar and preventing timeouts.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A batch redirect response, or a plain redirect if no nodes are eligible.
   */
  public function forceRefreshAll(Request $request): array|RedirectResponse {
    $destination = $request->query->get('destination') ?? '/admin/shared-content';

    // force_all = TRUE so every node is eligible regardless of last fetch time.
    $nids = array_values($this->feedRefresher->getEligibleNodeIds(TRUE));

    if (empty($nids)) {
      $this->messenger()->addWarning($this->t('No shared content nodes found to sync.'));
      return new RedirectResponse($destination);
    }

    $operations = [];

    // Process in chunks of 10 — tune this if needed.
    foreach (array_chunk($nids, 10) as $chunk) {
      $operations[] = [
        [static::class, 'batchForceRefreshChunk'],
        [$chunk],
      ];
    }

    // No separate "check for deleted sources" step needed here —
    // processNode() already deletes nodes immediately when it encounters
    // a 404 or empty XML response during each chunk above.
    $batch = [
      'title' => $this->t('Syncing shared content…'),
      'operations' => $operations,
      'finished' => [static::class, 'batchForceRefreshFinished'],
      'init_message' => $this->t('Starting content sync for @count nodes…', ['@count' => count($nids)]),
      'progress_message' => $this->t('Processing batch @current of @total.'),
      'error_message' => $this->t('An error occurred while syncing content.'),
    ];

    batch_set($batch);

    return batch_process($destination);
  }

  /**
   * Batch operation: processes a chunk of shared content nodes.
   *
   * @param int[] $nids
   *   Node IDs to process in this batch operation.
   * @param array $context
   *   The batch context array, passed by reference.
   */
  public static function batchForceRefreshChunk(array $nids, array &$context): void {
    /** @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce $refresher */
    $refresher = \Drupal::service('shared_content.feed_refresher_force');

    foreach ($nids as $nid) {
      try {
        // force_remap = TRUE so each node actually re-runs the field mapper
        // even when the upstream feed hasn't changed since the last fetch.
        $refresher->processNode($nid, TRUE);
        $context['results']['processed'] = ($context['results']['processed'] ?? 0) + 1;
      }
      catch (\Exception $e) {
        $context['results']['errors'] = ($context['results']['errors'] ?? 0) + 1;
        \Drupal::logger('shared_content')->error(
          'Batch sync error on node @nid: @message',
          ['@nid' => $nid, '@message' => $e->getMessage()]
        );
      }
    }

    // Use TranslatableMarkup directly in static context.
    $context['message'] = new TranslatableMarkup('Synced @count nodes…', [
      '@count' => $context['results']['processed'] ?? 0,
    ]);
  }

  /**
   * Batch operation: checks for and removes deleted/gone source nodes.
   *
   * Runs as the final batch operation so all active nodes are processed
   * before we check for stale ones.
   *
   * @param array $context
   *   The batch context array, passed by reference.
   */
  public static function batchProcessDeletedNodes(array &$context): void {
    $context['message'] = new TranslatableMarkup('Checking for removed sources…');
    \Drupal::service('shared_content.feed_refresher_force')->processDeletedNodes();
  }

  /**
   * Batch finished callback for forceRefreshAll.
   *
   * @param bool $success
   *   TRUE if the batch completed without a fatal error.
   * @param array $results
   *   Accumulated results from each batch operation.
   * @param array $operations
   *   Any unprocessed operations (only set on failure).
   */
  public static function batchForceRefreshFinished(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(new TranslatableMarkup('Content sync did not complete — check recent log messages for details.'));
      return;
    }

    $processed = $results['processed'] ?? 0;
    $errors = $results['errors'] ?? 0;

    \Drupal::messenger()->addStatus(new TranslatableMarkup(
      'Synced @count node(s). @errors error(s) — check logs for details.',
      ['@count' => $processed, '@errors' => $errors]
    ));
  }

  /**
   * Refreshes all aggregator feeds using the Batch API and redirects back.
   *
   * Each feed is processed in its own batch operation so that PHP's max
   * execution time is not exceeded when many feeds are configured.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A batch redirect response, or a plain redirect if no feeds exist.
   */
  public function refreshAllFeeds(Request $request): array|RedirectResponse {
    $fids = $this->entityTypeManager->getStorage('aggregator_feed')->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    $destination = $request->query->get('destination') ?? '/admin/shared-content';

    if (empty($fids)) {
      $this->messenger()->addWarning(new TranslatableMarkup('No aggregator feeds found.'));
      return new RedirectResponse($destination);
    }

    $operations = [];
    foreach ($fids as $fid) {
      $operations[] = [
        [static::class, 'batchRefreshFeed'],
        [$fid],
      ];
    }

    $batch = [
      'title' => $this->t('Refreshing aggregator feeds…'),
      'operations' => $operations,
      'finished' => [static::class, 'batchRefreshFinished'],
      'init_message' => $this->t('Starting feed refresh…'),
      'progress_message' => $this->t('Processing feed @current of @total.'),
      'error_message' => $this->t('An error occurred while refreshing feeds.'),
    ];

    batch_set($batch);

    return batch_process($destination);
  }

  /**
   * Batch operation: refreshes a single aggregator feed.
   *
   * Declared static so it can be used as a batch callback without needing
   * the controller to be instantiated by the batch system.
   *
   * @param int $fid
   *   The aggregator feed ID to refresh.
   * @param array $context
   *   The batch context array, passed by reference.
   */
  public static function batchRefreshFeed(int $fid, array &$context): void {
    $feed = \Drupal::entityTypeManager()
      ->getStorage('aggregator_feed')
      ->load($fid);

    if (!$feed) {
      $context['results']['errors'][] = "Feed ID $fid not found.";
      return;
    }

    try {
      $feed->refreshItems();
      $context['results']['count'] = ($context['results']['count'] ?? 0) + 1;
      $context['message'] = new TranslatableMarkup('Refreshed: @title', ['@title' => $feed->label()]);
    }
    catch (\Exception $e) {
      \Drupal::logger('shared_content')->error('Error refreshing feed @title: @message', [
        '@title' => $feed->label(),
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors'][] = $feed->label();
    }
  }

  /**
   * Batch finished callback: shows a summary message to the user.
   *
   * @param bool $success
   *   TRUE if the batch completed without a fatal PHP error.
   * @param array $results
   *   Results accumulated from each batchRefreshFeed() call.
   * @param array $operations
   *   Any operations that were not processed (only set on failure).
   */
  public static function batchRefreshFinished(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(new TranslatableMarkup('Feed refresh did not complete — check the recent log messages for details.'));
      return;
    }

    $count = $results['count'] ?? 0;
    $errors = $results['errors'] ?? [];

    if ($count > 0) {
      \Drupal::messenger()->addStatus(new TranslatableMarkup('Pulled latest items from @count aggregator feed(s).', ['@count' => $count]));
    }

    if (!empty($errors)) {
      \Drupal::messenger()->addWarning(new TranslatableMarkup('@count feed(s) failed — check recent log messages for details: @feeds', [
        '@count' => count($errors),
        '@feeds' => implode(', ', $errors),
      ]));
    }
  }

}
