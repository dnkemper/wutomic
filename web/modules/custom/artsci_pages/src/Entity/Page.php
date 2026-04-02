<?php

namespace Drupal\artsci_pages\Entity;

use Drupal\artsci_core\Entity\NodeBundleBase;

/**
 * Provides an interface for page entries.
 */
class Page extends NodeBundleBase {

  /**
   * {@inheritdoc}
   */
  protected $configSettings = 'artsci_pages.settings';

}
