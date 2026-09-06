<?php

namespace Drupal\artsci_core\Entity;

use Drupal\node\Entity\Node;
use Drupal\views\ViewExecutable;

/**
 * Bundle-specific subclass of Node.
 */
abstract class NodeBundleBase extends Node implements RendersAsCardInterface {

  use RendersAsCardTrait;

  /**
   * Link directly to source field name, if it exists.
   *
   * No longer consulted by getNodeUrl(): a card links directly to its source
   * link whenever that field is populated. Retained so subclasses that still
   * declare it do not create dynamic properties.
   *
   * @var string|null
   */
  protected $sourceLinkDirect = NULL;

  /**
   * Source link field name, if it exists.
   *
   * @var string|null
   */
  protected $sourceLink = NULL;

  /**
   * The config settings name, if one exists.
   *
   * @var string
   */
  protected $configSettings = '';

  /**
   * {@inheritdoc}
   */
  public function buildCard(array &$build) {
    $this->buildCardStyles($build);
    // V2 pages still need field_teaser, everything else uses body summary.
    if ($build['#node']->values['type']['x-default'] != 'page') {
      $content = 'body';
    }
    // Add shared fields to card.
    if ($build) {
      $this->mapFieldsToCardBuild($build, [
        '#media' => 'field_image',
        '#title' => 'title',
        '#content' => $content,
      ]);
    }

    // Handle link directly to source functionality.
    $build['#url'] = $this->getNodeUrl();

    if (!empty($this->configSettings)) {
      // Determine whether the link indicator should be set.
      $config = \Drupal::configFactory()->getEditable($this->configSettings);
      $link_indicator = $config->get('show_teaser_link_indicator') ?? FALSE;
      $build['#link_indicator'] = $link_indicator;
    }

    // At this point, we aren't always aware if this is coming from a view.
    // If this is a view, there is a chance that the teasers can be displayed in
    // more than one place. Unsetting the cache keys prevents the same cached
    // version from being shared from instance to instance in case we are
    // hiding fields or overriding styles.
    if (isset($build['#cache']['keys'])) {
      unset($build['#cache']['keys']);
    }

    // Indicate sticky content if part of view with sticky sort applied.
    $view = $this->values['view'] ?? NULL;
    if ($view instanceof ViewExecutable) {
      $sorts = $view->getDisplay()->getOption('sorts');
      if (!empty($sorts)) {
        if (array_key_exists('sticky', $sorts) && $this->isSticky()) {
          $build['#pre_title'] = [
            '#markup' => '<span aria-label="Pinned content" role="presentation" class="fas fa-solid fa-thumbtack"></span> Pinned<span class="sr-only">&nbsp;content, custom sorted.</span>',
          ];
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultCardStyles(): array {
    return [
      'card_headline_style' => 'headline--serif',
      'card_media_position' => 'card--layout-right',
      'media_format' => 'media--widescreen',
      'media_size' => 'media--small',
      'border' => 'borderless',
    ];
  }

  /**
   * Helper function to construct link directly to source functionality.
   *
   * @return string|null
   *   The url used to link the view mode.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getNodeUrl(): ?string {
    $source_link = $this->sourceLink;

    // Link the card directly to its source link whenever that field is
    // populated. The former "link direct" toggle fields are no longer
    // consulted; presence of a link is sufficient.
    if (!is_null($source_link) && !$this->get($source_link)->isEmpty()) {
      return $this
        ->get($source_link)
        ?->get(0)
        ?->getUrl()
        ?->toString();
    }

    return !$this->isNew() ? $this->toUrl()->toString() : NULL;
  }

  /**
   * Get view modes that should be rendered as a card.
   *
   * @return string[]
   *   The list of view modes.
   */
  protected function getCardViewModes(): array {
    return ['teaser'];
  }

}
