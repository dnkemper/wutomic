<?php

namespace Drupal\shared_content\Drush\Commands;

use Drupal\shared_content\Form\RemoteForm;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Utility\Token;
use Drupal\Core\Messenger\MessengerInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Drupal\aggregator\Entity\Feed;
use Drupal\Core\Database\Connection;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Importer\ConfigImporterBatch;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Asset\AssetCollectionOptimizerInterface;
use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\shared_content\Service\SharedContentFeedRefresherForce;
use Drupal\Core\Queue\QueueWorkerManagerInterface;

/**
 * A Drush commandfile.
 */
final class SharedContentCommands extends DrushCommands {

  /**
   * The force feed refresher service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce
   */
  protected $feedRefresherForce;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorkerManager;

  /**
   * Constructs a SharedContentCommands object.
   */
  public function __construct(
    SharedContentFeedRefresherForce $feedRefresherForce,
    QueueFactory $queueFactory,
    QueueWorkerManagerInterface $queueWorkerManager,
    private readonly Token $token,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    private readonly ClientInterface $httpClient,
    private readonly Connection $connection,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly AssetCollectionOptimizerInterface $cssCollectionOptimizer,
    private readonly AssetCollectionOptimizerInterface $jsCollectionOptimizer,
    private readonly StorageInterface $activeStorage,
    private readonly StorageInterface $syncStorage,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ConfigManagerInterface $configManager,
    private readonly LockBackendInterface $lock,
    private readonly TypedConfigManagerInterface $typedConfig,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleInstallerInterface $moduleInstaller,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly TranslationInterface $stringTranslation,
    private readonly ModuleExtensionList $extensionListModule,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?AssetQueryStringInterface $assetQueryString = NULL,
  ) {
    parent::__construct();
    $this->feedRefresherForce = $feedRefresherForce;
    $this->queueFactory = $queueFactory;
    $this->queueWorkerManager = $queueWorkerManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    return new static(
      $container->get('shared_content.feed_refresher_force'),
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('token'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('http_client'),
      $container->get('database'),
      $container->get('cache_tags.invalidator'),
      $container->get('asset.css.collection_optimizer'),
      $container->get('asset.js.collection_optimizer'),
      $container->get('config.storage'),
      $container->get('config.storage.sync'),
      $container->get('event_dispatcher'),
      $container->get('config.manager'),
      $container->get('lock'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('theme_handler'),
      $container->get('string_translation'),
      $container->get('extension.list.module'),
      $container->get('extension.list.theme'),
      $container->get('config.factory'),
      $container->has('asset.query_string') ? $container->get('asset.query_string') : NULL,
    );
  }

  /**
   * Populate content hash for all existing shared content nodes.
   *
   * @command shared-content:populate-hashes
   * @aliases sc-populate-hash,sc-hash
   * @option dry-run Preview without saving.
   * @option type Limit to specific content type(s).
   * @usage shared-content:populate-hashes
   *   Calculate and save content hashes for all shared content nodes.
   * @usage shared-content:populate-hashes --dry-run
   *   Preview what would be updated without saving.
   * @usage shared-content:populate-hashes --type=article
   *   Only process article nodes.
   */
  public function populateHashes(array $options = ['dry-run' => FALSE, 'type' => NULL]) {
    $this->output()->writeln('Populating content hashes for shared content nodes...');

    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');

    if (!empty($options['type'])) {
      $types = array_map('trim', explode(',', $options['type']));
      $query->condition('type', $types, 'IN');
    }
    else {
      $query->condition('type', ['person', 'event', 'article', 'book'], 'IN');
    }

    $nids = $query->execute();

    if (empty($nids)) {
      $this->output()->writeln('No shared content nodes found.');
      return;
    }

    $total = count($nids);
    $this->output()->writeln("Found {$total} nodes to process.\n");

    $updated = 0;
    $skipped = 0;
    $errors = 0;

    $nodes = Node::loadMultiple($nids);

    foreach ($nodes as $node) {
      $nid = $node->id();
      $url = $node->get('field_shared_content_xml')->value;

      if (empty($url)) {
        $this->output()->writeln("  Node {$nid}: No XML URL, skipping");
        $skipped++;
        continue;
      }

      if ($node->hasField('field_content_hash') && !$node->get('field_content_hash')->isEmpty()) {
        $existing_hash = $node->get('field_content_hash')->value;
        $this->output()->writeln("  Node {$nid}: Hash already exists (" . substr($existing_hash, 0, 16) . "...)");
        $skipped++;
        continue;
      }

      try {
        $response = $this->httpClient->request('GET', $url, [
          'timeout' => 120,
          'http_errors' => FALSE,
        ]);

        if ($response->getStatusCode() !== 200) {
          $this->output()->writeln("  Node {$nid}: HTTP {$response->getStatusCode()} from {$url}");
          $errors++;
          continue;
        }

        $content = $response->getBody()->getContents();
        $xml = @simplexml_load_string($content);
        if (!$xml) {
          $this->output()->writeln("  Node {$nid}: Invalid XML from {$url}");
          $errors++;
          continue;
        }

        $hash = hash('sha256', $content);
        $hash_display = substr($hash, 0, 16) . '...';

        if ($options['dry-run']) {
          $this->output()->writeln("  Node {$nid}: Would set hash to {$hash_display}");
          $updated++;
        }
        else {
          if ($node->hasField('field_content_hash')) {
            $node->set('field_content_hash', $hash);
            if ($node->hasField('field_last_fetch')) {
              $node->set('field_last_fetch', time());
            }
            $node->save();
            $this->output()->writeln("  Node {$nid}: Hash saved ({$hash_display})");
            $updated++;
          }
          else {
            $this->output()->writeln("  Node {$nid}: Missing field_content_hash field");
            $errors++;
          }
        }
      }
      catch (\Exception $e) {
        $this->output()->writeln("  Node {$nid}: Error - {$e->getMessage()}");
        $errors++;
      }
    }

    $this->output()->writeln("\n" . str_repeat('=', 50));
    $this->output()->writeln("Summary:");
    $this->output()->writeln("   Total nodes: {$total}");
    $this->output()->writeln("   Updated: {$updated}");
    $this->output()->writeln("   Skipped: {$skipped}");
    $this->output()->writeln("   Errors: {$errors}");

    if ($options['dry-run']) {
      $this->output()->writeln("\nThis was a dry run. Use without --dry-run to save changes.");
    }
  }

  /**
   * Recalculate and update content hash for specific node(s).
   *
   * @param string $nids
   *   Comma-separated node IDs or ranges (e.g., "123,456,100-110")
   * @param array $options
   *   Command options.
   *
   * @command shared-content:update-hash
   * @aliases sc-update-hash
   * @option force Force update even if hash exists.
   */
  public function updateHash($nids, array $options = ['force' => FALSE]) {
    $ids = $this->parseIdList($nids);

    if (empty($ids)) {
      $this->logger()->error('No valid node IDs provided.');
      return self::EXIT_FAILURE;
    }

    $this->output()->writeln("Updating hashes for " . count($ids) . " node(s)...\n");

    $nodes = Node::loadMultiple($ids);
    $updated = 0;
    $errors = 0;

    foreach ($nodes as $node) {
      $nid = $node->id();

      if (!$node->hasField('field_shared_content_xml') || $node->get('field_shared_content_xml')->isEmpty()) {
        $this->output()->writeln("  Node {$nid}: Not a shared content node");
        $errors++;
        continue;
      }

      if (!$options['force'] && $node->hasField('field_content_hash') && !$node->get('field_content_hash')->isEmpty()) {
        $existing = $node->get('field_content_hash')->value;
        $this->output()->writeln("  Node {$nid}: Hash exists (" . substr($existing, 0, 16) . "...), use --force to recalculate");
        continue;
      }

      $url = $node->get('field_shared_content_xml')->value;

      try {
        $response = $this->httpClient->request('GET', $url, [
          'timeout' => 120,
          'http_errors' => FALSE,
        ]);

        if ($response->getStatusCode() !== 200) {
          $this->output()->writeln("  Node {$nid}: HTTP {$response->getStatusCode()}");
          $errors++;
          continue;
        }

        $content = $response->getBody()->getContents();
        $hash = hash('sha256', $content);

        if ($node->hasField('field_content_hash')) {
          $node->set('field_content_hash', $hash);
          if ($node->hasField('field_last_fetch')) {
            $node->set('field_last_fetch', time());
          }
          $node->save();
          $this->output()->writeln("  Node {$nid}: Hash updated to " . substr($hash, 0, 16) . "...");
          $updated++;
        }
        else {
          $this->output()->writeln("  Node {$nid}: Missing field_content_hash field");
          $errors++;
        }
      }
      catch (\Exception $e) {
        $this->output()->writeln("  Node {$nid}: {$e->getMessage()}");
        $errors++;
      }
    }

    $this->output()->writeln("\nUpdated: {$updated}, Errors: {$errors}");
    return $updated > 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Manually create the hash and fetch timestamp fields.
   *
   * @command shared-content:create-fields
   * @aliases sc-create-fields
   */
  public function createFields() {
    $content_types = ['person', 'event', 'article', 'book'];

    $this->output()->writeln('Creating field storage...');

    if (!FieldStorageConfig::loadByName('node', 'field_content_hash')) {
      FieldStorageConfig::create([
        'field_name' => 'field_content_hash',
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => 1,
        'settings' => ['max_length' => 64],
      ])->save();
      $this->output()->writeln('  Created field_content_hash storage');
    }
    else {
      $this->output()->writeln('  field_content_hash storage already exists');
    }

    if (!FieldStorageConfig::loadByName('node', 'field_last_fetch')) {
      FieldStorageConfig::create([
        'field_name' => 'field_last_fetch',
        'entity_type' => 'node',
        'type' => 'timestamp',
        'cardinality' => 1,
      ])->save();
      $this->output()->writeln('  Created field_last_fetch storage');
    }
    else {
      $this->output()->writeln('  field_last_fetch storage already exists');
    }

    $this->output()->writeln('Adding fields to content types...');

    foreach ($content_types as $content_type) {
      if (!FieldConfig::loadByName('node', $content_type, 'field_content_hash')) {
        FieldConfig::create([
          'field_name' => 'field_content_hash',
          'entity_type' => 'node',
          'bundle' => $content_type,
          'label' => 'Content Hash',
          'description' => 'SHA-256 hash of remote XML content for change detection.',
          'required' => FALSE,
        ])->save();
        $this->output()->writeln("  Added field_content_hash to {$content_type}");
      }

      if (!FieldConfig::loadByName('node', $content_type, 'field_last_fetch')) {
        FieldConfig::create([
          'field_name' => 'field_last_fetch',
          'entity_type' => 'node',
          'bundle' => $content_type,
          'label' => 'Last Fetch Time',
          'description' => 'Timestamp of last successful fetch from remote source.',
          'required' => FALSE,
        ])->save();
        $this->output()->writeln("  Added field_last_fetch to {$content_type}");
      }
    }

    drupal_flush_all_caches();

    $state = \Drupal::state();
    if (!$state->get('shared_content.active_nodes')) {
      $state->set('shared_content.active_nodes', []);
      $this->output()->writeln('  Initialized active nodes tracking');
    }

    $this->output()->writeln('All fields created successfully!');
  }

  /**
   * Flushes all caches just like the Admin Toolbar Flush-all button.
   *
   * @command flush:all
   * @aliases flush-all,fa
   * @validate-module-enabled shared_content
   */
  public function flushAll(): void {
    $this->output()->writeln('<info>Flushing all Drupal caches...</info>');

    drupal_flush_all_caches();

    $this->cacheTagsInvalidator->invalidateTags(['library_info']);
    $this->cssCollectionOptimizer->deleteAll();
    $this->jsCollectionOptimizer->deleteAll();

    if ($this->assetQueryString) {
      $this->assetQueryString->reset();
    }

    \Drupal::state()->set('system.css_js_query_string', (string) time());

    $this->output()->writeln('<info>All caches flushed.</info>');
  }

  /**
   * Import a single configuration object from a YAML file.
   *
   * @param string $name
   *   Config name.
   * @param array $options
   *   Command options.
   *
   * @command config:single:import
   * @aliases csi,config-single-import
   * @option dir Directory where the YAML file is located.
   * @option file Full path to the YAML file.
   * @option preview Show the changes without applying them.
   * @usage drush csi system.site
   * @usage drush csi system.site --file=/tmp/system.site.yml
   * @validate-module-enabled shared_content
   */
  public function importSingle(string $name, array $options = ['dir' => NULL, 'file' => NULL, 'preview' => FALSE]): int {
    $inputPath = NULL;

    if (!empty($options['file'])) {
      $inputPath = (string) $options['file'];
      if (!str_ends_with(strtolower($inputPath), '.yml')) {
        $inputPath .= '.yml';
      }
    }
    elseif (!empty($options['dir'])) {
      $dir = rtrim((string) $options['dir'], '/');
      $inputPath = Path::join($dir, $name . '.yml');
    }

    $is_file = $inputPath && is_file($inputPath) && is_readable($inputPath);
    $data = NULL;

    if ($is_file) {
      $data = Yaml::decode((string) file_get_contents($inputPath));
      if (!is_array($data)) {
        $this->logger()->error("Could not parse YAML file: {$inputPath}");
        return self::EXIT_FAILURE;
      }
    }
    else {
      $data = $this->syncStorage->read($name);
      if (!is_array($data)) {
        $this->logger()->error("Config '{$name}' was not found in the sync directory.");
        return self::EXIT_FAILURE;
      }
    }

    $source = new StorageReplaceDataWrapper($this->activeStorage);
    $source->replaceData($name, $data);
    $comparer = new StorageComparer($source, $this->activeStorage);
    $comparer->createChangelist();

    if (!$comparer->hasChanges()) {
      $this->logger()->notice("No changes detected for '{$name}'.");
      return self::EXIT_SUCCESS;
    }

    if (!empty($options['preview'])) {
      $this->printChangelist($comparer, $name);
      return self::EXIT_SUCCESS;
    }

    $ok = $this->runConfigImport($comparer);
    if ($ok) {
      $this->configFactory->reset();
      $this->logger()->success("Successfully imported '{$name}'.");
      return self::EXIT_SUCCESS;
    }

    $this->logger()->error("Failed importing '{$name}'.");
    return self::EXIT_FAILURE;
  }

  /**
   * Export a single configuration object to YAML.
   *
   * @param string $name
   *   Config name.
   * @param array $options
   *   Command options.
   *
   * @command config:single:export
   * @aliases cse,config-single-export
   * @option dir Destination directory.
   * @option file Exact destination file path.
   * @usage drush cse system.site
   * @validate-module-enabled shared_content
   */
  public function exportSingle(string $name, array $options = ['dir' => NULL, 'file' => NULL]): int {
    $data = $this->activeStorage->read($name);
    if (!is_array($data)) {
      $this->logger()->error(sprintf('Config "%s" does not exist.', $name));
      return self::EXIT_FAILURE;
    }

    $yaml = Yaml::encode($data);

    if (!empty($options['file'])) {
      $path = (string) $options['file'];
      if (!str_ends_with(strtolower($path), '.yml')) {
        $path .= '.yml';
      }
      $this->ensureDirectoryWritable(Path::getDirectory($path));
      file_put_contents($path, $yaml);
      $this->logger()->success(sprintf('Exported "%s" to %s', $name, $path));
      return self::EXIT_SUCCESS;
    }

    if (!empty($options['dir'])) {
      $dir = rtrim((string) $options['dir'], '/');
      $this->ensureDirectoryWritable($dir);
      $path = Path::join($dir, $name . '.yml');
      file_put_contents($path, $yaml);
      $this->logger()->success(sprintf('Exported "%s" to %s', $name, $path));
      return self::EXIT_SUCCESS;
    }

    $this->syncStorage->write($name, $data);
    $this->logger()->success(sprintf('Exported "%s" to sync directory.', $name));
    return self::EXIT_SUCCESS;
  }

  /**
   * Update nodes from their shared-content XML.
   *
   * This is the primary command for updating shared content nodes. It supports
   * targeting by nid, content type, or all nodes, with dry-run and force options.
   *
   * @command shared-content:update-one
   * @aliases scuo,scu-existing
   * @option nids Comma/ranges.
   * @option type Comma list.
   * @option limit Limit.
   * @option force Bypass caches.
   * @option dry-run Preview changes.
   * @usage drush scuo --nids=123
   * @usage drush scuo --type=article --limit=10
   * @usage drush scuo --type=person --dry-run
   * @usage drush scuo --force
   */
  public function updateOne(
    array $options = [
      'nids' => '',
      'type' => NULL,
      'limit' => 0,
      'force' => FALSE,
      'dry-run' => FALSE,
    ],
  ): int {
    $ids = $this->parseIdList((string) ($options['nids'] ?? ''));

    if (empty($ids)) {
      $q = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');

      if (!empty($options['type'])) {
        $bundles = array_map('trim', explode(',', (string) $options['type']));
        $q->condition('type', $bundles, 'IN');
      }
      if ((int) ($options['limit'] ?? 0) > 0) {
        $q->range(0, (int) $options['limit']);
      }
      $ids = $q->execute();
    }

    if (empty($ids)) {
      $this->logger()->notice('No nodes matched.');
      return self::EXIT_SUCCESS;
    }

    $noFilters = empty($options['nids']) && empty($options['type']) && (int) ($options['limit'] ?? 0) === 0;
    if ($noFilters) {
      $count = is_array($ids) ? count($ids) : 0;
      if (!$this->io()->confirm("This will update ALL {$count} shared-content nodes. Continue?", FALSE)) {
        $this->logger()->warning('Aborted.');
        return self::EXIT_FAILURE;
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($ids);
    $changed = 0;

    foreach ($nodes as $node) {
      if ($node->get('field_shared_content_xml')->isEmpty()) {
        continue;
      }

      $url = (string) $node->get('field_shared_content_xml')->value;
      $xml = $this->fetchXmlContent($url, (bool) $options['force']);
      if (!$xml) {
        $this->io()->writeln("  {$node->id()} failed to fetch XML — deleting");
        $node->delete();
        continue;
      }

      $xmlElement = $xml->node;
      if (empty($xmlElement) || count((array) $xmlElement) === 0) {
        $this->io()->writeln("  {$node->id()} XML element empty — skipping");
        continue;
      }

      if ($options['dry-run']) {
        $clone = $node->createDuplicate();
        $this->applyXmlValuesToNode($clone, $clone->bundle(), $xmlElement, $xml, $url);
        $diff = $this->diffNode($node, $clone, [
          'title', 'field_event_series_link', 'field_event_when', 'field_person_first_name', 'field_person_last_name',
        ]);
        $this->printDiff($node->id(), $diff);
        continue;
      }

      $this->applyXmlValuesToNode($node, $node->bundle(), $xmlElement, $xml, $url);
      $node->setNewRevision(TRUE);
      $node->setChangedTime(\Drupal::time()->getRequestTime());
      $node->save();
      $changed++;
      $this->io()->writeln("  Updated node {$node->id()}");
    }

    $this->logger()->notice("Done. Updated {$changed} node(s).");
    return self::EXIT_SUCCESS;
  }

  /**
   * Cleanup Node Batch Process.
   */
  public static function cleanupNodeBatch($nid, &$context) {
    $node = Node::load($nid);

    if (!$node) {
      \Drupal::logger('shared_content')->warning("Node {$nid} no longer exists.");
      return;
    }

    $title = $node->label();
    $bundle = $node->bundle();

    if ($node->hasField('field_shared_content_xml') && !$node->get('field_shared_content_xml')->isEmpty()) {
      $sharedURL = (string) $node->get('field_shared_content_xml')->value;

      try {
        $httpClient = \Drupal::service('http_client');
        $logger = \Drupal::logger('shared_content');

        $response = $httpClient->request('GET', $sharedURL, ['timeout' => 30]);

        if ($response->getStatusCode() === 200) {
          $xml = @simplexml_load_string($response->getBody()->getContents());

          if (!$xml) {
            $logger->notice("Deleting node {$nid} '{$title}' [{$bundle}]: Invalid XML.");
            $node->delete();
            $context['message'] = "Deleted node {$nid} '{$title}'";
          }
          else {
            $xmlElement = $xml->node;
            if (empty($xmlElement) || count((array) $xmlElement) === 0) {
              $logger->notice("Deleting node {$nid} '{$title}' [{$bundle}]: Empty XML element.");
              $node->delete();
              $context['message'] = "Deleted node {$nid} '{$title}'";
            }
          }
        }
        else {
          $logger->warning("Fetch failed for node {$nid} '{$title}' [{$bundle}] (HTTP {$response->getStatusCode()}). Deleting.");
          $node->delete();
          $context['message'] = "Deleted node {$nid} '{$title}'";
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('shared_content')->error("Exception for node {$nid} '{$title}' [{$bundle}]: " . $e->getMessage() . " — Deleting.");
        $node->delete();
        $context['message'] = "Deleted node {$nid} '{$title}'";
      }
    }
    else {
      \Drupal::logger('shared_content')->notice("Deleting node {$nid} '{$title}' [{$bundle}]: Missing field_shared_content_xml.");
      $node->delete();
      $context['message'] = "Deleted node {$nid} '{$title}'";
    }
  }

  /**
   * Batch finish callback.
   */
  public static function cleanupBatchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Cleanup complete.'));
    }
    else {
      \Drupal::messenger()->addError(t('Cleanup encountered errors.'));
    }
  }

  /**
   * Imports OPML feeds manually.
   *
   * @command shared-content:opml
   * @aliases sc-opml
   */
  public function importOpml() {
    $form = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\shared_content\Form\RemoteForm::class);
    $form->importFeedsFromOpml();
    if ($form instanceof RemoteForm) {
      $form->importFeedsFromOpml();
    }
    $this->logger()->success('OPML feeds imported successfully.');
  }

  /**
   * Refresh aggregator feeds — all feeds or a single feed by ID/URL.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:refresh-feeds
   * @aliases sc-refresh,sc-refresh-one
   * @option id Refresh a single feed by ID.
   * @option url Refresh a single feed by URL.
   * @usage drush sc-refresh
   *   Refresh all feeds.
   * @usage drush sc-refresh --id=5
   *   Refresh feed with ID 5.
   * @usage drush sc-refresh --url="https://example.com/feed"
   *   Refresh feed matching this URL.
   */
  public function refreshFeeds(array $options = ['id' => NULL, 'url' => NULL]): int {
    // Single feed mode.
    if (!empty($options['id']) || !empty($options['url'])) {
      $feed = NULL;

      if (!empty($options['id'])) {
        $feed = Feed::load((int) $options['id']);
      }
      elseif (!empty($options['url'])) {
        $ids = \Drupal::entityQuery('aggregator_feed')
          ->accessCheck(FALSE)
          ->condition('url', $options['url'])
          ->execute();
        if ($ids) {
          $feed = Feed::load(reset($ids));
        }
      }

      if (!$feed) {
        $this->logger()->error('Feed not found.');
        return self::EXIT_FAILURE;
      }

      try {
        $feed->refreshItems();
        $this->logger()->success('Feed refreshed: ' . $feed->label());
        return self::EXIT_SUCCESS;
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
        return self::EXIT_FAILURE;
      }
    }

    // All feeds mode.
    $batch = [
      'title' => dt('Refreshing Aggregator Feeds'),
      'operations' => [],
      'init_message' => dt('Initializing...'),
      'progress_message' => dt('Processing @current of @total feeds...'),
      'error_message' => dt('An error occurred during feed refresh.'),
      'finished' => [static::class, 'batchFinished'],
    ];

    $feed_ids = $this->entityTypeManager->getStorage('aggregator_feed')
      ->getQuery()
      ->accessCheck()
      ->execute();

    if (empty($feed_ids)) {
      $this->logger()->notice('No aggregator feeds found to refresh.');
      return self::EXIT_SUCCESS;
    }

    foreach ($feed_ids as $feed_id) {
      $batch['operations'][] = [[static::class, 'refreshFeedBatch'], [$feed_id]];
    }

    batch_set($batch);
    drush_backend_batch_process();
    return self::EXIT_SUCCESS;
  }

  /**
   * Batch process callback to refresh a single feed.
   */
  public static function refreshFeedBatch($feed_id, &$context) {
    $feed = Feed::load($feed_id);
    if ($feed) {
      try {
        $feed->refreshItems();
        $context['message'] = t('Refreshing feed: @title', ['@title' => $feed->label()]);
        \Drupal::logger('aggregator')->notice('Feed @title refreshed.', ['@title' => $feed->label()]);
      }
      catch (\Exception $e) {
        \Drupal::logger('aggregator')->error('Error refreshing feed @title: @message', [
          '@title' => $feed->label(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Batch finish callback for refreshFeeds.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addMessage(t('All aggregator feeds have been refreshed.'));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred while refreshing feeds.'));
    }
  }

  /**
   * Delete aggregator feeds, their items, and related nodes by title or URL.
   *
   * @param array $options
   *   Command options.
   *
   * @command shared-content:delete-feeds
   * @aliases sc-delete-feeds,sc-edel
   * @option title Feed title pattern.
   * @option url Feed URL pattern.
   * @usage drush sc-delete-feeds --url="https://lasprogram.wustl.edu"
   * @usage drush sc-delete-feeds --title="Digital Commons"
   * @usage drush sc-delete-feeds --title="News" --url="artsci.wustl.edu"
   */
  public function deleteFeeds(array $options = ['title' => NULL, 'url' => NULL]): int {
    $matchTitle = trim((string) ($options['title'] ?? ''));
    $matchUrl = trim((string) ($options['url'] ?? ''));

    if ($matchTitle === '' && $matchUrl === '') {
      $this->logger()->error('Please provide at least one of --title or --url.');
      return self::EXIT_FAILURE;
    }

    $titlePattern = $matchTitle !== '' ? '/' . preg_quote($matchTitle, '/') . '/i' : NULL;
    $urlPattern = $matchUrl !== '' ? '/' . preg_quote($matchUrl, '/') . '/i' : NULL;

    $feedStorage = $this->entityTypeManager->getStorage('aggregator_feed');
    $feeds = $feedStorage->loadMultiple(
      \Drupal::entityQuery('aggregator_feed')->accessCheck(FALSE)->execute()
    );

    $deletedFeeds = 0;
    $deletedItems = 0;
    $deletedNodes = 0;

    foreach ($feeds as $feed) {
      $title = (string) $feed->label();
      $url = (string) $feed->get('url')->value;

      $titleMatch = $titlePattern ? preg_match($titlePattern, $title) : TRUE;
      $urlMatch = $urlPattern ? preg_match($urlPattern, $url) : TRUE;

      if ($titleMatch && $urlMatch) {
        // Delete aggregator items.
        $itemIds = \Drupal::entityQuery('aggregator_item')
          ->accessCheck(FALSE)
          ->condition('fid', $feed->id())
          ->execute();
        if ($itemIds) {
          $items = $this->entityTypeManager->getStorage('aggregator_item')->loadMultiple($itemIds);
          foreach ($items as $item) {
            $item->delete();
            $deletedItems++;
          }
        }

        // Delete related shared content nodes.
        if ($urlPattern) {
          $nodeIds = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('field_shared_content_xml', NULL, 'IS NOT NULL')
            ->execute();
          if ($nodeIds) {
            $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds);
            foreach ($nodes as $node) {
              $shared = (string) $node->get('field_shared_content_xml')->value;
              if (preg_match($urlPattern, $shared)) {
                $node->delete();
                $deletedNodes++;
                $this->output()->writeln("  Deleted shared content node {$node->id()} for feed URL: {$shared}");
              }
            }
          }
        }

        $this->output()->writeln("  Deleting feed: '{$title}' ({$url})");
        $feed->delete();
        $deletedFeeds++;
      }
    }

    if ($deletedFeeds === 0) {
      $this->logger()->warning('No matching feeds found.');
    }
    else {
      $this->logger()->success("Deleted {$deletedFeeds} feed(s), {$deletedItems} item(s), {$deletedNodes} node(s).");
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Update shared content XML field URLs (replace old domains).
   *
   * @command shared-content:update-xml
   * @aliases scu
   */
  public function updateSharedContentXml() {
    $this->output()->writeln("Starting shared content XML field updates...");

    $content_types = ['person', 'book', 'article', 'event'];
    foreach ($content_types as $type) {
      $this->output()->writeln("  Updating nodes of type: $type");
      $this->updateNodesByContentType($type);
    }

    $this->messenger->addStatus("Shared content XML field update completed.");
  }

  /**
   * Apply domain replacements to shared content XML URLs.
   *
   * @command shared-content:fix-domains
   * @aliases sc-fix-domains
   * @option type Restrict to bundle.
   * @option dry-run Preview only.
   */
  public function fixDomains(array $options = ['type' => NULL, 'dry-run' => FALSE]): int {
    $q = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');
    if (!empty($options['type'])) {
      $q->condition('type', $options['type']);
    }
    $nids = $q->execute();
    if (!$nids) {
      $this->io()->writeln('No nodes to process.');
      return self::EXIT_SUCCESS;
    }

    $count = 0;
    foreach (Node::loadMultiple($nids) as $node) {
      $cur = (string) $node->get('field_shared_content_xml')->value;
      $upd = $this->replaceOldDomains($cur);
      if ($upd !== $cur) {
        $this->io()->writeln("NID {$node->id()}: $cur  =>  $upd");
        if (!$options['dry-run']) {
          $node->set('field_shared_content_xml', $upd);
          $node->setNewRevision(TRUE);
          $node->save();
        }
        $count++;
      }
    }
    $this->logger()->notice("Changed {$count} node(s).");
    return self::EXIT_SUCCESS;
  }

  /**
   * Enqueue shared-content updates.
   *
   * @command shared-content:queue-updates
   * @aliases scq
   * @option nids Comma/ranges.
   * @option type Comma list.
   * @option limit Limit.
   * @option force Bypass caches.
   * @option purge Purge existing queue.
   */
  public function queueUpdates(
    array $options = [
      'nids' => '',
      'type' => NULL,
      'limit' => 0,
      'force' => FALSE,
      'purge' => FALSE,
    ],
  ): int {
    $queue = $this->queueFactory->get('shared_content_update');

    if (!empty($options['purge'])) {
      $queue->deleteQueue();
      $this->io()->writeln('Purged existing queue items.');
    }

    $ids = $this->parseIdList((string) ($options['nids'] ?? ''));
    if (empty($ids)) {
      $q = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');

      if (!empty($options['type'])) {
        $bundles = array_map('trim', explode(',', (string) $options['type']));
        $q->condition('type', $bundles, 'IN');
      }
      if ((int) ($options['limit'] ?? 0) > 0) {
        $q->range(0, (int) $options['limit']);
      }
      $ids = $q->execute();
    }

    if (empty($ids)) {
      $this->logger()->notice('No nodes to queue.');
      return self::EXIT_SUCCESS;
    }

    $noFilters = empty($options['nids']) && empty($options['type']) && (int) ($options['limit'] ?? 0) === 0;
    if ($noFilters) {
      $count = is_array($ids) ? count($ids) : 0;
      if (!$this->io()->confirm("This will enqueue ALL {$count} shared-content nodes. Continue?", FALSE)) {
        $this->logger()->warning('Aborted.');
        return self::EXIT_FAILURE;
      }
    }

    $force = (bool) $options['force'];
    foreach ($ids as $nid) {
      $queue->createItem(['nid' => (int) $nid, 'force' => $force, 'attempts' => 0]);
    }

    $this->logger()->success("Queued " . count($ids) . " node(s).");
    return self::EXIT_SUCCESS;
  }

  /**
   * Force-refresh all shared content nodes via Batch API.
   *
   * @command sc-force-refresh-batch
   * @aliases sc-frb
   * @option force Process all nodes regardless of last fetch time.
   * @option min-age Minimum age in seconds before re-fetching. Default 3600.
   * @option chunk-size Number of nodes per batch operation. Default 10.
   * @usage drush sc-frb
   * @usage drush sc-frb --force
   * @usage drush sc-frb --force --chunk-size=5
   */
  public function forceRefreshBatch(
    array $options = [
      'force' => FALSE,
      'min-age' => 3600,
      'chunk-size' => 10,
    ],
  ) {
    $force_all = (bool) $options['force'];
    $min_age = (int) $options['min-age'];
    $chunk_size = max(1, (int) $options['chunk-size']);

    $nids = $this->feedRefresherForce->getEligibleNodeIds($force_all, $min_age);

    if (empty($nids)) {
      $this->logger()->notice('No eligible nodes found.');
      return;
    }

    $nids = array_values($nids);
    $total = count($nids);

    $this->logger()->notice('Queuing @count nodes for batch refresh (chunk size: @chunk).', [
      '@count' => $total,
      '@chunk' => $chunk_size,
    ]);

    $batch = [
      'title' => dt('Refreshing shared content nodes'),
      'operations' => [],
      'finished' => ['\Drupal\shared_content\Batch\SharedContentRefreshBatch', 'finished'],
      'init_message' => dt('Starting shared content refresh for @count nodes...', ['@count' => $total]),
      'progress_message' => dt('Processed @current of @total batches.'),
      'error_message' => dt('Batch encountered an error.'),
    ];

    foreach (array_chunk($nids, $chunk_size) as $chunk) {
      $batch['operations'][] = [
        ['\Drupal\shared_content\Batch\SharedContentRefreshBatch', 'processChunk'],
        [$chunk],
      ];
    }

    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Queue shared content nodes for refresh via queue worker.
   *
   * @command sc-queue
   * @option force Queue all nodes regardless of last fetch time.
   * @option min-age Minimum age in seconds before re-queuing. Default 3600.
   * @usage drush sc-queue
   * @usage drush sc-queue --force
   */
  public function queueNodes(array $options = ['force' => FALSE, 'min-age' => 3600]) {
    $force_all = (bool) $options['force'];
    $min_age = (int) $options['min-age'];
    $this->feedRefresherForce->queueNodesForRefresh($force_all, $min_age);
    $this->logger()->success('Nodes queued. Run drush sc-process to consume the queue.');
  }

  /**
   * Process items in the shared content refresh queue.
   *
   * @command sc-process
   * @option limit Max number of queue items to process. 0 = unlimited.
   * @option lease-time Seconds to lease each queue item. Default 120.
   * @usage drush sc-process
   * @usage drush sc-process --limit=50
   */
  public function processQueue(array $options = ['limit' => 0, 'lease-time' => 120]) {
    $limit = (int) $options['limit'];
    $lease_time = (int) $options['lease-time'];

    $queue = $this->queueFactory->get('shared_content_refresh');

    $count = $queue->numberOfItems();
    if ($count === 0) {
      $this->logger()->notice('No items in the shared_content_refresh queue.');
      return;
    }

    $this->logger()->notice('Processing @count queued items.', ['@count' => $count]);

    $processed = 0;
    $errors = 0;

    while (TRUE) {
      if ($limit > 0 && $processed >= $limit) {
        break;
      }

      $item = $queue->claimItem($lease_time);
      if (!$item) {
        break;
      }

      try {
        // force_remap = TRUE: queue items represent an explicit refresh
        // request, so we want the field mapper to run unconditionally rather
        // than skipping on 304 / hash-equal short-circuits.
        $this->feedRefresherForce->processNode($item->data['nid'], TRUE);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Exception $e) {
        $errors++;
        $queue->releaseItem($item);
        $this->logger()->error('Error processing node @nid: @message', [
          '@nid' => $item->data['nid'],
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger()->success('Processed @count items (@errors errors).', [
      '@count' => $processed,
      '@errors' => $errors,
    ]);
  }

  /**
   * Process a specific node by ID.
   *
   * @param int $nid
   *   The node ID.
   *
   * @command shared-content:process-node
   * @aliases sc-node
   */
  public function processNode($nid) {
    $this->output()->writeln(sprintf('Processing node %d...', $nid));

    try {
      // force_remap = TRUE bypasses both the conditional request short-circuit
      // (304 from upstream) and the local hash-equality short-circuit so the
      // field mapper actually runs.
      $this->feedRefresherForce->processNode($nid, TRUE);
      $this->output()->writeln('Node processed successfully.');
    }
    catch (\Exception $e) {
      $this->logger()->error('Error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Clear the shared content queue.
   *
   * @command shared-content:clear-queue
   * @aliases sc-clear
   */
  public function clearQueue() {
    $queue = $this->queueFactory->get('shared_content_refresh');
    $count = $queue->numberOfItems();
    $queue->deleteQueue();
    $this->output()->writeln(sprintf('Cleared %d items from queue.', $count));
  }

  /**
   * Delete m_map* and m_message* migration mapping tables.
   *
   * @command drop-migrate-tables
   * @aliases dmt
   */
  public function dropMigrateTables() {
    $schema = $this->connection->schema();
    $database = $this->connection->getConnectionOptions()['database'];

    $query = $this->connection->query("
      SELECT table_name
      FROM information_schema.tables
      WHERE table_schema = :schema
        AND (table_name LIKE 'm_map%' OR table_name LIKE 'm_message%')
    ", [':schema' => $database]);

    $tables = $query->fetchCol();

    if (empty($tables)) {
      $this->output()->writeln("No matching migration tables found.");
      return;
    }

    foreach ($tables as $table) {
      $this->output()->writeln("  Dropping table: $table");
      try {
        $schema->dropTable($table);
      }
      catch (\Exception $e) {
        $this->output()->writeln("  Error dropping $table: " . $e->getMessage());
      }
    }

    $this->output()->writeln("Done. All matching tables have been dropped.");
  }

  /**
   * Set private field value on all nodes.
   *
   * @param int $value
   *   The value to set (1 or 0).
   *
   * @command shared-content:set-private
   * @aliases sc-set-private
   */
  public function setFieldPrivate(int $value): void {
    if (!in_array($value, [0, 1], TRUE)) {
      $this->output()->writeln("Invalid value.");
      return;
    }

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)->execute();

    if (empty($nids)) {
      $this->output()->writeln("No nodes found.");
      return;
    }

    $nodes = Node::loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      if ($node->hasField('private')) {
        $node->set('private', $value);
        $node->save();
        $count++;
      }
    }

    $this->output()->writeln("Updated {$count} nodes.");
  }

  /**
   * Applies field values to a node based on XML data.
   */
  private function applyXmlValuesToNode(Node $node, string $type, \SimpleXMLElement $xmlElement, \SimpleXMLElement $xml, string $sharedURL): void {
    $node->set('field_shared_content', base64_encode($xml->asXML()));
    $node->set('field_shared_content_xml', $sharedURL);

    if ($type == 'event' && isset($xmlElement->eventDate)) {
      if ($node->hasField('field_event_series_link') && isset($xmlElement->externalURL) && !empty((string) $xmlElement->externalURL)) {
        $node->set('field_event_series_link', ['uri' => (string) $xmlElement->externalURL]);
      }
      // $node->set('field_event_date_tbd', (int) $xmlElement->eventTBD);

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
      if ($node->hasField('field_event_series_link') && isset($xmlElement->externalURL) && !empty((string) $xmlElement->externalURL)) {
        $node->set('field_event_series_link', ['uri' => (string) $xmlElement->externalURL]);
      }
      $date = \DateTime::createFromFormat('n.j.y', (string) $xmlElement->postDate);
      $node->setCreatedTime($date ? $date->getTimestamp() : \Drupal::time()->getCurrentTime());
    }

    if ($type === 'person') {
      if ($node->hasField('field_event_series_link') && isset($xmlElement->externalURL) && !empty((string) $xmlElement->externalURL)) {
        $node->set('field_event_series_link', ['uri' => (string) $xmlElement->externalURL]);
      }
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
   * Fetch XML content from a URL.
   */
  private function fetchXmlContent(string $sharedURL, bool $force = FALSE): ?\SimpleXMLElement {
    $options = ['timeout' => 50];
    if ($force) {
      $options['query'] = ['_ts' => \Drupal::time()->getRequestTime()];
      $options['headers'] = [
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
      ];
    }
    try {
      $response = $this->httpClient->request('GET', $sharedURL, $options);
      if ($response->getStatusCode() === 200) {
        return simplexml_load_string((string) $response->getBody());
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('shared_content')
        ->error("Error fetching XML from {$sharedURL}: " . $e->getMessage());
    }
    return NULL;
  }

  /**
   * Helper: update nodes of a specific content type with domain replacements.
   */
  private function updateNodesByContentType($content_type) {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', $content_type)
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL')
      ->execute();

    if (empty($nids)) {
      return;
    }

    $nodes = Node::loadMultiple($nids);
    foreach ($nodes as $node) {
      if ($node->hasField('field_shared_content_xml') && !$node->get('field_shared_content_xml')->isEmpty()) {
        $current_value = $node->get('field_shared_content_xml')->value;
        $updated_value = $this->replaceOldDomains($current_value);

        if ($current_value !== $updated_value) {
          $node->set('field_shared_content_xml', $updated_value);
          $xml = $this->fetchXmlContent($updated_value, FALSE);

          if ($xml) {
            $xml_string = $xml->asXML();
            if ($xml_string) {
              $encoded_xml = base64_encode($xml_string);
              $node->set('field_shared_content', $encoded_xml);
              $node->save();
            }
          }
          else {
            $node->delete();
          }
        }
      }
    }
  }

  /**
   * Replace old domains with new ones.
   */
  private function replaceOldDomains(string $value): string {
    $replacements = [
      'https://computing.artsci.wustl.edu' => 'https://it.artsci.wustl.edu',
      'https://eps.wustl.edu' => 'https://eeps.wustl.edu',
      'https://artsci.wustl.edu' => 'https://artsci.washu.edu',
      'https://quantumsensors.wustl.edu' => 'https://quantumleaps.wustl.edu',
      'https://complit.wustl.edu' => 'https://complitandthought.wustl.edu',
      'https://german.wustl.edu' => 'https://complitandthought.wustl.edu',
      'https://graduateschool.wustl.edu' => 'https://gradstudies.artsci.wustl.edu',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $value);
  }

  /**
   * Helper: parse "1,2,10-20" into [1,2,10,...,20].
   */
  private function parseIdList(string $list): array {
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', $list))) as $part) {
      if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
        $out = array_merge($out, range((int) $m[1], (int) $m[2]));
      }
      elseif (ctype_digit($part)) {
        $out[] = (int) $part;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * Compare two nodes for field differences.
   */
  private function diffNode(Node $a, Node $b, array $fields): array {
    $diff = [];
    foreach ($fields as $f) {
      if (!$a->hasField($f) || !$b->hasField($f)) {
        continue;
      }
      $va = $a->get($f)->toArray();
      $vb = $b->get($f)->toArray();
      if ($va !== $vb) {
        $diff[$f] = ['from' => $va, 'to' => $vb];
      }
    }
    if ($a->label() !== $b->label()) {
      $diff['title'] = ['from' => $a->label(), 'to' => $b->label()];
    }
    return $diff;
  }

  /**
   * Print field diff for a node.
   */
  private function printDiff(int $nid, array $diff): void {
    if (!$diff) {
      $this->io()->writeln("= NID {$nid}: no changes");
      return;
    }
    $this->io()->writeln("= NID {$nid} changes:");
    foreach ($diff as $k => $v) {
      $from = json_encode($v['from']);
      $to = json_encode($v['to']);
      $this->io()->writeln("  - {$k}: {$from} => {$to}");
    }
  }

  /**
   * Run the config import with a batch.
   */
  private function runConfigImport(StorageComparer $comparer): bool {
    $importer = new ConfigImporter(
      $comparer,
      $this->eventDispatcher,
      $this->configManager,
      $this->lock,
      $this->typedConfig,
      $this->moduleHandler,
      $this->moduleInstaller,
      $this->themeHandler,
      $this->stringTranslation,
      $this->extensionListModule,
      $this->themeExtensionList
    );

    if ($importer->alreadyImporting()) {
      $this->logger()->warning('A configuration import is already running.');
      return FALSE;
    }

    if (!$importer->validate()) {
      $this->logger()->error('Configuration import did not validate.');
      return FALSE;
    }

    try {
      $steps = $importer->initialize();
      $batch = [
        'operations' => [],
        'title' => dt('Importing configuration'),
        'init_message' => dt('Initializing...'),
        'progress_message' => dt('Processing...'),
        'error_message' => dt('An error occurred.'),
        'finished' => [ConfigImporterBatch::class, 'finish'],
      ];

      foreach ($steps as $step) {
        $batch['operations'][] = [
          [ConfigImporterBatch::class, 'process'],
          [$importer, $step],
        ];
      }

      batch_set($batch);
      drush_backend_batch_process();
      return TRUE;
    }
    catch (ConfigImporterException $e) {
      $this->logger()->error($e->getMessage());
      return FALSE;
    }
  }

  /**
   * Pretty-print a changelist for preview mode.
   */
  private function printChangelist(StorageComparer $comparer, string $name): void {
    $ops = [
      'create' => $comparer->getChangelist('create'),
      'update' => $comparer->getChangelist('update'),
      'delete' => $comparer->getChangelist('delete'),
      'rename' => $comparer->getChangelist('rename'),
    ];
    $this->io()->title(sprintf('Preview for "%s"', $name));
    foreach ($ops as $op => $list) {
      if (!empty($list)) {
        $this->io()->writeln(strtoupper($op) . ':');
        foreach ($list as $item) {
          $this->io()->writeln("  - {$item}");
        }
      }
    }
    $this->io()->newLine();
  }

  /**
   * Ensure directory exists & is writable.
   */
  private function ensureDirectoryWritable(string $dir): void {
    if ($dir === '' || $dir === '.' || $dir === '/') {
      return;
    }
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
        throw new \RuntimeException(sprintf('Failed to create directory: %s', $dir));
      }
    }
    if (!is_writable($dir)) {
      throw new \RuntimeException(sprintf('Directory is not writable: %s', $dir));
    }
  }

}
