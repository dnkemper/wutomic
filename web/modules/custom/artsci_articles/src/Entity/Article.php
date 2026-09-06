<?php

namespace Drupal\artsci_articles\Entity;

use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\artsci_core\Entity\NodeBundleBase;
use Drupal\artsci_core\Entity\RendersAsCardInterface;

/**
 * Provides an interface for article entries.
 */
class Article extends NodeBundleBase implements ArticleInterface, RendersAsCardInterface {

  /**
   * {@inheritdoc}
   */
  protected $sourceLink = 'field_article_source_link';

  /**
   * {@inheritdoc}
   */
  protected $configSettings = 'artsci_articles.settings';

  /**
   * {@inheritdoc}
   */
  public function buildCard(array &$build) {
    if ($this->get('field_image')->isEmpty()) {
      $build['field_image'] = [
        [
          '#type' => 'image_empty_article',
          '#alt' => $this->getTitle(),
        ],
      ];
    }
    parent::buildCard($build);
    // Process additional card mappings.

    // Add a byline
    // Check which fields have been hidden. getHideFields() reads both the build
    // (views rows) and the drupal_static set for Layout Builder list blocks, so
    // it reflects hides made in either context; $build['#hide_fields'] alone is
    // empty inside a list block.
    $hide_fields = $this->getHideFields($build);
    // If the source link is hidden or not set, its value is NULL.
    $source_link = !in_array('field_article_source_link', $hide_fields) ? $this->get('field_article_source_link')->uri : NULL;
    // If the source org is hidden or not set, its value is NULL.
    // $source_org = !in_array('field_article_source_org', $hide_fields) ? $this->get('field_article_source_org')->value : NULL;
    // If the source author is hidden or not set, its value is NULL.
    $source_author = in_array('field_tags', $hide_fields) ? NULL : !$this->get('field_tags')->isEmpty();
    // Hide the tags whenever the description (body) is hidden. Otherwise, limit
    // the tags to only the first item before mapping into the card meta.
    if (in_array('field_tags', $hide_fields)) {
      unset($build['field_tags']);
    }
    elseif (isset($build['field_tags'])) {
      $deltas = Element::children($build['field_tags']);
      // Keep the first delta; drop the rest.
      array_shift($deltas);
      foreach ($deltas as $delta) {
        unset($build['field_tags'][$delta]);
      }
    }
    $this->mapFieldsToCardBuild($build, [
      '#meta' => [
        'field_tags',
      ],
    ]);
    // Add a wrapper div with class "fa-field-item"
    // if author field is not empty or if it's hidden in hide_fields.
    // if ((!$this->get('field_tags')->isEmpty() && $source_author)) {
    //   $build['#meta']['#prefix'] = '<div class="fa-field-item">';
    //   $build['#meta']['#suffix'] = '</div>';
    // }
    // $source_author = in_array('field_tags', $hide_fields) ? NULL : !$this->get('field_tags')->isEmpty();

    // The link text should be the org, if set, or the source link, if set. It
    // will be NULL otherwise.
    $source_output = $source_org ?? $source_link;

    // If there is a source link, then turn the link text into a link.
    // Otherwise, it will just be rendered as text.
    if ($source_output) {
      if ($source_link) {
        $source_output = Link::fromTextAndUrl($source_output, Url::fromUri($source_link))
          ->toString();
      }
      // Add the output for whatever we've generated up to this point.
      $build['#meta']['byline'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#weight' => 99,
        '#attributes' => [
          'class' => [
            'field--article-byline',
          ],
        ],
        'source' => [
          '#markup' => $source_output,
        ],
      ];
    }

    // Add the published date if it has not been hidden.
    if (!in_array('created', $hide_fields)) {
      $created = $this->get('created')->value;
      $date = \Drupal::service('date.formatter')->format($created, 'article');
      $build['#subtitle'] = $date;
    }
  }

}
