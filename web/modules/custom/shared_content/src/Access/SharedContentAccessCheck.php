<?php

namespace Drupal\shared_content\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Checks access for Force Update tab.
 */
class SharedContentAccessCheck implements AccessInterface {

  /**
   * Checks access for the force update route.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node, AccountInterface $account) {
    // Check if node has the field.
    if (!$node->hasField('field_shared_content_xml')) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    // Check if field is not empty.
    if ($node->get('field_shared_content_xml')->isEmpty()) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    // Check user has permission.
    return AccessResult::allowedIf($node->access('view', $account))
      ->addCacheableDependency($node)
      ->cachePerUser()
      ->cachePerPermissions();
  }

}
