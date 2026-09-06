<?php

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an adaptive article header block.
 *
 * Renders the host article in a header view mode chosen by the media bundle
 * referenced in field_header_image: remote_video/video bundles render the
 * 'video' view mode (two-column hero), everything else renders 'header_image'
 * (one-column banner hero). An empty field falls back to 'header_image'.
 *
 * @Block(
 *   id = "article_header",
 *   admin_label = @Translation("Article header (adaptive)"),
 *   category = @Translation("Artsci"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"), required = FALSE)
 *   }
 * )
 */
class ArticleHeaderBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * Resolve the host node from context, falling back to the current route.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL if none is available.
   */
  protected function getNode(): ?NodeInterface {
    try {
      $node = $this->getContextValue('node');
    }
    catch (\Exception $e) {
      $node = NULL;
    }
    if (!$node instanceof NodeInterface) {
      $node = $this->routeMatch->getParameter('node');
    }
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Pick the header view mode based on the referenced header media bundle.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article node.
   *
   * @return string
   *   'video' for remote_video/video media, otherwise 'header_image'.
   */
  protected function headerViewMode(NodeInterface $node): string {
    if ($node->hasField('field_header_image') && !$node->get('field_header_image')->isEmpty()) {
      $media = $node->get('field_header_image')->entity;
      if ($media instanceof MediaInterface && in_array($media->bundle(), ['remote_video', 'video'], TRUE)) {
        return 'video';
      }
    }
    return 'header_image';
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getNode();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'article') {
      return [];
    }

    $view_mode = $this->headerViewMode($node);
    $build = $this->entityTypeManager->getViewBuilder('node')->view($node, $view_mode);

    // Vary with the node so swapping the header media re-renders the block.
    $build['#cache']['tags'] = Cache::mergeTags($build['#cache']['tags'] ?? [], $node->getCacheTags());

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $node = $this->getNode();
    if ($node instanceof NodeInterface) {
      return Cache::mergeTags(parent::getCacheTags(), $node->getCacheTags());
    }
    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
