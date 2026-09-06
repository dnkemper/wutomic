<?php

namespace Drupal\artsci_migration\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a JSON:API paragraph related URL into link field values.
 *
 * The D10 book content type stores its links as a multivalue
 * entity_reference_revisions field (field_links_p) referencing
 * paragraphs of bundle "links" with a single link field (field_link).
 * The D11 book content type drops paragraphs and stores the same data
 * directly on the node as a multivalue link field (field_book_links).
 *
 * Each book's JSON:API representation includes a related URL on the
 * field_links_p relationship that returns the linked paragraphs in a
 * single response. This plugin takes that URL, fetches it, and emits
 * an array shaped for a Drupal link field:
 *
 *   [
 *     ['uri' => 'https://…', 'title' => 'Read more'],
 *     ['uri' => 'https://…', 'title' => ''],
 *   ]
 *
 * Configuration:
 *   - link_field: machine name of the link field on the paragraph.
 *     Defaults to 'field_link'.
 *   - request_options: optional array merged into the Guzzle request.
 *     Use this to add Authorization or X-Consumer-ID headers if the
 *     source JSON:API endpoint is not publicly readable.
 *
 * Example:
 * @code
 *   field_book_links:
 *     plugin: extract_jsonapi_paragraph_links
 *     source: field_links_p_related
 *     link_field: field_link
 * @endcode
 *
 * Caveat: if the source paragraph collection paginates (very rare for a
 * book's links — usually 1 to 3 entries), only the first page is read.
 *
 * @MigrateProcessPlugin(
 *   id = "extract_jsonapi_paragraph_links",
 *   handle_multiples = TRUE
 * )
 */
class ExtractJsonapiParagraphLinks extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Static cache of fetched URLs within a single PHP process.
   *
   * Process plugins are reinstantiated per row, so a static array is the
   * simplest way to dedup repeat fetches across a drush migrate:import
   * run. Cleared automatically when the process exits.
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
    if (empty($value) || !is_string($value)) {
      return NULL;
    }

    $link_field = $this->configuration['link_field'] ?? 'field_link';

    $payload = $this->fetchJson($value, $migrate_executable);
    if (!is_array($payload) || empty($payload['data'])) {
      return NULL;
    }

    $links = [];
    foreach ($payload['data'] as $paragraph) {
      $attrs = $paragraph['attributes'] ?? [];
      if (!isset($attrs[$link_field])) {
        continue;
      }
      $field_value = $attrs[$link_field];

      // Single value link fields serialize as one associative array;
      // multivalue ones as a list of those. Normalize to a list either way.
      if (isset($field_value['uri'])) {
        $field_value = [$field_value];
      }

      foreach ($field_value as $link) {
        if (empty($link['uri'])) {
          continue;
        }
        $links[] = [
          'uri' => $link['uri'],
          'title' => $link['title'] ?? '',
        ];
      }
    }

    return $links ?: NULL;
  }

  /**
   * Fetches a JSON:API URL with simple in process caching.
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
        'extract_jsonapi_paragraph_links: HTTP error fetching %s: %s',
        $url,
        $e->getMessage()
      ));
      return static::$cache[$url] = NULL;
    }

    if ($response->getStatusCode() !== 200) {
      $migrate_executable->saveMessage(sprintf(
        'extract_jsonapi_paragraph_links: HTTP %d fetching %s',
        $response->getStatusCode(),
        $url
      ));
      return static::$cache[$url] = NULL;
    }

    $body = (string) $response->getBody();
    $payload = json_decode($body, TRUE);
    if (!is_array($payload)) {
      $migrate_executable->saveMessage(sprintf(
        'extract_jsonapi_paragraph_links: invalid JSON from %s',
        $url
      ));
      return static::$cache[$url] = NULL;
    }

    return static::$cache[$url] = $payload;
  }

}
