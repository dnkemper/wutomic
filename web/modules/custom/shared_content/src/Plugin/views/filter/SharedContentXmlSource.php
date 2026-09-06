<?php

namespace Drupal\shared_content\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Filter by shared content XML source hostname.
 *
 * @ViewsFilter("shared_content_xml_source")
 */
class SharedContentXmlSource extends FilterPluginBase {

  /**
   * Disable the operator form.
   *
   * @var bool
   */
  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
  public $no_operator = TRUE;

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['value'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    $identifier = $this->options['expose']['identifier'];
    $form[$identifier] = [
      '#type' => 'select',
      '#title' => $this->t('Source Site'),
      '#options' => $this->getHostnameOptions(),
      '#empty_option' => $this->t('- Any -'),
      '#default_value' => $this->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $identifier = $this->options['expose']['identifier'];
    if (!empty($input[$identifier])) {
      $this->value = $input[$identifier];
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    if (empty($this->value)) {
      return;
    }

    $this->ensureMyTable();
    $field = "$this->tableAlias.$this->realField";
    $connection = Database::getConnection();

    $this->query->addWhere(
      $this->options['group'],
      $field,
      '%' . $connection->escapeLike($this->value) . '%',
      'LIKE'
    );
  }

  /**
   * Returns hostname options from distinct XML feed URLs.
   *
   * @return array
   *   Associative array of hostname => hostname.
   */
  protected function getHostnameOptions() {
    $connection = Database::getConnection();
    $results = $connection->select('node__field_shared_content_xml', 'f')
      ->fields('f', ['field_shared_content_xml_value'])
      ->distinct()
      ->orderBy('field_shared_content_xml_value')
      ->execute()
      ->fetchCol();

    $options = [];
    foreach ($results as $url) {
      $host = parse_url($url, PHP_URL_HOST);
      if (!empty($host)) {
        $options[$host] = $host;
      }
    }

    ksort($options);
    return $options;
  }

}
