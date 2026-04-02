<?php

namespace Drupal\artsci_entities\Plugin\Field\FieldType;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;

/**
 * Provides a field type of AcademicUnits.
 *
 * @FieldType(
 *   id = "artsci_academic_units",
 *   label = @Translation("Academic Units"),
 *   description = @Translation("Reference academic unit configuration entities."),
 *   category = @Translation("reference"),
 *   default_formatter = "artsci_academic_units_formatter",
 *   default_widget = "artsci_academic_units_widget",
 *   list_class = "\Drupal\Core\Field\EntityReferenceFieldItemList",
 * )
 */
class AcademicUnits extends EntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
      'target_type' => 'artsci_academic_unit',
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $columns = [
      'target_id' => [
        'description' => 'The ID of the target configuration entity.',
        'type' => 'varchar_ascii',
        'length' => 255,
      ],
    ];

    return [
      'columns' => $columns,
      'indexes' => [
        'target_id' => ['target_id'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);

    $target_id_definition = DataReferenceTargetDefinition::create('string')
      ->setLabel(t('artsci_academic_unit ID'))
      ->setRequired(TRUE);

    $properties['target_id'] = $target_id_definition;
    $properties['entity'] = DataReferenceDefinition::create('entity')
      ->setLabel('artsci_academic_unit')
      ->setDescription(t('The referenced entity'))
      ->setComputed(TRUE)
      ->setReadOnly(FALSE)
      ->setTargetDefinition(EntityDataDefinition::create('artsci_academic_unit'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public static function getPreconfiguredOptions() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableOptions(?AccountInterface $account = NULL) {
    $au_storage = \Drupal::service('entity_type.manager')->getStorage('artsci_academic_unit');
    return $au_storage->getOptions(FALSE);
  }

}
