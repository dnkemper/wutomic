<?php

namespace Drush\Commands;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\PermissionHandler;

/**
 * A Drush command file.
 *
 * @package Drupal\rip\Commands
 */
class UserPermissions extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The permission handler.
   *
   * @var \Drupal\user\PermissionHandler
   */
  protected PermissionHandler $permissionHandler;

  /**
   * The entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $roleStorage;

  /**
   * RipCommands constructor.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(
      EntityTypeManagerInterface $entity_type_manager,
      PermissionHandler $permission_handler) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->permissionHandler = $permission_handler;
    $this->roleStorage = $entity_type_manager->getStorage('user_role');
  }

  /**
   * Removes the invalid permissions from roles.
   *
   * @command remove-invalid-permissions
   * @aliases rip
   * @usage remove-invalid-permissions
   *   Removes invalid permissions.
   *
   * @return void
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function removeInvalidPermissions(): void {
    $permissions = array_keys($this->permissionHandler->getPermissions());
    /** @var \Drupal\user\Entity\Role[] $roles */
    $roles = $this->roleStorage->loadMultiple();

    /** @var \Drupal\user\RoleInterface $role */
    foreach ($roles as $role) {
      $role_permissions = $role->getPermissions();
      $diff_permissions_in_role = array_diff($role_permissions, $permissions);

      if ($diff_permissions_in_role) {

        foreach ($diff_permissions_in_role as $permission) {

          $confirm = $this->io()->confirm('Remove ' . $permission . ' for ' . $role->id() . '?');
          if ($confirm) {
            $this->io()->note('Removed');
            $role->revokePermission($permission);
          }
        }

        $role->save();
      }
    }

    $this->logger()?->success('Invalid permissions removed.');
  }

}
