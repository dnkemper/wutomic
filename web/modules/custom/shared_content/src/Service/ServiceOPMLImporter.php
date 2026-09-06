<?php

namespace Drupal\shared_content\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service to import OPML feeds automatically via cron.
 */
class ServiceOPMLImporter {

  /**
   * The feed storage.
   *
   * @var \Drupal\aggregator\FeedStorageInterface
   */
  protected $feedStorage;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs an OPMLImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->feedStorage = $entity_type_manager->getStorage('aggregator_feed');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('shared_content');
  }

  /**
   * Imports feeds from OPML automatically.
   */
  public function importFeedsFromOpml() {
    $file_path = DRUPAL_ROOT . '/modules/custom/shared_content/shared_content_opml.xml';

    if (!file_exists($file_path)) {
      $this->logger->error('The OPML file could not be found at the specified path.');
      return;
    }

    $data = file_get_contents($file_path);
    $feeds = $this->parseOpml($data);

    if (empty($feeds)) {
      $this->logger->notice('No new feeds were found.');
      return;
    }

    // Get the site's domain and aliases.
    $site_aliases = $this->getSiteAliases();

    foreach ($feeds as $feed) {
      $feed_source = $feed['field_feed_source'] ?? '';
      $feed_type = $feed['field_feed_type'] ?? '';

      // Skip self-imported feeds.
      if (in_array($feed_source, $site_aliases, TRUE)) {
        $this->logger->notice('Skipping self-imported feed: @title (Source: @source)', [
          '@title' => $feed['title'],
          '@source' => $feed_source,
        ]);
        continue;
      }

      if (!UrlHelper::isValid($feed['url'], TRUE)) {
        $this->logger->warning('Invalid URL: @url', ['@url' => $feed['url']]);
        continue;
      }

      // Check for existing feeds.
      $query = $this->feedStorage->getQuery()->accessCheck(FALSE);
      $query->condition('title', $feed['title'])
        ->condition('url', $feed['url'])
        ->condition('field_feed_source', $feed_source)
      // Corrected: Using $feed_type instead of $feed_source.
        ->condition('field_feed_type', $feed_type);

      $ids = $query->execute();
      if (!empty($ids)) {
        $this->logger->notice('Feed already exists: @title', ['@title' => $feed['title']]);
        continue;
      }

      // Create new feed.
      $new_feed = $this->feedStorage->create([
        'title' => $feed['title'],
        'url' => $feed['url'],
        'field_feed_source' => $feed_source,
        'field_feed_type' => $feed_type,
      ]);
      $new_feed->save();

      $this->logger->notice('Imported new feed: @title', ['@title' => $feed['title']]);
    }
  }

  /**
   * Determines site aliases or if running in a local environment (DDEV/Docker).
   *
   * @return array
   *   An array of site aliases. Returns an empty array if running locally.
   */
  protected function getSiteAliases() {
    $current_domain = \Drupal::request()->getHost();

    // Check if running in DDEV.
    $is_ddev = getenv('DDEV_PROJECT') !== FALSE;

    // Check if running in Docker.
    $is_docker = file_exists('/.dockerenv');

    // Define known local hostnames.
    $local_hostnames = ['localhost', '127.0.0.1', 'default.ddev.site'];

    // Determine if it's running locally.
    $is_local = $is_ddev || $is_docker || in_array($current_domain, $local_hostnames, TRUE);

    if ($is_local) {
      // Skip alias checking when running locally.
      return [];
    }

    // Initialize site aliases with the current domain.
    $site_aliases = [$current_domain];

    // Load the sites.php file if available.
    $sites_php_path = DRUPAL_ROOT . '/sites/sites.php';
    if (file_exists($sites_php_path)) {
      $sites = [];
      include $sites_php_path;

      // Check for aliases of the current domain.
      foreach ($sites as $alias => $primary) {
        if ($primary === $current_domain || $alias === $current_domain) {
          $site_aliases[] = $primary;
        }
      }
    }

    return array_unique($site_aliases);
  }

  /**
   * Parses an OPML file.
   *
   * @param string $opml
   *   The OPML file contents.
   *
   * @return array
   *   List of parsed feeds.
   */
  protected function parseOpml($opml) {
    $feeds = [];
    $xml_parser = xml_parser_create();
    xml_parser_set_option($xml_parser, XML_OPTION_TARGET_ENCODING, 'utf-8');

    if (xml_parse_into_struct($xml_parser, $opml, $values)) {
      foreach ($values as $entry) {
        if ($entry['tag'] === 'OUTLINE' && isset($entry['attributes'])) {
          $item = $entry['attributes'];
          if (!empty($item['XMLURL']) && !empty($item['FEEDCONTENTTYPE']) && !empty($item['FEEDSOURCE']) && !empty($item['TEXT'])) {
            $feeds[] = [
              'title' => $item['TEXT'],
              'url' => $item['XMLURL'],
              'field_feed_type' => $item['FEEDCONTENTTYPE'],
              'field_feed_source' => $item['FEEDSOURCE'],
            ];
          }
        }
      }
    }

    xml_parser_free($xml_parser);
    return $feeds;
  }

}
