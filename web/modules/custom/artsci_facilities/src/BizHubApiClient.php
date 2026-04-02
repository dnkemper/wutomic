<?php

namespace Drupal\artsci_facilities;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\artsci_core\ApiAuthBasicTrait;
use Drupal\artsci_core\ApiClientBase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The BizHub API service.
 */
class BizHubApiClient extends ApiClientBase implements BizHubApiClientInterface {

  use ApiAuthBasicTrait;

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
    $auth = $this->configFactory->get('artsci_facilities.apis')->get('bizhub.auth');
    $this->username = $auth['user'] ?? NULL;
    $this->password = $auth['pass'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function basePath(): string {
    return $this->configFactory->get('artsci_facilities.apis')->get('bizhub.endpoint');
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheIdBase() {
    return 'artsci_facilities_api_bizhub';
  }

  /**
   * {@inheritdoc}
   */
  public function getBuildings(): array|bool {
    return $this->get('buildings');
  }

  /**
   * {@inheritdoc}
   */
  public function getBuilding($building_number): \stdClass|bool {
    return $this->get('building', [
      'query' => [
        'bldgnumber' => $building_number,
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getBuildingCoordinators(): array|bool {
    return $this->get('bldgCoordinators');
  }

}
