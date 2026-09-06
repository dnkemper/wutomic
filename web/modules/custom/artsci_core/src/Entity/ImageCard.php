<?php

namespace Drupal\artsci_core\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;

/**
 * Bundle class for image_card nodes.
 *
 * Renders image_card nodes as cards in card view modes (e.g. teaser, as used
 * by the image_card_list_block view). This enables the ListBlock "Hide fields"
 * options and the Layout Builder Styles card formatting, matching the behavior
 * of the other *_list_block views (article, page, person).
 *
 * Clicking the teaser card opens the node's full view display in a modal
 * lightbox rather than navigating.
 */
class ImageCard extends NodeBundleBase implements RendersAsCardInterface {

  /**
   * {@inheritdoc}
   */
  public function buildCard(array &$build) {
    parent::buildCard($build);

    // The teaser display does not render the title field, so make sure the card
    // still has a heading. The heading also serves as the click target.
    if (empty($build['#title'])) {
      $build['#title'] = ['#plain_text' => $this->label()];
    }

    // Open the node's full view display in a modal lightbox instead of
    // navigating. click-a11y.js makes the whole card fire this link, and the
    // headline link carries the use-ajax dialog attributes.
    if (!$this->isNew()) {
      $url = Url::fromRoute('artsci_core.image_card.modal', [
        'node' => $this->id(),
      ])->toString();

      $build['#links'] = [
        [
          'link_url' => $url,
          // Intentionally empty: a non-empty value renders a duplicate button.
          'link_text' => NULL,
          'link_attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => 880,
              'dialogClass' => 'image-card-lightbox',
            ]),
          ],
        ],
      ];

      $build['#attached']['library'][] = 'core/drupal.dialog.ajax';
    }
  }

}
