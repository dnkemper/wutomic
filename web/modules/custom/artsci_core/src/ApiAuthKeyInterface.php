<?php

namespace Drupal\artsci_core;

/**
 * An interface for API key authentication.
 */
interface ApiAuthKeyInterface {

  /**
   * Get the API key.
   */
  public function getKey(): string|NULL;

  /**
   * Set the API key.
   *
   * @param string $key
   *   The API key being set.
   *
   * @return \Drupal\artsci_core\ApiClientInterface
   *   The DispatchApiClientInterface object.
   */
  public function setKey(string $key): static;

}
