<?php

namespace Drupal\shared_content\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\Event;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Logs detailed audit info for every node deletion.
 *
 * If hook_event_dispatcher is not installed, use the hook-based version
 * in shared_content.module instead (see bottom of this file for the hook).
 */
class NodeDeletionAuditSubscriber implements EventSubscriberInterface {

  /**
   * The database table name for the audit log.
   */
  const TABLE = 'shared_content_deletion_log';

  public function __construct(
    protected readonly Connection $database,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly RequestStack $requestStack,
    protected readonly LoggerInterface $logger,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // If using hook_event_dispatcher module.
    if (class_exists('Drupal\core_event_dispatcher\EntityHookEvents')) {
      return [
        'hook_event_dispatcher.entity.predelete' => ['onEntityPreDelete', 100],
      ];
    }
    return [];
  }

  /**
   * React to entity pre-delete (hook_event_dispatcher version).
   */
  public function onEntityPreDelete(Event $event): void {
    if (method_exists($event, 'getEntity')) {
      $entity = $event->getEntity();
      if ($entity instanceof NodeInterface) {
        $this->auditNodeDeletion($entity);
      }
    }
  }

  /**
   * Main audit method — can be called from the event subscriber or a hook.
   */
  public function auditNodeDeletion(NodeInterface $node): void {
    $context = $this->buildDeletionContext($node);

    // Write to our custom audit table.
    $this->writeAuditLog($context);

    // Also log to watchdog for visibility.
    $this->logger->warning('Node deleted: @type "@title" (nid: @nid) by @trigger. User: @user (uid: @uid). Shared: @shared. Hash: @hash. Trace: @trace_summary', [
      '@type' => $context['bundle'],
      '@title' => $context['title'],
      '@nid' => $context['nid'],
      '@trigger' => $context['trigger'],
      '@user' => $context['username'],
      '@uid' => $context['uid'],
      '@shared' => $context['is_shared'] ? 'yes' : 'no',
      '@hash' => $context['content_hash'] ?: 'none',
      '@trace_summary' => $context['trace_summary'],
    ]);
  }

  /**
   * Build the full deletion context array.
   */
  protected function buildDeletionContext(NodeInterface $node): array {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
    $trigger = $this->detectTrigger($trace);
    $trace_summary = $this->buildTraceSummary($trace);
    $request = $this->requestStack->getCurrentRequest();

    // Extract shared_content metadata from the node before it's gone.
    $content_hash = NULL;
    if ($node->hasField('field_content_hash') && !$node->get('field_content_hash')->isEmpty()) {
      $content_hash = $node->get('field_content_hash')->value;
    }

    $xml_source = NULL;
    if ($node->hasField('field_shared_content_xml') && !$node->get('field_shared_content_xml')->isEmpty()) {
      $xml_source = $node->get('field_shared_content_xml')->value;
    }

    $is_shared = FALSE;
    if ($node->hasField('field_is_shared') && !$node->get('field_is_shared')->isEmpty()) {
      $is_shared = (bool) $node->get('field_is_shared')->value;
    }

    return [
      'nid' => $node->id(),
      'uuid' => $node->uuid(),
      'bundle' => $node->bundle(),
      'title' => $node->getTitle(),
      'status' => $node->isPublished(),
      'uid' => $this->currentUser->id(),
      'username' => $this->currentUser->getAccountName() ?: 'anonymous',
      'trigger' => $trigger,
      'request_uri' => $request ? $request->getRequestUri() : 'cli',
      'request_method' => $request ? $request->getMethod() : 'cli',
      'content_hash' => $content_hash,
      'xml_source' => $xml_source,
      'is_shared' => $is_shared,
      'trace_summary' => $trace_summary,
      'trace_full' => $this->buildFullTrace($trace),
      'timestamp' => $this->time->getRequestTime(),
    ];
  }

  /**
   * Detect what triggered the deletion from the backtrace.
   */
  protected function detectTrigger(array $trace): string {
    $trace_string = json_encode($trace);

    // Check most specific patterns first.
    $patterns = [
      // Shared content module operations.
      'shared_content' => [
        'pattern' => '/shared_content/',
        'sub_patterns' => [
          'shared_content:webhook' => '/webhook|Webhook/',
          'shared_content:cron' => '/cron|Cron/',
          'shared_content:batch' => '/batch|Batch/',
          'shared_content:drush' => '/[Dd]rush/',
          'shared_content:purge' => '/purge|Purge/',
        ],
      ],
    ];

    // Check shared_content sub-patterns first.
    foreach ($patterns['shared_content']['sub_patterns'] as $label => $regex) {
      if (preg_match($regex, $trace_string)) {
        return $label;
      }
    }

    // Walk the trace for known trigger signatures.
    foreach ($trace as $frame) {
      $class = $frame['class'] ?? '';
      $function = $frame['function'] ?? '';
      $file = $frame['file'] ?? '';

      // Drush commands.
      if (str_contains($class, 'Drush\\') || str_contains($file, 'drush')) {
        return 'drush';
      }

      // Cron.
      if ($function === 'cron' || str_contains($class, 'CronRun') || str_contains($class, 'Cron')) {
        return 'cron';
      }

      // Batch API.
      if (str_contains($class, 'Batch') || $function === '_batch_process') {
        return 'batch';
      }

      // Bulk operations / Views Bulk Operations / Action.
      if (str_contains($class, 'ViewsBulkOperations')
        || str_contains($class, 'Action')
        || str_contains($class, 'BulkForm')) {
        return 'bulk_operation';
      }

      // REST / JSON:API / webhook.
      if (str_contains($class, 'Rest\\')
        || str_contains($class, 'JsonApi')
        || str_contains($class, 'Webhook')) {
        return 'api';
      }

      // Content moderation.
      if (str_contains($class, 'ContentModeration')) {
        return 'content_moderation';
      }

      // Node form delete (manual via UI).
      if (str_contains($class, 'NodeDeleteForm')
        || str_contains($class, 'DeleteMultiple')
        || str_contains($class, 'ContentEntityDeleteForm')) {
        return 'manual_ui';
      }

      // Entity queue, scheduler, etc.
      if (str_contains($class, 'Scheduler')) {
        return 'scheduler';
      }
    }

    // Fallback: check the request.
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $uri = $request->getRequestUri();
      if (str_contains($uri, '/delete')) {
        return 'manual_ui';
      }
      if (str_contains($uri, 'batch')) {
        return 'batch';
      }
      if (str_contains($uri, 'cron')) {
        return 'cron';
      }
    }

    // If we're in CLI but not Drush specifically.
    if (PHP_SAPI === 'cli') {
      return 'cli_unknown';
    }

    return 'unknown';
  }

  /**
   * Build a concise trace summary (key frames only).
   */
  protected function buildTraceSummary(array $trace): string {
    $relevant = [];
    foreach (array_slice($trace, 0, 20) as $frame) {
      $class = $frame['class'] ?? '';
      $function = $frame['function'] ?? '';
      $file = $frame['file'] ?? '';

      // Skip low-level Drupal/Symfony internals.
      if (str_starts_with($class, 'Symfony\\')
        || str_starts_with($class, 'Drupal\\Core\\DependencyInjection')
        || str_starts_with($class, 'Drupal\\Component\\')
        || $function === '__construct') {
        continue;
      }

      // Include frames from custom/contrib modules, Drush, and key core.
      if (str_contains($file, 'modules/custom/')
        || str_contains($file, 'modules/contrib/')
        || str_contains($class, 'Drush')
        || str_contains($class, 'shared_content')
        || str_contains($class, 'Entity')
        || str_contains($class, 'Node')
        || str_contains($class, 'Cron')
        || str_contains($class, 'Batch')
        || str_contains($class, 'Action')
        || str_contains($class, 'BulkForm')
        || str_contains($class, 'Webhook')) {
        $short_class = $class ? basename(str_replace('\\', '/', $class)) : basename($file);
        $relevant[] = "$short_class::$function";
      }

      // Cap at 8 relevant frames.
      if (count($relevant) >= 8) {
        break;
      }
    }

    return implode(' → ', $relevant) ?: 'no relevant frames';
  }

  /**
   * Build the full trace for storage (more detail for deep debugging).
   */
  protected function buildFullTrace(array $trace): string {
    $lines = [];
    foreach (array_slice($trace, 0, 20) as $i => $frame) {
      $class = $frame['class'] ?? '';
      $function = $frame['function'] ?? '';
      $file = $frame['file'] ?? '';
      $line = $frame['line'] ?? '';
      $type = $frame['type'] ?? '';

      $location = $file ? basename($file) . ":$line" : 'unknown';
      $call = $class ? "$class$type$function" : $function;
      $lines[] = "#$i $call ($location)";
    }

    return implode("\n", $lines);
  }

  /**
   * Write to the custom audit log table.
   */
  protected function writeAuditLog(array $context): void {
    try {
      // Ensure the table exists.
      if (!$this->database->schema()->tableExists(self::TABLE)) {
        $this->createAuditTable();
      }

      $this->database->insert(self::TABLE)
        ->fields([
          'nid' => $context['nid'],
          'uuid' => $context['uuid'],
          'bundle' => $context['bundle'],
          'title' => mb_substr($context['title'], 0, 255),
          'status' => (int) $context['status'],
          'uid' => $context['uid'],
          'username' => $context['username'],
          'trigger_type' => $context['trigger'],
          'request_uri' => mb_substr($context['request_uri'], 0, 2048),
          'request_method' => $context['request_method'],
          'content_hash' => $context['content_hash'],
          'xml_source' => $context['xml_source'],
          'is_shared' => (int) $context['is_shared'],
          'trace_summary' => mb_substr($context['trace_summary'], 0, 2048),
          'trace_full' => $context['trace_full'],
          'timestamp' => $context['timestamp'],
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Don't let audit logging failures break deletions.
      $this->logger->error('Failed to write deletion audit log: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Create the audit log table if it doesn't exist.
   */
  protected function createAuditTable(): void {
    $schema = [
      'description' => 'Audit log for node deletions.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'nid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'uuid' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => FALSE,
        ],
        'bundle' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'title' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'status' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 1,
        ],
        'uid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'username' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => FALSE,
        ],
        'trigger_type' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => 'unknown',
        ],
        'request_uri' => [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => FALSE,
        ],
        'request_method' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => FALSE,
        ],
        'content_hash' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'xml_source' => [
          'type' => 'text',
          'size' => 'medium',
          'not null' => FALSE,
        ],
        'is_shared' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'trace_summary' => [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => FALSE,
        ],
        'trace_full' => [
          'type' => 'text',
          'size' => 'medium',
          'not null' => FALSE,
        ],
        'timestamp' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'nid' => ['nid'],
        'bundle' => ['bundle'],
        'trigger_type' => ['trigger_type'],
        'timestamp' => ['timestamp'],
        'content_hash' => ['content_hash'],
        'is_shared' => ['is_shared'],
      ],
    ];

    $this->database->schema()->createTable(self::TABLE, $schema);
  }

}
