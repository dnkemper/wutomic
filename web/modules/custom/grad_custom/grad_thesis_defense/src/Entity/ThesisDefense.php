<?php

namespace Drupal\grad_thesis_defense\Entity;

use Drupal\artsci_core\Entity\NodeBundleBase;
use Drupal\artsci_core\Entity\RendersAsCardInterface;

/**
 * Provides an interface for grad.artsci.edu thesis defense entries.
 */
class ThesisDefense extends NodeBundleBase implements RendersAsCardInterface {

  /**
   * {@inheritdoc}
   */
  public function buildCard(array &$build) {
    parent::buildCard($build);

    // Add the person library.
    $build['#attached']['library'][] = 'atomic_artsci/person';

    // Process additional card mappings.
    $this->mapFieldsToCardBuild($build, [
      '#meta' => [
        'field_thesis_defense_date',
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultCardStyles(): array {
    return array_merge(
      parent::getDefaultCardStyles(),
      [
        'card_media_position' => 'card--layout-left',
        'border' => 'borderless',
        'card_headline_style' => 'default',
      ]
    );
  }

}
