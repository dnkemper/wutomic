<?php

namespace Drupal\shared_content\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\file\Entity\File;

/**
 * Provides Site Name field handler.
 *
 * @ViewsField("shared_content_site_image")
 *
 * @DCG
 * The plugin needs to be assigned to a specific table column through
 * hook_views_data() or hook_views_data_alter().
 * Put the following code to shared_content.views.inc file.
 * @code
 * function foo_views_data_alter(array &$data): void {
 *   $data['global']['shared_content_site_image'] = [
 *     'title' => t('Site Image'),
 *     'help' => t('Custom site image field.'),
 *     'id' => 'shared_content_site_image',
 *   ];
 * }
 * @endcode
 */
class SiteHost extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // For non-existent columns (i.e. computed fields) this method must be
    // empty.
  }

  /**
   * Defines how the field is displayed.
   *
   * @param \Drupal\views\ResultRow $values
   *   The result row.
   *
   * @return mixed|string
   *   The absolute URL of the department image, or empty string.
   */
  public function render(ResultRow $values) {
    $fid = theme_get_setting('department_image_upload', 'washu');

    // If it's an array, get the first ID. If not, just use the value.
    if (is_array($fid)) {
      $fid = reset($fid);
    }

    // Safety check.
    if (!$fid || !is_numeric($fid)) {
      return '';
    }

    $file = File::load($fid);
    if (!$file) {
      return '';
    }

    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
    $file_url_generator = \Drupal::service('file_url_generator');
    return $file_url_generator->generateAbsoluteString($file->getFileUri()) ?? '';
  }

}
