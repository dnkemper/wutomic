<?php

namespace Drupal\layout_builder_custom\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\artsci_core\HeadlineHelper;

/**
 * Plugin implementation of the 'artsci_headline_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "artsci_headline_formatter",
 *   label = @Translation("Artsci Headline Field Type Formatter"),
 *   field_types = {
 *     "artsci_headline"
 *   }
 * )
 */
class ArtsciHeadlineFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];
    $styles = HeadlineHelper::getStyles();
    $alignment = HeadlineHelper::getHeadingAlignment();

    foreach ($items as $delta => $item) {
      $hidden = ($item->get('hide_headline')->getValue()) ? ' sr-only' : '';

      $item_style = isset($styles[$item->get('headline_style')->getValue()]) ?
        $styles[$item->get('headline_style')->getValue()] . $hidden : $hidden;

      $item_alignment = $alignment[$item->get('headline_alignment')->getValue()] ?? 'headline--left';

      $element[$delta] = [
        '#theme' => 'artsci_headline_field_type',
        '#text' => strip_tags($item->get('headline')->getValue()),
        '#size' => $item->get('heading_size')->getValue(),
        '#styles' => $item_style,
        '#alignment' => $item_alignment,
      ];
    }

    return $element;
  }

}
