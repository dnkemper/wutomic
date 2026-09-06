<?php

namespace Drupal\shared_content\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Provides Site Name field handler.
 *
 * @ViewsField("shared_content_site_name")
 *
 * @DCG
 * The plugin needs to be assigned to a specific table column through
 * hook_views_data() or hook_views_data_alter().
 * Put the following code to shared_content.views.inc file.
 * @code
 * function foo_views_data_alter(array &$data): void {
 *   $data['global']['shared_content_site_name'] = [
 *     'title' => t('Example'),
 *     'help' => t('Custom example field.'),
 *     'id' => 'shared_content_site_name',
 *   ];
 * }
 * @endcode
 */
class SiteName extends FieldPluginBase {

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
   *   The rendered output.
   */
  public function render(ResultRow $values) {
    return \Drupal::config('system.site')->get('name');
  }

}
