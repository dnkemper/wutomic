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
 * Resolves a single JSON:API ref to a target id on one of its relationships.
 *
 * Given a single JSON:API relationship data object ({type, id, meta}), fetches
 * that resource from the source site over HTTP and reads a configured
 * relationship off it, returning that relationship's
 * meta.drupal_internal__target_id as a scalar int (or NULL).
 *
 * Companion to jsonapi_media_ids_from_refs, which does the same walk for an
 * ARRAY of refs and returns an array. Use this single-value variant when the
 * source field is to-one and the destination wants a scalar — e.g. walking a
 * fullscreen_video paragraph's field_fullscreen_video_image (a media--image
 * ref) to the file id behind its field_media_image, to feed a
 * migration_lookup against a file migration.
 *
 * Results are cached in a static array per process run, so referencing the
 * same resource from multiple rows hits the source server once.
 *
 * @MigrateProcessPlugin(
 *   id = "jsonapi_target_id_from_ref",
 *   handle_multiples = TRUE
 * )
 *
 * Example:
 * @code
 * _poster_d10_fid:
 *   plugin: jsonapi_target_id_from_ref
 *   source: fullscreen_image_ref
 *   base_url: 'https://artsci.washu.edu'
 *   relationship: field_media_image
 * @endcode
 */
class JsonApiTargetIdFromRef extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client used to fetch JSON:API resources from the source site.
   */
  protected ClientInterface $httpClient;

  /**
   * In-memory cache of payloads keyed by full request URL.
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
    // A JSON:API to-one relationship payload is a single {type,id,meta}
    // object. Tolerate a one-element wrapping list too, just in case.
    if (is_array($value) && !isset($value['type']) && isset($value[0]) && is_array($value[0])) {
      $value = $value[0];
    }
    if (empty($value) || !is_array($value) || empty($value['type']) || empty($value['id'])) {
      return NULL;
    }

    $base_url = rtrim((string) ($this->configuration['base_url'] ?? ''), '/');
    $relationship = $this->configuration['relationship'] ?? NULL;
    if ($base_url === '' || empty($relationship)) {
      throw new MigrateException('jsonapi_target_id_from_ref requires both `base_url` and `relationship` configuration.');
    }

    // JSON:API resource type "media--image" maps to URL "media/image".
    $resource_path = str_replace('--', '/', (string) $value['type']);
    $endpoint = "{$base_url}/jsonapi/{$resource_path}/{$value['id']}";

    if (array_key_exists($endpoint, self::$cache)) {
      $payload = self::$cache[$endpoint];
    }
    else {
      try {
        $response = $this->httpClient->get($endpoint, [
          'headers' => ['Accept' => 'application/vnd.api+json'],
          'timeout' => 30,
        ]);
        $payload = json_decode((string) $response->getBody(), TRUE);
      }
      catch (\Throwable $e) {
        $migrate_executable->saveMessage(sprintf(
          'jsonapi_target_id_from_ref: failed fetching %s (%s)',
          $endpoint,
          $e->getMessage(),
        ));
        $payload = NULL;
      }
      self::$cache[$endpoint] = $payload;
    }

    if (!is_array($payload)) {
      return NULL;
    }

    $data = $payload['data']['relationships'][$relationship]['data'] ?? NULL;
    if (empty($data)) {
      return NULL;
    }

    // Normalize to-one (object) vs to-many (list); take the first for to-many.
    $entry = isset($data['type']) ? $data : ($data[0] ?? NULL);
    if (!is_array($entry)) {
      return NULL;
    }
    $id = $entry['meta']['drupal_internal__target_id'] ?? NULL;

    return $id !== NULL ? (int) $id : NULL;
  }

}
