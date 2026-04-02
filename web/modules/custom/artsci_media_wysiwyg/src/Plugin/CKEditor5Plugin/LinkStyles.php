<?php

declare(strict_types=1);

namespace Drupal\artsci_media_wysiwyg\Plugin\CKEditor5Plugin;

use Drupal\ckeditor5\HTMLRestrictions;
use Drupal\ckeditor5\Plugin\CKEditor5PluginConfigurableInterface;
use Drupal\ckeditor5\Plugin\CKEditor5PluginConfigurableTrait;
use Drupal\ckeditor5\Plugin\CKEditor5PluginDefault;
use Drupal\ckeditor5\Plugin\CKEditor5PluginElementsSubsetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\editor\EditorInterface;

/**
 * CKEditor 5 Link Styles plugin.
 */
class LinkStyles extends CKEditor5PluginDefault implements CKEditor5PluginConfigurableInterface, CKEditor5PluginElementsSubsetInterface {

  use CKEditor5PluginConfigurableTrait;

  /**
   * {@inheritDoc}
   */
  public function getElementsSubset(): array {
    if (empty($this->configuration['styles'])) {
      return [];
    }
    return ['<a class>'];
  }

  /**
   * {@inheritDoc}
   */
  public function defaultConfiguration() {
    return [
      'styles' => [],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['styles'] = [
      '#title' => $this->t('Link Styles'),
      '#type' => 'textarea',
      '#description' => $this->t('A list of classes that will be provided as Link Styles. Enter one or more classes on each line in the format: a.classA.classB|Label. Example: a.btn|Button. Advanced example: a.btn.large-button|Large Button.<br />These link styles should be available in your theme\'s CSS file.'),
    ];
    if (!empty($this->configuration['styles'])) {
      $as_selectors = '';
      foreach ($this->configuration['styles'] as $style) {
        [$tag, $classes] = self::getTagAndClasses(HTMLRestrictions::fromString($style['element']));
        $as_selectors .= sprintf("%s.%s|%s\n", $tag, implode('.', $classes), $style['label']);
      }
      $form['styles']['#default_value'] = $as_selectors;
    }

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Match the config schema structure at ckeditor5.plugin.ckeditor5_style.
    $form_value = $form_state->getValue('styles');
    [$styles, $unparseable_lines] = self::parseStylesFormValue($form_value);
    if (!empty($unparseable_lines)) {
      $line_numbers = array_keys($unparseable_lines);
      $form_state->setError($form['styles'], $this->formatPlural(
        count($unparseable_lines),
        'Line @line-number does not contain a valid value. Enter a valid anchor tag CSS selector containing one or more classes, followed by a pipe symbol and a label.',
        'Lines @line-numbers do not contain a valid value. Enter a valid anchor tag CSS selector containing one or more classes, followed by a pipe symbol and a label.',
        [
          '@line-number' => reset($line_numbers),
          '@line-numbers' => implode(', ', $line_numbers),
        ]
      ));
    }
    $form_state->setValue('styles', $styles);
  }

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['styles'] = $form_state->getValue('styles');
  }

  /**
   * Parses the line-based (for form) link style configuration.
   *
   * @param string $form_value
   *   A string containing >=1 lines with on each line a CSS selector targeting
   *   1 tag with >=1 classes, a pipe symbol and a label. An example of a single
   *   line: `a.foo.bar|Foo bar link`.
   *
   * @return array
   *   The parsed equivalent: a list of arrays with each containing:
   *   - label: the label after the pipe symbol, with whitespace trimmed
   *   - element: the CKEditor 5 element equivalent of the tag + classes
   */
  private static function parseStylesFormValue(string $form_value): array {
    $unparseable_lines = [];

    $lines = explode("\n", $form_value);
    $styles = [];
    foreach ($lines as $index => $line) {
      if (empty(trim($line))) {
        continue;
      }

      // Parse the line.
      [$selector, $label] = array_map('trim', explode('|', $line));

      // Validate the selector ensure it is an anchor tag.
      $selector_matches = [];
      // @see https://www.w3.org/TR/CSS2/syndata.html#:~:text=In%20CSS%2C%20identifiers%20(including%20element,hyphen%20followed%20by%20a%20digit
      if (!preg_match('/^(a)((\.[a-zA-Z0-9\x{00A0}-\x{FFFF}\-_]+)+)$/u', $selector, $selector_matches)) {
        $unparseable_lines[$index + 1] = $line;
        continue;
      }

      // Parse selector into tag + classes and normalize.
      $tag = $selector_matches[1];
      $classes = array_filter(explode('.', $selector_matches[2]));
      $normalized = HTMLRestrictions::fromString(sprintf('<%s class="%s">', $tag, implode(' ', $classes)));

      $styles[] = [
        'label' => $label,
        'element' => $normalized->toCKEditor5ElementsArray()[0],
      ];
    }
    return [$styles, $unparseable_lines];
  }

  /**
   * Gets the tag and classes for a parsed style element.
   *
   * @param \Drupal\ckeditor5\HTMLRestrictions $style_element
   *   A parsed style element.
   *
   * @return array
   *   An array containing two values:
   *   - an HTML tag name
   *   - a list of classes
   */
  public static function getTagAndClasses(HTMLRestrictions $style_element): array {
    $tag = array_keys($style_element->getAllowedElements())[0];
    $classes = array_keys($style_element->getAllowedElements()[$tag]['class']);
    return [$tag, $classes];
  }

  /**
   * {@inheritdoc}
   *
   * Sets the decorators on the link ckeditor plugin.
   *
   * See https://ckeditor.com/docs/ckeditor5/latest/features/link.html#custom-link-attributes-decorators
   */
  public function getDynamicPluginConfig(array $static_plugin_config, EditorInterface $editor): array {
    if (empty($this->configuration['styles'])) {
      return [];
    }

    $config = [];
    foreach ($this->configuration['styles'] as $style) {
      [$tag, $classes] = self::getTagAndClasses(
        HTMLRestrictions::fromString($style['element'])
      );

      // Only supporting anchor tags.
      if ($tag != 'a') {
        continue;
      }

      $config['link']['decorators'][] = [
        'mode' => 'manual',
        'label' => $style['label'],
        'attributes' => [
          'class' => implode(' ', $classes),
        ],
      ];
    }

    return $config;
  }

}
