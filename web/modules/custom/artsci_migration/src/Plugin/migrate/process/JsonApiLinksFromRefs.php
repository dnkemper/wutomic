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
 * Resolves JSON:API entity references into Drupal link field values.
 *
 * Takes a JSON:API relationship payload (an array of {type, id, meta} objects)
 * and, for each reference, fetches the resource from the source site over
 * HTTP and extracts URL / text / new-window fields into a link field value
 * structure: [['uri' => ..., 'title' => ..., 'options' => ...], ...].
 *
 * Built originally to consolidate D10 `paragraph/links` entities into a
 * multi-value link field on D11 person nodes without creating staging
 * paragraph entities. Reusable for any "fetch paragraph attributes via
 * JSON:API and turn them into link field deltas" scenario.
 *
 * Results are cached in a static array per process run, so referencing the
 * same paragraph from multiple source rows hits the source server once.
 *
 * @MigrateProcessPlugin(
 *   id = "jsonapi_links_from_refs",
 *   handle_multiples = TRUE
 * )
 *
 * Example:
 * @code
 * field_person_website:
 *   plugin: jsonapi_links_from_refs
 *   source: source_links_refs
 *   base_url: 'https://artsci.washu.edu'
 *   url_field: field_button_url
 *   title_field: field_call_to_action_button_text
 *   new_window_field: field_new_window
 * @endcode
 */
class JsonApiLinksFromRefs extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client used to fetch JSON:API resources from the source site.
   */
  protected ClientInterface $httpClient;

  /**
   * In-memory cache of attribute payloads keyed by full request URL.
   *
   * Static so caching survives across rows within a single migration run.
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
    if (empty($value) || !is_array($value)) {
      return [];
    }

    $base_url = rtrim((string) ($this->configuration['base_url'] ?? ''), '/');
    $url_field = $this->configuration['url_field'] ?? NULL;
    $title_field = $this->configuration['title_field'] ?? NULL;
    $new_window_field = $this->configuration['new_window_field'] ?? NULL;

    if ($base_url === '' || empty($url_field)) {
      throw new MigrateException('jsonapi_links_from_refs requires both `base_url` and `url_field` configuration.');
    }

    $links = [];
    foreach ($value as $ref) {
      if (!is_array($ref)) {
        continue;
      }
      $type = $ref['type'] ?? NULL;
      $uuid = $ref['id'] ?? NULL;
      if (empty($type) || empty($uuid)) {
        continue;
      }

      // JSON:API resource type "paragraph--links" maps to URL "paragraph/links".
      $resource_path = str_replace('--', '/', (string) $type);
      $endpoint = "{$base_url}/jsonapi/{$resource_path}/{$uuid}";

      if (array_key_exists($endpoint, self::$cache)) {
        $attrs = self::$cache[$endpoint];
      }
      else {
        try {
          $response = $this->httpClient->get($endpoint, [
            'headers' => ['Accept' => 'application/vnd.api+json'],
            'timeout' => 30,
          ]);
          $data = json_decode((string) $response->getBody(), TRUE);
          $attrs = $data['data']['attributes'] ?? [];
        }
        catch (\Throwable $e) {
          $migrate_executable->saveMessage(sprintf(
            'jsonapi_links_from_refs: failed fetching %s (%s)',
            $endpoint,
            $e->getMessage(),
          ));
          $attrs = NULL;
        }
        self::$cache[$endpoint] = $attrs;
      }

      if (!is_array($attrs)) {
        continue;
      }

      $uri = $attrs[$url_field] ?? NULL;
      if (empty($uri)) {
        continue;
      }

      $link = [
        'uri' => (string) $uri,
        'title' => $title_field ? (string) ($attrs[$title_field] ?? '') : '',
      ];

      if ($new_window_field && !empty($attrs[$new_window_field])) {
        $link['options'] = ['attributes' => ['target' => '_blank']];
      }

      $links[] = $link;
    }

    return $links;
  }

  /**
   * {@inheritdoc}
   *
   * Returning TRUE here declares that the plugin's OUTPUT is a multi-value
   * array (one link delta per element). Combined with `handle_multiples`
   * in the annotation (which controls INPUT iteration behavior), this fully
   * declares the plugin's array semantics: "I take an array of refs and
   * return an array of link field values."
   */
  public function multiple(): bool {
    return TRUE;
  }

}
