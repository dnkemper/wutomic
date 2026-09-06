<?php

namespace Drupal\artsci_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Template\Attribute;

/**
 * Plugin implementation of the 'text_summary_only' formatter.
 *
 * Renders the summary only when an editor has entered one. Unlike the core
 * 'text_summary_or_trimmed' formatter, this never falls back to a trimmed
 * version of the full body: an empty summary renders nothing.
 *
 * @FieldFormatter(
 *   id = "text_summary_only",
 *   label = @Translation("Summary only (no trimmed fallback)"),
 *   field_types = {
 *     "text_with_summary"
 *   }
 * )
 */
class TextSummaryOnlyFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'wrapper_class' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    // Start from the parent's (empty) settings array. Returning the incoming
    // $form here would make Layout Builder's FieldBlock union the element into
    // itself and send FormHelper::rewriteStatesSelector() into infinite
    // recursion, so only ever return our own settings elements.
    $elements = parent::settingsForm($form, $form_state);

    $elements['wrapper_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wrapper CSS class'),
      '#description' => $this->t('Optional. One or more space-separated classes added to a &lt;div&gt; wrapping the summary, e.g. artsci-component--bold-intro. Leave empty for no wrapper.'),
      '#default_value' => $this->getSetting('wrapper_class'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $class = trim((string) $this->getSetting('wrapper_class'));
    if ($class !== '') {
      $summary[] = $this->t('Wrapper class: @class', ['@class' => $class]);
    }
    else {
      $summary[] = $this->t('No wrapper class');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // Split the configured class string into individual classes once.
    $classes = array_filter(preg_split('/\s+/', trim((string) $this->getSetting('wrapper_class'))));

    foreach ($items as $delta => $item) {
      // Only render an editor-authored summary. No trimmed body fallback.
      if (trim((string) $item->summary) === '') {
        continue;
      }

      $elements[$delta] = [
        '#type' => 'processed_text',
        '#text' => $item->summary,
        '#format' => $item->format,
        '#langcode' => $langcode,
      ];

      // Wrap in a div with the configured class(es), if any.
      if ($classes) {
        $attributes = new Attribute(['class' => $classes]);
        $elements[$delta]['#prefix'] = '<div' . $attributes . '>';
        $elements[$delta]['#suffix'] = '</div>';
      }
    }

    return $elements;
  }

}
