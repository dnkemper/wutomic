<?php

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a block with links to share the current page on social media.
 */
#[Block(
  id: 'artsci_social_share',
  admin_label: new TranslatableMarkup('Social Share Links'),
  category: new TranslatableMarkup('Arts & Sciences'),
)]
class SocialShareBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a SocialShareBlock.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RequestStack $requestStack,
    protected RouteMatchInterface $routeMatch,
    protected TitleResolverInterface $titleResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get('title_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $request = $this->requestStack->getCurrentRequest();
    $share_url = $request->getSchemeAndHttpHost() . $request->getRequestUri();

    $route = $this->routeMatch->getRouteObject();
    $title = $route ? (string) $this->titleResolver->getTitle($request, $route) : '';

    $encoded_url = rawurlencode($share_url);
    $encoded_title = rawurlencode($title);

    $links = [
      'facebook' => [
        'label' => $this->t('Share on Facebook'),
        'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url,
        'icon' => 'fa-brands fa-facebook-f',
      ],
      'twitter' => [
        'label' => $this->t('Share on X'),
        'url' => 'https://twitter.com/intent/tweet?url=' . $encoded_url . '&text=' . $encoded_title,
        'icon' => 'fa-brands fa-x-twitter',
      ],
      'linkedin' => [
        'label' => $this->t('Share on LinkedIn'),
        'url' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded_url,
        'icon' => 'fa-brands fa-linkedin-in',
      ],
    ];

    return [
      '#theme' => 'artsci_social_share',
      '#heading' => $this->t('Share'),
      '#links' => $links,
      '#attached' => ['library' => ['artsci_core/social_share']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The share URL and title are derived from the current page.
    return Cache::mergeContexts(parent::getCacheContexts(), ['url']);
  }

}
