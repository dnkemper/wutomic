<?php

namespace Drupal\artsci_core\Entity;

use Drupal\Core\Render\Element;

/**
 * Provides functionality related to rendering entities as cards.
 */
trait RendersAsCardTrait {

  /**
   * {@inheritdoc}
   */
  public function addCardBuildInfo(array &$build) {
    // Set the type to card.
    $build['#type'] = 'card';

    // If there is an existing '#theme' setting, unset it.
    if (isset($build['#theme'])) {
      unset($build['#theme']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultCardStyles(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildCardStyles(array &$build) {
    // Check for override styles from build array first (for non-view contexts).
    $override_styles = $build['#override_styles'] ?? [];

    // If not in build, check drupal_static (set by views_pre_render).
    if (empty($override_styles)) {
      $static_styles = &drupal_static('layout_builder_custom_card_override_styles');
      if (!empty($static_styles)) {
        $override_styles = $static_styles;
      }
    }

    // Loop through combined default and override styles and add them.
    foreach ([
      ...$this->getDefaultCardStyles(),
      ...$override_styles,
    ] as $style) {
      $build['#attributes']['class'][] = $style;
    }
  }

  /**
   * Get hide_fields from build array or view context.
   *
   * @param array $build
   *   A renderable array representing the entity content.
   *
   * @return array
   *   The list of fields to hide.
   */
  protected function getHideFields(array &$build): array {
    $hide_fields = $build['#hide_fields'] ?? [];

    // If not in build, check drupal_static (set by views_pre_render).
    if (empty($hide_fields)) {
      $static_hide_fields = &drupal_static('layout_builder_custom_card_hide_fields');
      if (!empty($static_hide_fields)) {
        $hide_fields = $static_hide_fields;
      }
    }

    return $hide_fields;
  }

  /**
   * Map build fields to card properties.
   *
   * @param array $build
   *   A renderable array representing the entity content.
   * @param array $mapping
   *   Array of field names.
   */
  protected function mapFieldsToCardBuild(array &$build, array $mapping): void {
    $hide_fields = $this->getHideFields($build);

    // Map fields to the card parts.
    foreach ($mapping as $prop => $fields) {
      // If the prop hasn't been added yet, add it.
      if (!isset($build[$prop])) {
        $build[$prop] = [];
      }
      // For convenience, fields can be passed as strings. Convert strings to
      // an array.
      if (!is_array($fields)) {
        $fields = [$fields];
      }
      // Loop through fields.
      foreach ($fields as $field_name) {
        // If the field exists, it can be rendered, and should not be hidden,
        // add it to the appropriate prop.
        if (isset($build[$field_name])) {
          if (count(Element::children($build[$field_name])) > 0 && !in_array($field_name, $hide_fields)) {
            $build[$prop][$field_name] = $build[$field_name];
          }
          // Unset the field, so it doesn't get accidentally displayed.
          unset($build[$field_name]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewModeShouldRenderAsCard(string $view_mode): bool {
    if (empty($this->getCardViewModes())) {
      return TRUE;
    }

    return in_array($view_mode, $this->getCardViewModes());
  }

  /**
   * Get view modes that should be rendered as a card.
   *
   * @return string[]
   *   The list of view modes.
   */
  protected function getCardViewModes(): array {
    return [];
  }

}
