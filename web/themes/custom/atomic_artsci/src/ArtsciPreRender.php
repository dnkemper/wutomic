<?php

namespace Drupal\atomic_artsci;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Implements trusted prerender callbacks for the ARTSCI Base theme.
 *
 * @internal
 */
class ArtsciPreRender implements TrustedCallbackInterface {

  /**
   * Prerender callback for status_messages placeholder.
   *
   * @param array $element
   *   A renderable array.
   *
   * @return array
   *   The updated renderable array containing the placeholder.
   */
  public static function messagePlaceholder(array $element) {
    // Set up the fallback placeholder with ARTSCI-specific attributes.
    if (isset($element['fallback']['#markup'])) {
      $element['fallback']['#markup'] = '<div data-drupal-messages-fallback class="hidden messages-list artsci-messages-container"></div>';
    }
    $element['#attached']['library'][] = 'atomic_artsci/status-messages';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'messagePlaceholder',
    ];
  }

}
