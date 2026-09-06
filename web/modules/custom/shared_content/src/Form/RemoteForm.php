<?php

namespace Drupal\shared_content\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Imports feeds from OPML.
 *
 * @internal
 */
class RemoteForm extends FormBase {

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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a RemoteForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date.formatter service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ClientInterface $http_client,
    DateFormatterInterface $date_formatter,
    RequestStack $request_stack,
    LoggerInterface $logger,
    MessengerInterface $messenger,
  ) {
    $this->feedStorage = $entity_type_manager->getStorage('aggregator_feed');
    $this->httpClient = $http_client;
    $this->dateFormatter = $date_formatter;
    $this->requestStack = $request_stack;
    $this->logger = $logger;
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('logger.channel.shared_content'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aggregator_opml_add';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['update_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Manually Import OPML File'),
    ];
    return $form;
  }

  /**
   * Add this method to your class.
   */
  public function importFeedsFromOpml($refresh = 3600) {
    $file_path = '../web/modules/custom/shared_content/shared_content_opml.xml';

    if (!file_exists($file_path)) {
      $this->messenger()->addError($this->t('The OPML file could not be found at the specified path.'));
      return;
    }

    $data = file_get_contents($file_path);
    $feeds = $this->parseOpml($data);

    if (empty($feeds)) {
      $this->messenger()->addStatus($this->t('No new feed has been added.'));
      return;
    }

    // Get the site's domain and aliases.
    $site_aliases = $this->getSiteAliases();

    foreach ($feeds as $feed) {
      $feed_source = $feed['field_feed_source'] ?? '';

      // Skip self-imported feeds.
      if (in_array($feed_source, $site_aliases, TRUE)) {
        $this->logger->notice('Skipping self-imported feed: @title (Source: @source)', [
          '@title' => $feed['title'],
          '@source' => $feed_source,
        ]);
        continue;
      }

      if (!UrlHelper::isValid($feed['url'], TRUE)) {
        $this->messenger()->addWarning($this->t('The URL %url is invalid.', ['%url' => $feed['url']]));
        continue;
      }

      // Check for existing feeds.
      $query = $this->feedStorage->getQuery()->accessCheck(FALSE);
      $condition = $query->orConditionGroup()
        ->condition('title', $feed['title'])
        ->condition('url', $feed['url'])
        ->condition('field_feed_type', $feed['field_feed_type'])
        ->condition('field_feed_source', $feed_source);
      $ids = $query->condition($condition)->execute();
      $existing_feeds = $this->feedStorage->loadMultiple($ids);

      if (!empty($existing_feeds)) {
        foreach ($existing_feeds as $existing_feed) {
          $updated = FALSE;

          if (!$existing_feed->hasField('field_feed_source') || empty($existing_feed->get('field_feed_source')->value)) {
            $existing_feed->set('field_feed_source', $feed_source);
            $updated = TRUE;
          }

          if (!$existing_feed->hasField('field_feed_type') || empty($existing_feed->get('field_feed_type')->value)) {
            $existing_feed->set('field_feed_type', $feed['field_feed_type']);
            $updated = TRUE;
          }

          if ($updated) {
            $existing_feed->save();
            $this->messenger()->addMessage($this->t('Updated feed %title with missing fields.', ['%title' => $existing_feed->label()]));
          }
        }
      }
      $result = $this->feedStorage->loadMultiple($ids);
      foreach ($result as $old) {
        if (strcasecmp($old->label(), $feed['title']) == 0) {
          $this->messenger()->addWarning($this->t('A feed named %title already exists.', ['%title' => $old->label()]));
          continue 2;
        }
        if (strcasecmp($old->getUrl(), $feed['url']) == 0) {
          $this->messenger()->addWarning($this->t('A feed with the URL %url already exists.', ['%url' => $old->getUrl()]));
          continue 2;
        }
      }
      $new_feed = $this->feedStorage->create([
        'title' => $feed['title'],
        'url' => $feed['url'],
        'field_feed_type' => $feed['field_feed_type'],
        'field_feed_source' => $feed_source,
        'refresh' => $refresh,
      ]);
      $new_feed->save();

    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // No specific validation for the fixed file path.
  }

  /**
   * Returns the available site aliases for shared content remotes.
   */
  protected function getSiteAliases() {
    $request = $this->requestStack->getCurrentRequest();
    $current_domain = $request ? $request->getHost() : '';

    // Check if running locally by detecting DDEV or a typical local hostname.
    $is_local = getenv('DDEV_PROJECT') || in_array($current_domain, ['localhost', '127.0.0.1', 'ddev.site'], TRUE);

    if ($is_local) {
      // Skip alias checking when running locally.
      return [];
    }

    $site_aliases = [$current_domain];

    // Load the sites.php file if available.
    $sites_php_path = DRUPAL_ROOT . '/sites/sites.php';
    if (file_exists($sites_php_path)) {
      include $sites_php_path;
      $sites = [];

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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_path = '../web/modules/custom/shared_content/shared_content_opml.xml';

    if (!file_exists($file_path)) {
      $this->messenger()->addError($this->t('The OPML file could not be found at the specified path.'));
      return;
    }

    $data = file_get_contents($file_path);
    $feeds = $this->parseOpml($data);
    if (empty($feeds)) {
      $this->messenger()->addStatus($this->t('No new feed has been added.'));
      return;
    }

    $site_aliases = $this->getSiteAliases();

    foreach ($feeds as $feed) {
      $feed_source = $feed['field_feed_source'] ?? '';

      if (in_array($feed_source, $site_aliases, TRUE)) {
        $this->logger->notice('Skipping self-imported feed: @title (Source: @source)', [
          '@title' => $feed['title'],
          '@source' => $feed_source,
        ]);
        continue;
      }

      if (!UrlHelper::isValid($feed['url'], TRUE)) {
        $this->messenger()->addWarning($this->t('The URL %url is invalid.', ['%url' => $feed['url']]));
        continue;
      }

      $query = $this->feedStorage->getQuery()->accessCheck(FALSE);
      $condition = $query->orConditionGroup()
        ->condition('title', $feed['title'])
        ->condition('url', $feed['url'])
        ->condition('field_feed_type', $feed['field_feed_type'])
        ->condition('field_feed_source', $feed_source);
      $ids = $query->condition($condition)->execute();
      $result = $this->feedStorage->loadMultiple($ids);

      foreach ($result as $old) {
        if (strcasecmp($old->label(), $feed['title']) == 0) {
          $this->messenger()->addWarning($this->t('A feed named %title already exists.', ['%title' => $old->label()]));
          continue 2;
        }
        if (strcasecmp($old->getUrl(), $feed['url']) == 0) {
          $this->messenger()->addWarning($this->t('A feed with the URL %url already exists.', ['%url' => $old->getUrl()]));
          continue 2;
        }
      }

      $new_feed = $this->feedStorage->create([
        'title' => $feed['title'],
        'url' => $feed['url'],
        'field_feed_type' => $feed['field_feed_type'],
        'field_feed_source' => $feed_source,
      ]);
      $new_feed->save();
    }

    $form_state->setRedirect('aggregator.admin_overview');
  }

  /**
   * Parses an OPML file.
   *
   * @param string $opml
   *   The complete contents of an OPML document.
   *
   * @return array
   *   An array of feeds, each an associative array with a "title" and a "url"
   *   element.
   */
  protected function parseOpml($opml) {
    $feeds = [];
    $xml_parser = xml_parser_create();
    xml_parser_set_option($xml_parser, XML_OPTION_TARGET_ENCODING, 'utf-8');
    if (xml_parse_into_struct($xml_parser, $opml, $values)) {
      foreach ($values as $entry) {
        if ($entry['tag'] == 'OUTLINE' && isset($entry['attributes'])) {
          $item = $entry['attributes'];
          if (!empty($item['XMLURL']) && !empty($item['FEEDCONTENTTYPE']) && !empty($item['FEEDSOURCE']) && !empty($item['TEXT'])) {
            $feeds[] = [
              'title' => $item['TEXT'],
              'url' => $item['XMLURL'],
              // Add feedContentType.
              'field_feed_type' => $item['FEEDCONTENTTYPE'] ?? '',
              // Add feedSource.
              'field_feed_source' => $item['FEEDSOURCE'] ?? '',
            ];
          }
        }
      }
    }
    xml_parser_free($xml_parser);

    return $feeds;
  }

}
