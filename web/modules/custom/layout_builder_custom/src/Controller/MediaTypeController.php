<?php

namespace Drupal\layout_builder_custom\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns media type information.
 */
class MediaTypeController extends ControllerBase {

  /**
   * Returns the bundle type of a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the media bundle.
   */
  public function getMediaType(MediaInterface $media) {
    return new JsonResponse([
      'bundle' => $media->bundle(),
      'id' => $media->id(),
    ]);
  }

}
