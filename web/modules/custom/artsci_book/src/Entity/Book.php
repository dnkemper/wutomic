<?php

namespace Drupal\artsci_book\Entity;

use Drupal\artsci_core\Entity\NodeBundleBase;
use Drupal\artsci_core\Entity\RendersAsCardInterface;

/**
 * Provides the bundle class for book entries.
 *
 * Mirrors the Article and Event bundle classes: extends NodeBundleBase so the
 * shared card pipeline applies. The parent buildCard() maps the cover
 * (field_image) to #media, the title to #title, and the body to #content, and
 * applies the card style classes returned by getDefaultCardStyles(). This
 * class layers on book-specific subtitle and meta handling and a portrait
 * cover treatment.
 */
class Book extends NodeBundleBase implements BookInterface, RendersAsCardInterface {

  /**
   * {@inheritdoc}
   */
  public function buildCard(array &$build) {
    // Base handles #media (cover), #title, #content (body), card styles, #url.
    parent::buildCard($build);

    $hide_fields = $build['#hide_fields'] ?? [];

    // Subtitle from the book subtitle field.
    if (!in_array('field_book_subtitle', $hide_fields)
      && $this->hasField('field_book_subtitle')
      && !$this->get('field_book_subtitle')->isEmpty()) {
      $build['#subtitle'] = $this->get('field_book_subtitle')->value;
    }

    // Map meta fields onto the card. These only render if the book teaser
    // view mode actually exposes them (mapFieldsToCardBuild pulls from the
    // already-built render array), so the teaser display gates what shows.
    $meta_fields = [];
    foreach (['field_book_author', 'field_book_publication_date', 'book_tags'] as $field) {
      if (!in_array($field, $hide_fields)) {
        $meta_fields[] = $field;
      }
    }
    if (!empty($meta_fields)) {
      $this->mapFieldsToCardBuild($build, [
        '#meta' => $meta_fields,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultCardStyles(): array {
    // Book covers are portrait (2:3). Default to a cover-left card; keeps the
    // serif headline and borderless treatment from the base.
    return array_merge(
      parent::getDefaultCardStyles(),
      [
        'card_media_position' => 'card--layout-left',
        'media_format' => 'media--portrait',
        'media_size' => 'media--small',
      ]
    );
  }

}
