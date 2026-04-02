<?php

namespace Drupal\artsci\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * A 'Powered by Artsci' block.
 *
 * This is really to establish 'Custom' category for config management purposes.
 *
 * @Block(
 *   id = "artsci_block",
 *   admin_label = @Translation("Powered by Artsci"),
 *   category = @Translation("Site custom")
 * )
 */
class ArtsciBlock extends BlockBase {

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
    return [
      '#markup' =>
      '<span>' . $this->t('Powered by <a href=":link">Artsci</a>',
          [':link' => 'https://artsci.artsci.edu']
      ) . '</span>',
    ];
  }

}
