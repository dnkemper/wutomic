<?php

namespace Drupal\layout_builder_custom\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Link;
use Drupal\webform\Plugin\WebformSourceEntity\QueryStringWebformSourceEntity;
use Drupal\webform\Plugin\Field\FieldFormatter\WebformEntityReferenceFormatterBase;

/**
 * Plugin implementation of the 'Webform RSVP button' formatter.
 *
 * @FieldFormatter(
 *   id = "layout_builder_custom_webform_entity_reference_absolute_url",
 *   label = @Translation("RSVP Button"),
 *   description = @Translation("Display RSVP button linking to the referenced webform."),
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
      $link_options['attributes'] = [
        'class' => ['bttn', 'bttn--primary--reverse'],
      ];

      $url = $entity->toUrl('canonical', $link_options);
      $link = Link::fromTextAndUrl($this->t('RSVP'), $url);

      $elements[$delta] = [
        '#type' => 'container',
        'link' => $link->toRenderable(),
      ];

      $this->setCacheContext($elements[$delta], $entity, $items[$delta]);
    }

    return $elements;
  }

}
