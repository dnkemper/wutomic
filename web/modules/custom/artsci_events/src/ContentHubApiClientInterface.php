<?php

namespace Drupal\artsci_events;

use Drupal\artsci_core\ApiClientInterface;

/**
 * A Content Hub API client interface.
 */
interface ContentHubApiClientInterface extends ApiClientInterface {

  /**
   * Get all events.
   *
   * @return \stdClass|bool
   *   The events object.
   */
  public function getEvents(): \stdClass|bool;

  /**
   * Get all events with instances.
   *
   * @return array|bool
   *   The array of event objects.
   */
  public function getEventInstances(): array|bool;

}
