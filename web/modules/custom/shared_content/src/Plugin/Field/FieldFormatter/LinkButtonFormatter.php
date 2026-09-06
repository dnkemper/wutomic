<?php

declare(strict_types=1);

namespace Drupal\shared_content\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'shared_content_link_button' formatter.
 *
 * Renders a link field as a styled button. Unlike AbsoluteUrlFormatter
 * (which prints the URL itself as the link text), this formatter uses the
 * link's own TITLE (the editor-entered link text) as the button label and
 * adds the theme's `bttn` button classes. The href is still the ABSOLUTE
 * URL so the button works when this content is syndicated to other sites,
 * matching the sibling absolute-URL formatter.
 */
#[FieldFormatter(
  id: 'shared_content_link_button',
  label: new TranslatableMarkup('Link text as button'),
  field_types: ['link'],
)]
final class LinkButtonFormatter extends FormatterBase {

  /**
   * Available button style modifiers (theme `bttn--*` classes).
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Machine modifier keyed by human label.
   */
  protected function buttonStyleOptions(): array {
    return [
      'primary' => $this->t('Primary'),
      'secondary' => $this->t('Secondary'),
      'tertiary' => $this->t('Tertiary'),
      'arrow' => $this->t('Arrow'),
      'transparent' => $this->t('Transparent'),
      'light' => $this->t('Light'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'button_style' => 'primary',
      'fallback_to_url' => TRUE,
      'trim_length' => 0,
      'rel' => '',
      'target' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);

    $elements['button_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Button style'),
      '#default_value' => $this->getSetting('button_style'),
      '#options' => $this->buttonStyleOptions(),
      '#description' => $this->t('Applies the matching theme button class (bttn bttn--<style>).'),
    ];

    $elements['fallback_to_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fall back to the URL when no link text is set'),
      '#default_value' => $this->getSetting('fallback_to_url'),
      '#description' => $this->t('If a link has no title, use the absolute URL as the button label. When unchecked, links without title text are skipped.'),
    ];

    $elements['trim_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Trim button text length'),
      '#description' => $this->t('Leave blank to show the full link text. Enter a number to trim the displayed label to that many characters (the link still points to the full URL).'),
      '#default_value' => $this->getSetting('trim_length'),
      '#min' => 0,
      '#size' => 10,
    ];

    $elements['rel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add rel attribute'),
      '#description' => $this->t('Specify the rel attribute (e.g., "nofollow noopener").'),
      '#default_value' => $this->getSetting('rel'),
    ];

    $elements['target'] = [
      '#type' => 'select',
      '#title' => $this->t('Open link in'),
      '#default_value' => $this->getSetting('target'),
      '#options' => [
        '' => $this->t('Same window'),
        '_blank' => $this->t('New window (_blank)'),
      ],
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    $settings = $this->getSettings();

    $styles = $this->buttonStyleOptions();
    $style = $settings['button_style'];
    $summary[] = $this->t('Button style: @style', [
      '@style' => $styles[$style] ?? $style,
    ]);

    $summary[] = empty($settings['fallback_to_url'])
      ? $this->t('Skip links without text')
      : $this->t('Fall back to URL when no link text');

    if (!empty($settings['trim_length'])) {
      $summary[] = $this->t('Trim text to @length characters', [
        '@length' => $settings['trim_length'],
      ]);
    }

    if (!empty($settings['rel'])) {
      $summary[] = $this->t('Rel: @rel', ['@rel' => $settings['rel']]);
    }

    if (!empty($settings['target'])) {
      $summary[] = $this->t('Open in new window');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $settings = $this->getSettings();

    foreach ($items as $delta => $item) {
      if ($item->isEmpty()) {
        continue;
      }

      $url = $item->getUrl();
      if (!$url instanceof Url) {
        continue;
      }

      // Absolute href so syndicated copies link back to the source site.
      $url->setOption('absolute', TRUE);
      $absolute_url = $url->toString();

      // Button label is the link's own title (link text). Fall back to the
      // URL only when allowed; otherwise skip the item entirely.
      $label = trim((string) ($item->title ?? ''));
      if ($label === '') {
        if (empty($settings['fallback_to_url'])) {
          continue;
        }
        $label = $absolute_url;
      }

      if (!empty($settings['trim_length']) && mb_strlen($label) > (int) $settings['trim_length']) {
        $label = mb_substr($label, 0, (int) $settings['trim_length']) . '…';
      }

      $elements[$delta] = $this->buildButtonElement($url, $label, $settings);
    }

    return $elements;
  }

  /**
   * Builds a styled button link render element.
   *
   * @param \Drupal\Core\Url $url
   *   The URL object (already flagged absolute).
   * @param string $label
   *   The button text.
   * @param array $settings
   *   The formatter settings.
   *
   * @return array
   *   A render array for the button link.
   */
  protected function buildButtonElement(Url $url, string $label, array $settings): array {
    // Merge onto any existing options instead of overwriting them, so the
    // button class coexists with rel/target (the original AbsoluteUrl
    // formatter clobbered attributes here and dropped rel/target).
    $options = $url->getOptions();
    $options['attributes']['class'] = ['bttn', 'bttn--' . $settings['button_style']];

    if (!empty($settings['rel'])) {
      $options['attributes']['rel'] = $settings['rel'];
    }
    if (!empty($settings['target'])) {
      $options['attributes']['target'] = $settings['target'];
    }

    $url->setOptions($options);

    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
      '#options' => $options,
    ];
  }

}
