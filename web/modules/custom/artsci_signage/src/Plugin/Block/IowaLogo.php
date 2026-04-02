<?php

namespace Drupal\artsci_signage\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * A Artsci Logo block.
 *
 * @Block(
 *   id = "iowalogo_block",
 *   admin_label = @Translation("Iowa Logo Block"),
 *   category = @Translation("Site custom")
 * )
 */
class IowaLogo extends BlockBase {

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
      '#type' => 'inline_template',
      '#attached' => [
        'library' => [
          'atomic_artsci/logo',
        ],
      ],
      '#template' => '
        {% include "@atomic_artsci/artsci/logo.twig" with {
          path: "https://artsci.edu",
          logo_classes: "logo--tab",
        } %}
        ',
    ];
  }

}
