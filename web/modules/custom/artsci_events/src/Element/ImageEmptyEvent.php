<?php

namespace Drupal\artsci_events\Element;

use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Provides a responsive image element.
 *
 * @RenderElement("image_empty_event")
 */
class ImageEmptyEvent extends RenderElementBase {

  const RESPONSIVE_STYLE_DEFAULT = 'large__square';

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#theme' => 'imagecache_external_responsive',
      '#responsive_image_style_id' => static::RESPONSIVE_STYLE_DEFAULT,
      '#pre_render' => [
        [$class, 'preRenderImageEmptyEvent'],
      ],
    ];
  }

  /**
   * Adds form element theming to details.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   details.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderImageEmptyEvent(array $element): array {
    $path = \Drupal::service('extension.list.theme')->getPath('atomic_artsci');
    $path = \Drupal::service('file_url_generator')->generateAbsoluteString($path . '/assets/images/default-event.png');
    $element['#uri'] = $path;
    $element['#attributes'] = [
      'data-lazy' => TRUE,
      'alt' => t('@title', [
        '@title' => $element['#alt'] ?? 'No picture provided',
      ]),
    ];

    return $element;
  }

}
