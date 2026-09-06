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
 * Walks JSON:API paragraph refs and extracts a media target id from each.
 *
 * Takes a JSON:API relationship payload (an array of {type, id, meta}
 * objects pointing at paragraphs) and, for each ref, fetches the resource
 * from the source site over HTTP and reads a configured relationship on
 * the paragraph to pull out its drupal_internal__target_id. Returns the
 * collected ids as an array of single key arrays — one entry per
 * resolved media id — keyed by the configurable `output_key` (default
 * `mid`) so a downstream `sub_process` can iterate and look each id up
 * against a media migration.
 *
 * Built originally for the article migration's field_video_p paragraph
 * references, where each video paragraph holds a remote_video media
 * entity on field_spotlight_video_url and we want those MIDs to feed a
 * `migration_lookup` against the remote_video media migration. Reusable
 * for any "fetch paragraph via JSON:API and pull a related media id off
 * one of its relationships" scenario.
 *
 * Results are cached in a static array per process run, so referencing
 * the same paragraph from multiple source rows hits the source server
 * once.
 *
 * @MigrateProcessPlugin(
 *   id = "jsonapi_media_ids_from_refs",
 *   handle_multiples = TRUE
 * )
 *
 * Example:
 * @code
 * _video_d10_media_ids:
 *   plugin: jsonapi_media_ids_from_refs
 *   source: source_video_paragraph_refs
 *   base_url: 'https://artsci.washu.edu'
 *   media_field: field_spotlight_video_url
 *   output_key: mid
 * @endcode
 */
class JsonApiMediaIdsFromRefs extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client used to fetch JSON:API resources from the source site.
   */
  protected ClientInterface $httpClient;

  /**
   * In memory cache of JSON:API payloads keyed by full request URL.
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
    $media_field = $this->configuration['media_field'] ?? NULL;
    $output_key = $this->configuration['output_key'] ?? 'mid';

    if ($base_url === '' || empty($media_field)) {
      throw new MigrateException('jsonapi_media_ids_from_refs requires both `base_url` and `media_field` configuration.');
    }

    $out = [];
    foreach ($value as $ref) {
      if (!is_array($ref)) {
        continue;
      }
      $type = $ref['type'] ?? NULL;
      $uuid = $ref['id'] ?? NULL;
      if (empty($type) || empty($uuid)) {
        continue;
      }

      // JSON:API resource type "paragraph--video" maps to URL "paragraph/video".
      $resource_path = str_replace('--', '/', (string) $type);
      $endpoint = "{$base_url}/jsonapi/{$resource_path}/{$uuid}";

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
            'jsonapi_media_ids_from_refs: failed fetching %s (%s)',
            $endpoint,
            $e->getMessage(),
          ));
          $payload = NULL;
        }
        self::$cache[$endpoint] = $payload;
      }

      if (!is_array($payload)) {
        continue;
      }

      $relationship = $payload['data']['relationships'][$media_field]['data'] ?? NULL;
      if (empty($relationship)) {
        continue;
      }

      // JSON:API relationship data is a single object for to-one fields
      // and an array of objects for to-many. Normalize to a list either way.
      $entries = isset($relationship['type']) ? [$relationship] : $relationship;
      foreach ($entries as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $id = $entry['meta']['drupal_internal__target_id'] ?? NULL;
        if (!empty($id)) {
          $out[] = [$output_key => (int) $id];
        }
      }
    }

    return $out;
  }

  /**
   * {@inheritdoc}
   *
   * Returning TRUE here declares that the plugin's OUTPUT is a multi value
   * array (one entry per resolved media id). Combined with
   * `handle_multiples` in the annotation (which controls INPUT iteration
   * behavior), this fully declares the plugin's array semantics: "I take
   * an array of refs and return an array of single key arrays."
   */
  public function multiple(): bool {
    return TRUE;
  }

}
