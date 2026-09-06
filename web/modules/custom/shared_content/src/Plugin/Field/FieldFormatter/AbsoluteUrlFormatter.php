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
 * Plugin implementation of the 'shared_content_absolute_url' formatter.
 */
#[FieldFormatter(
  id: 'shared_content_absolute_url',
  label: new TranslatableMarkup('Absolute link URL'),
  field_types: ['link'],
)]
final class AbsoluteUrlFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'display_as_link' => FALSE,
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

    $elements['display_as_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display as clickable link'),
      '#default_value' => $this->getSetting('display_as_link'),
      '#description' => $this->t('If checked, the URL will be displayed as a clickable link. Otherwise, it will be displayed as plain text.'),
    ];

    $elements['trim_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Trim link text length'),
      '#description' => $this->t('Leave blank to display the full URL. Enter a number to trim the displayed text to that many characters (the link will still point to the full URL).'),
      '#default_value' => $this->getSetting('trim_length'),
      '#min' => 0,
      '#size' => 10,
      '#states' => [
        'visible' => [
          ':input[name*="display_as_link"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $elements['rel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add rel attribute'),
      '#description' => $this->t('Specify the rel attribute (e.g., "nofollow noopener").'),
      '#default_value' => $this->getSetting('rel'),
      '#states' => [
        'visible' => [
          ':input[name*="display_as_link"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $elements['target'] = [
      '#type' => 'select',
      '#title' => $this->t('Open link in'),
      '#default_value' => $this->getSetting('target'),
      '#options' => [
        '' => $this->t('Same window'),
        '_blank' => $this->t('New window (_blank)'),
      ],
      '#states' => [
        'visible' => [
          ':input[name*="display_as_link"]' => ['checked' => TRUE],
        ],
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

    if ($settings['display_as_link']) {
      $summary[] = $this->t('Display as clickable link');

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
    }
    else {
      $summary[] = $this->t('Display as plain text');
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

      // Generate absolute URL.
      $absolute_url = $url->setOption('absolute', TRUE)->toString();

      if ($settings['display_as_link']) {
        $elements[$delta] = $this->buildLinkElement($url, $absolute_url, $settings);
      }
      else {
        $elements[$delta] = $this->buildPlainTextElement($absolute_url);
      }
    }

    return $elements;
  }

  /**
   * Builds a link render element.
   *
   * @param \Drupal\Core\Url $url
   *   The URL object.
   * @param string $absolute_url
   *   The absolute URL string.
   * @param array $settings
   *   The formatter settings.
   *
   * @return array
   *   A render array for the link.
   */
  protected function buildLinkElement(Url $url, string $absolute_url, array $settings): array {
    $options = $url->getOptions();

    // Add rel attribute if specified.
    if (!empty($settings['rel'])) {
      $options['attributes']['rel'] = $settings['rel'];
    }

    // Add target attribute if specified.
    if (!empty($settings['target'])) {
      $options['attributes']['target'] = $settings['target'];
    }

    $url->setOptions($options);

    // Determine link text.
    $link_text = $absolute_url;
    if (!empty($settings['trim_length']) && mb_strlen($absolute_url) > $settings['trim_length']) {
      $link_text = mb_substr($absolute_url, 0, $settings['trim_length']) . '...';
    }

    return [
      '#type' => 'link',
      '#title' => $link_text,
      '#url' => $url,
      '#options' => $options,
    ];
  }

  /**
   * Builds a plain text render element.
   *
   * @param string $absolute_url
   *   The absolute URL string.
   *
   * @return array
   *   A render array for plain text.
   */
  protected function buildPlainTextElement(string $absolute_url): array {
    return [
      '#plain_text' => $absolute_url,
    ];
  }

}
