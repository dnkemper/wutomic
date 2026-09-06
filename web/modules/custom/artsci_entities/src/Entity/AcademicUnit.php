<?php

namespace Drupal\artsci_entities\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\artsci_entities\AcademicUnitInterface;

/**
 * Defines the academic unit entity type.
 *
 * @ConfigEntityType(
 *   id = "artsci_academic_unit",
 *   label = @Translation("Academic Unit"),
 *   label_collection = @Translation("Academic Units"),
 *   label_singular = @Translation("academic unit"),
 *   label_plural = @Translation("academic units"),
 *   label_count = @PluralTranslation(
 *     singular = "@count academic unit",
 *     plural = "@count academic units",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\artsci_entities\AcademicUnitListBuilder",
 *     "form" = {
 *       "add" = "Drupal\artsci_entities\Form\AcademicUnitForm",
 *       "edit" = "Drupal\artsci_entities\Form\AcademicUnitForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "academic_unit",
 *   admin_permission = "administer artsci_academic_unit",
 *   links = {
 *     "collection" = "/admin/structure/artsci-academic-unit",
 *     "add-form" = "/admin/structure/artsci-academic-unit/add",
 *     "edit-form" = "/admin/structure/artsci-academic-unit/{artsci_academic_unit}",
 *     "delete-form" = "/admin/structure/artsci-academic-unit/{artsci_academic_unit}/delete"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "type",
 *     "homepage"
 *   }
 * )
 */
class AcademicUnit extends ConfigEntityBase implements AcademicUnitInterface {

  /**
   * The academic unit ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The academic unit label.
   *
   * @var string
   */
  protected $label;

  /**
   * The academic unit status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The academic unit type.
   *
   * @var string
   */
  protected $type;

  /**
   * A link to the academic unit homepage.
   *
   * @var string
   */
  protected $homepage;

}
