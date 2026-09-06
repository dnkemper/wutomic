<?php

namespace Drupal\artsci_migration\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a JSON:API paragraph related URL into the paragraph's attributes.
 *
 * Sibling to extract_jsonapi_paragraph_links: same HTTP fetch + static
 * cache pattern, same URL-driven input (no need to construct endpoints
 * from type+id), but returns the paragraph's full `attributes` object so
 * callers can pluck individual fields out of it with core's `extract`
 * plugin.
 *
 * Built for the D10 optional_callout paragraph (cardinality 1 on the
 * source node) whose `field_title` and `field_description` get flattened
 * into two text fields on the D11 image_card node. Handles both single-
 * and multi-cardinality related URLs:
 *
 *   - single-cardinality response: `data` is an object       -> return its `attributes`
 *   - multi-cardinality response:  `data` is an array        -> return the first item's `attributes`
 *   - empty / unset relationship:  `data` is null or missing -> return []
 *
 * Always returns an array (possibly empty). NEVER returns NULL, so
 * downstream `extract` calls in YAML don't error on "Input should be an
 * array".
 *
 * Configuration:
 *   - request_options: optional array merged into the Guzzle request.
 *     Use this to add Authorization or X-Consumer-ID headers if the
 *     source JSON:API endpoint is not publicly readable.
 *
 * Example:
 * @code
 *   # Source field grabs the JSON:API-supplied related URL directly.
 *   - name: source_optional_callout_related
 *     selector: 'relationships/field_optional_callout/links/related/href'
 *
 *   process:
 *     optional_callout_attrs:
 *       plugin: extract_jsonapi_paragraph_attributes
 *       source: source_optional_callout_related
 *
 *     field_sidebar_title:
 *       plugin: extract
 *       source: '@optional_callout_attrs'
 *       default: ''
 *       index:
 *         - field_title
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "extract_jsonapi_paragraph_attributes",
 *   handle_multiples = FALSE
 * )
 */
class ExtractJsonapiParagraphAttributes extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Static cache of fetched URLs within a single PHP process.
   *
   * Process plugins are reinstantiated per row, so a static array is the
   * simplest way to dedup repeat fetches across a drush migrate:import
   * run. Cleared automatically when the process exits.
   *
   * Cached value is the raw fetched `attributes` array, or NULL on
   * fetch/parse failure. Callers always see an array (NULL is coerced on
   * return).
   */
  protected static $cache = [];

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs the plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // No URL at all (source node didn't expose this relationship).
    if (empty($value) || !is_string($value)) {
      return [];
    }

    $payload = $this->fetchJson($value, $migrate_executable);
    if (!is_array($payload) || !array_key_exists('data', $payload)) {
      return [];
    }

    $data = $payload['data'];

    // Empty relationship: JSON:API returns "data": null for an unset
    // single-cardinality ref, or "data": [] for an empty multi-cardinality
    // ref. Either way: no paragraph, no attributes.
    if ($data === NULL || $data === []) {
      return [];
    }

    // Multi-cardinality response: take the first item. Optional_callout is
    // cardinality 1 on D10 so this branch is only a safety net for callers
    // pointing this plugin at a multivalue relationship by mistake.
    if (array_is_list($data)) {
      $data = $data[0] ?? NULL;
      if (!is_array($data)) {
        return [];
      }
    }

    $attrs = $data['attributes'] ?? NULL;
    return is_array($attrs) ? $attrs : [];
  }

  /**
   * Fetches a JSON:API URL with simple in-process caching.
   *
   * Mirrors the helper in extract_jsonapi_paragraph_links so behavior
   * (timeout, error logging, content-type) is identical across plugins.
   *
   * @param string $url
   *   The URL to fetch.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The current migrate executable, used to record errors.
   *
   * @return array|null
   *   The decoded JSON, or NULL on error.
   */
  protected function fetchJson($url, MigrateExecutableInterface $migrate_executable) {
    if (array_key_exists($url, static::$cache)) {
      return static::$cache[$url];
    }

    $request_options = $this->configuration['request_options'] ?? [];
    $request_options += [
      'timeout' => 30,
      'http_errors' => FALSE,
    ];
    $request_options['headers'] = ($request_options['headers'] ?? []) + [
      'Accept' => 'application/vnd.api+json',
    ];

    try {
      $response = $this->httpClient->request('GET', $url, $request_options);
    }
    catch (\Exception $e) {
      $migrate_executable->saveMessage(sprintf(
        'extract_jsonapi_paragraph_attributes: HTTP error fetching %s: %s',
        $url,
        $e->getMessage()
      ));
      return static::$cache[$url] = NULL;
    }

    if ($response->getStatusCode() !== 200) {
      $migrate_executable->saveMessage(sprintf(
        'extract_jsonapi_paragraph_attributes: HTTP %d fetching %s',
        $response->getStatusCode(),
        $url
      ));
      return static::$cache[$url] = NULL;
    }

    $body = (string) $response->getBody();
    $payload = json_decode($body, TRUE);
    if (!is_array($payload)) {
      $migrate_executable->saveMessage(sprintf(
        'extract_jsonapi_paragraph_attributes: invalid JSON from %s',
        $url
      ));
      return static::$cache[$url] = NULL;
    }

    return static::$cache[$url] = $payload;
  }

}
