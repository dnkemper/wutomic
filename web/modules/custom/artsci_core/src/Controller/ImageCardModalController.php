<?php

namespace Drupal\artsci_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders an image_card node for display inside a modal lightbox.
 *
 * Used by the image_card teaser card: clicking the card opens this route in a
 * Drupal AJAX modal (core/drupal.dialog.ajax) showing the node's full view
 * display.
 */
class ImageCardModalController extends ControllerBase {

  /**
   * Renders the image_card node in the full view mode.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being viewed.
   *
   * @return array
   *   A render array for the node's full view display.
   */
  public function view(NodeInterface $node): array {
    if ($node->bundle() !== 'image_card') {
      throw new NotFoundHttpException();
    }

    $build = $this->entityTypeManager()
      ->getViewBuilder('node')
      ->view($node, 'full');

    // Scope the dialog content so modal-specific CSS can target it without
    // affecting the standalone page render.
    $build['#attributes']['class'][] = 'image-card-lightbox__content';

    return $build;
  }

  /**
   * Provides the modal/dialog title.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being viewed.
   *
   * @return string
   *   The node title.
   */
  public function title(NodeInterface $node): string {
    return $node->label();
  }

}
