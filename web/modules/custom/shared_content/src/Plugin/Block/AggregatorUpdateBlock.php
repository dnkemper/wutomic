<?php

namespace Drupal\shared_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\shared_content\Service\SharedContentFeedRefresherForce;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block to force refresh aggregator feeds and update imported nodes.
 *
 * @Block(
 *   id = "aggregator_update_block",
 *   admin_label = @Translation("Aggregator Update Block")
 * )
 */
class AggregatorUpdateBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The force feed refresher service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce
   */
  protected SharedContentFeedRefresherForce $feedRefresherForce;

  /**
   * The current path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * Constructs an AggregatorUpdateBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\shared_content\Service\SharedContentFeedRefresherForce $feed_refresher_force
   *   The force feed refresher service.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SharedContentFeedRefresherForce $feed_refresher_force,
    CurrentPathStack $current_path,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->feedRefresherForce = $feed_refresher_force;
    $this->currentPath = $current_path;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('shared_content.feed_refresher_force'),
      $container->get('path.current')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $current_path = $this->currentPath->getPath();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['shared-content-actions']],

      'refresh_feeds' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['shared-content-action']],
        'heading' => [
          '#markup' => '<h3>' . $this->t('Option 1: Refresh Feeds') . '</h3>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('This option pulls in all of the new and updated shared content and makes it available to import. This option will take 3-5 minutes to run.') . '</p>',
        ],
        'button' => [
          '#type' => 'link',
          '#title' => $this->t('Refresh Feeds'),
          '#url' => Url::fromRoute('shared_content.refresh_all_feeds', [], [
            'query' => ['destination' => $current_path],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],

      'refresh_nodes' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['shared-content-action']],
        'heading' => [
          '#markup' => '<h3>' . $this->t('Option 2: Update Imported Content') . '</h3>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('This option updates all of your imported content to reflect any changes. It also removes any shared content that has been deleted or is no longer available.') . '</p>',
        ],
        'button' => [
          '#type' => 'link',
          '#title' => $this->t('Sync Content'),
          '#url' => Url::fromRoute('shared_content.force_refresh_all', [], [
            'query' => ['destination' => $current_path],
          ]),
          '#attributes' => ['class' => ['button', 'button--secondary']],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
