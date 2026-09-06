<?php

namespace Drupal\artsci_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\webform\Plugin\WebformSourceEntity\QueryStringWebformSourceEntity;
use Drupal\webform\Plugin\Field\FieldFormatter\WebformEntityReferenceFormatterBase;

/**
 * Plugin implementation of the 'Webform url' formatter.
 *
 * @FieldFormatter(
 *   id = "artsci_core_webform_entity_reference_absolute_url",
 *   label = @Translation("Absolute URL"),
 *   description = @Translation("Display Absolute URL to the referenced webform."),
 *   field_types = {
 *     "webform"
 *   }
 * )
 */
class WebformEntityReferenceAbsoluteUrlFormatter extends WebformEntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $source_entity = $items->getEntity();

    $elements = [];

    /** @var \Drupal\webform\WebformInterface[] $entities */
    $entities = $this->getEntitiesToView($items, $langcode);

    foreach ($entities as $delta => $entity) {
      $link_options = QueryStringWebformSourceEntity::getRouteOptionsQuery($source_entity);

      $link_options['absolute'] = TRUE;

      $link = [
        '#plain_text' => $entity->toUrl('canonical', $link_options)->toString(),
      ];

      $elements[$delta] = $link;

      $this->setCacheContext($elements[$delta], $entity, $items[$delta]);
    }

    return $elements;
  }

}
