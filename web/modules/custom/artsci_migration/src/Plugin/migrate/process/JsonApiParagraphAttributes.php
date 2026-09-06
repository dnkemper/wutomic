<?php

declare(strict_types=1);

namespace Drupal\artsci_migration\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fetches a single JSON:API paragraph reference and returns its attributes.
 *
 * Counterpart to jsonapi_links_from_refs: that plugin handles a multi-value
 * relationship payload (an array of {type, id} refs) and returns an array of
 * link field deltas. This one handles a SINGLE relationship ref (the `data`
 * object of a cardinality-1 entity_reference_revisions relationship) and
 * returns the paragraph's full `attributes` object so callers can pluck
 * individual fields out of it with core's `extract` plugin.
 *
 * Built for the D10 `optional_callout` paragraph (cardinality 1 on the source
 * node) whose `field_title` and `field_description` get flattened into two
 * separate fields on the D11 image_card node.
 *
 * Results are cached in a static array per process run, so the same paragraph
 * referenced from multiple source rows only hits the source server once.
 *
 * @MigrateProcessPlugin(
 *   id = "jsonapi_paragraph_attributes",
 *   handle_multiples = FALSE
 * )
 *
 * Example:
 * @code
 * # First, fetch the whole attributes object once.
 * optional_callout_attrs:
 *   plugin: jsonapi_paragraph_attributes
 *   source: source_optional_callout_ref
 *   base_url: 'https://artsci.washu.edu'
 *
 * # Then pull individual fields out of it with core extract.
 * field_sidebar_title:
 *   plugin: extract
 *   source: '@optional_callout_attrs'
 *   default: ''
 *   index:
 *     - field_title
 *
 * 'field_sidebar_text/value':
 *   plugin: extract
 *   source: '@optional_callout_attrs'
 *   default: ''
 *   index:
 *     - field_description
 *     - value
 * @endcode
 */
class JsonApiParagraphAttributes extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client used to fetch JSON:API resources from the source site.
   */
  protected ClientInterface $httpClient;

  /**
   * In-memory cache of attribute payloads keyed by full request URL.
   *
   * Static so caching survives across rows within a single migration run.
   * Keys are full endpoint URLs; values are the decoded `attributes` array
   * or NULL on fetch/parse failure.
   */
  protected static array $cache = [];

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // No reference at all (source node has no paragraph in this field).
    // Return an empty array rather than NULL so callers using core's
    // `extract` plugin don't error on "Input should be an array" — extract
    // on [] with a missing index falls back to its `default`.
    if (empty($value) || !is_array($value)) {
      return [];
    }

    // The JSON:API single-ref payload is a {type, id, meta} associative
    // array. Multi-value refs come in as a list of these; if the caller
    // accidentally points us at one, take the first and continue rather
    // than failing hard. List detection: numerically-indexed array.
    if (array_is_list($value)) {
      $value = $value[0] ?? NULL;
      if (!is_array($value)) {
        return [];
      }
    }

    $type = $value['type'] ?? NULL;
    $uuid = $value['id'] ?? NULL;
    if (empty($type) || empty($uuid)) {
      return [];
    }

    $base_url = rtrim((string) ($this->configuration['base_url'] ?? ''), '/');
    if ($base_url === '') {
      throw new MigrateException('jsonapi_paragraph_attributes requires a `base_url` configuration value.');
    }

    // JSON:API resource type "paragraph--optional_callout" maps to URL
    // path "paragraph/optional_callout".
    $resource_path = str_replace('--', '/', (string) $type);
    $endpoint = "{$base_url}/jsonapi/{$resource_path}/{$uuid}";

    if (array_key_exists($endpoint, self::$cache)) {
      return self::$cache[$endpoint] ?? [];
    }

    try {
      $response = $this->httpClient->get($endpoint, [
        'headers' => ['Accept' => 'application/vnd.api+json'],
        'timeout' => 30,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $attrs = $data['data']['attributes'] ?? NULL;
    }
    catch (\Throwable $e) {
      $migrate_executable->saveMessage(sprintf(
        'jsonapi_paragraph_attributes: failed fetching %s (%s)',
        $endpoint,
        $e->getMessage(),
      ));
      $attrs = NULL;
    }

    // Cache the raw fetch result (NULL on failure) so future cache hits
    // can distinguish "never fetched" from "fetched and got nothing" if we
    // ever need to. But coerce to [] on return so callers see a consistent
    // array shape regardless of fetch outcome.
    self::$cache[$endpoint] = $attrs;
    return $attrs ?? [];
  }

}
