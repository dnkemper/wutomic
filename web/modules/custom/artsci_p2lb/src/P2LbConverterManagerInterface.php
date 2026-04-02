<?php

namespace Drupal\artsci_p2lb;

use Drupal\artsci_pages\Entity\Page;

/**
 * Converter manager interface.
 */
interface P2LbConverterManagerInterface {

  /**
   * Instantiates a converter object for a page.
   *
   * @param \Drupal\artsci_pages\Entity\Page $page
   *   The page being converted.
   *
   * @return \Drupal\artsci_p2lb\P2LbConverter
   *   The converter object.
   */
  public function createConverter(Page $page): P2LbConverter;

}
