<?php

namespace Drupal\artsci_core\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\EntityLink;
use Drupal\views\ResultRow;

/**
 * Field handler to present a link to download the media file.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("media_download_link")
 */
class DownloadLink extends EntityLink {

  /**
   * {@inheritdoc}
   */
  protected function getUrlInfo(ResultRow $row) {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->getEntity($row);
    return Url::fromRoute('artsci_core.download', ['media' => $media->id()])->setAbsolute($this->options['absolute'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultLabel() {
    return $this->t('Download');
  }

}
