<?php

namespace Drupal\artsci_articles\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * An image gallery jump link block.
 *
 * @Block(
 *   id = "galleryjumplink_block",
 *   admin_label = @Translation("Gallery Jump Link Block"),
 *   category = @Translation("Restricted")
 * )
 */
class GalleryJumpLink extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['label_display' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $markup = '<span role="presentation" class="fas fa-image"></span> <a href="#gallery">Image Gallery</a>';

    return [
      '#markup' => $markup,
      '#attributes' => [
        'class' => [
          'gallery-jump-link',
        ],
      ],
      '#attached' => [
        'library' => [
          'artsci_articles/gallery-jump-link',
        ],
      ],
    ];
  }

}
