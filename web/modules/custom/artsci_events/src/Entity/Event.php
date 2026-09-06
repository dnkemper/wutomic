<?php

namespace Drupal\artsci_events\Entity;

use Drupal\artsci_core\Entity\NodeBundleBase;
use Drupal\artsci_core\Entity\RendersAsCardInterface;

/**
 * Provides an interface for event entries.
 */
class Event extends NodeBundleBase implements RendersAsCardInterface {

  /**
   * If entity has link directly to source field.
   *
   * @var string|null
   *   field name or null.
   */
  protected $sourceLinkDirect = 'field_event_series_link_direct';

  /**
   * If entity has source link field.
   *
   * @var string|null
   *   field name or null.
   */
  protected $sourceLink = 'field_event_series_link';

  /**
   * {@inheritdoc}
   */
public function buildCard(array &$build) {
      if ($this->get('field_image')->isEmpty()) {
      $build['field_image'] = [
        [
          '#type' => 'image_empty_article',
          '#alt' => $this->getTitle(),
        ],
      ];
    }
    parent::buildCard($build);


  // Check for hidden fields
  $hide_fields = $build['#hide_fields'] ?? [];

  // Map image field if not hidden
  if (!in_array('field_image', $hide_fields)) {
    $this->mapFieldsToCardBuild($build, [
      '#media' => 'field_image',
    ]);
  }

  // Map event-specific meta fields
  $meta_fields = [];
  
  if (!in_array('field_event_when', $hide_fields)) {
    $meta_fields[] = 'field_event_when';
  }
  if (!in_array('field_event_location', $hide_fields)) {
    $meta_fields[] = 'field_event_location';
  }
  if (!in_array('field_event_category', $hide_fields)) {
    $meta_fields[] = 'field_event_category';
  }
  
  if (!empty($meta_fields)) {
    $this->mapFieldsToCardBuild($build, [
      '#meta' => $meta_fields,
    ]);
  }

  $build['#url'] = $this->getNodeUrl();
}
  /**
   * {@inheritdoc}
   */
  public function getDefaultCardStyles(): array {
    return array_merge(
      parent::getDefaultCardStyles(),
      [
        'card_media_position' => 'card--layout-stacked',
        'media_format' => 'media--widescreen media--border',
        'media_size' => 'media--large',
      ]
    );
  }

}
