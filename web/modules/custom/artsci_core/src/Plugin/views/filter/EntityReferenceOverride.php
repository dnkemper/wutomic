<?php

namespace Drupal\artsci_core\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\EntityReference;

/**
 * Custom override of EntityReference filter to increase the select limit.
 */
class EntityReferenceOverride extends EntityReference {
  public const WIDGET_SELECT_LIMIT = 500;

}
