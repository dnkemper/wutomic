<?php

namespace Drupal\artsci_facilities;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\artsci_core\ApiAuthKeyTrait;
use Drupal\artsci_core\ApiClientBase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The Utility Alerts API service.
 */
class UtilityAlertsApiClient extends ApiClientBase implements UtilityAlertsApiClientInterface {

  use ApiAuthKeyTrait;

  /**
   * {@inheritdoc}
   */
  protected int $cacheLength = 30;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ClientInterface $client,
    LoggerInterface $logger,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($client, $logger, $cache, $configFactory);
    $auth = $this->configFactory->get('artsci_facilities.apis')->get('utility_alerts.auth');
    $this->apiKey = $auth['key'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function basePath(): string {
    return $this->configFactory->get('artsci_facilities.apis')->get('utility_alerts.endpoint');
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheIdBase() {
    return 'artsci_facilities_api_utility_alerts';
  }

  /**
   * {@inheritdoc}
   */
  public function addAuthToOptions(array &$options = []): void {
    if (!is_null($this->apiKey)) {
      $options = array_merge([
        'headers' => [
          'api-token' => $this->apiKey,
        ],
      ], $options);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAlerts(int $days = 14): array|false {
    return $this->get('', [
      'query' => [
        'days' => $days,
      ],
    ]);
  }

}
