<?php

namespace Drupal\artsci_core\Element;

use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Provides a render element to display a card.
 *
 * @RenderElement("card")
 */
class Card extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#attached' => [
        'library' => [
          'atomic_artsci/card',
        ],
      ],
      '#theme' => 'card',
      '#attributes' => [],
      '#media' => NULL,
      '#media_attributes' => [],
      '#pre_title' => NULL,
      '#title' => NULL,
      '#title_heading_size' => 'h2',
      '#subtitle' => NULL,
      '#meta' => [],
      '#content' => NULL,
      '#links' => [],
      '#link_indicator' => FALSE,
    ];
  }

  /**
   * Filters a list of styles to just those used by cards.
   *
   * @param array $styles
   *   The styles being filtered.
   *
   * @return array
   *   The filtered styles.
   */
  public static function filterCardStyles(array $styles): array {
    $filtered_styles = [];
    foreach ($styles as $key => $style) {
      foreach ([
        'bg',
        'card',
        'media',
        'borderless',
        'hide',
        'headline',
        'bttn',
      ] as $check) {
        if (str_starts_with($style, $check)) {
          $filtered_styles[$key] = $style;
        }
      }
    }

    if (!isset($filtered_styles['border'])) {
      $filtered_styles['border'] = '';
    }

    return $filtered_styles;
  }

}
