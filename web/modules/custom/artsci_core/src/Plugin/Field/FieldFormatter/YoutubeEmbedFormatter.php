<?php

namespace Drupal\artsci_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'youtube_embed' formatter.
 *
 * @FieldFormatter(
 *   id = "youtube_embed",
 *   label = @Translation("YouTube Embed"),
 *   field_types = {
 *     "link",
 *     "string",
 *     "string_long"
 *   }
 * )
 */
class YoutubeEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'width' => 560,
      'height' => 315,
      'responsive' => TRUE,
      'autoplay' => FALSE,
      'controls' => TRUE,
      'modestbranding' => FALSE,
      'rel' => FALSE,
      'showinfo' => FALSE,
      'show_url' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['responsive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Responsive'),
      '#default_value' => $this->getSetting('responsive'),
      '#description' => $this->t('Make the video responsive (16:9 aspect ratio). Width and height settings will be ignored if enabled.'),
    ];

    $elements['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width'),
      '#default_value' => $this->getSetting('width'),
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name*="responsive"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $elements['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height'),
      '#default_value' => $this->getSetting('height'),
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name*="responsive"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $elements['autoplay'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autoplay'),
      '#default_value' => $this->getSetting('autoplay'),
      '#description' => $this->t('Automatically start playing the video.'),
    ];

    $elements['controls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show controls'),
      '#default_value' => $this->getSetting('controls'),
      '#description' => $this->t('Display video controls.'),
    ];

    $elements['modestbranding'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Modest branding'),
      '#default_value' => $this->getSetting('modestbranding'),
      '#description' => $this->t('Reduce YouTube branding in the player.'),
    ];

    $elements['rel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show related videos'),
      '#default_value' => $this->getSetting('rel'),
      '#description' => $this->t('Show related videos when the video finishes.'),
    ];

    $elements['showinfo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show info'),
      '#default_value' => $this->getSetting('showinfo'),
      '#description' => $this->t('Show video title and uploader before the video starts.'),
    ];

    $elements['show_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display embed URL'),
      '#default_value' => $this->getSetting('show_url'),
      '#description' => $this->t('Show the YouTube embed URL as text below the video.'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    if ($this->getSetting('responsive')) {
      $summary[] = $this->t('Responsive: Yes');
    }
    else {
      $summary[] = $this->t('Dimensions: @widthx@height', [
        '@width' => $this->getSetting('width'),
        '@height' => $this->getSetting('height'),
      ]);
    }

    $options = [];
    if ($this->getSetting('autoplay')) {
      $options[] = $this->t('Autoplay');
    }
    if ($this->getSetting('controls')) {
      $options[] = $this->t('Controls');
    }
    if ($this->getSetting('modestbranding')) {
      $options[] = $this->t('Modest branding');
    }
    if ($this->getSetting('rel')) {
      $options[] = $this->t('Related videos');
    }
    if ($this->getSetting('showinfo')) {
      $options[] = $this->t('Show info');
    }
    if ($this->getSetting('show_url')) {
      $options[] = $this->t('Display URL');
    }

    if (!empty($options)) {
      $summary[] = $this->t('Options: @options', ['@options' => implode(', ', $options)]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $video_id = $this->extractYoutubeId($item);

      if ($video_id) {
        $elements[$delta] = [
          '#theme' => 'youtube_embed_formatter',
          '#video_id' => $video_id,
          '#width' => $this->getSetting('width'),
          '#height' => $this->getSetting('height'),
          '#responsive' => $this->getSetting('responsive'),
          '#parameters' => $this->buildParameters(),
          '#show_url' => $this->getSetting('show_url'),
        ];
      }
    }

    return $elements;
  }

  /**
   * Extract YouTube video ID from various formats.
   *
   * @param mixed $item
   *   The field item.
   *
   * @return string|null
   *   The video ID or NULL if not found.
   */
  protected function extractYoutubeId($item) {
    $value = '';

    // Get the value based on field type.
    if (isset($item->uri)) {
      // Link field.
      $value = $item->uri;
    }
    elseif (isset($item->value)) {
      // String or string_long field.
      $value = $item->value;
    }

    if (empty($value)) {
      return NULL;
    }

    // Remove whitespace.
    $value = trim($value);

    // Pattern to match various YouTube URL formats.
    $patterns = [
      // Standard watch URL.
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
      // Shortened URL.
      '/(?:https?:\/\/)?(?:www\.)?youtu\.be\/([a-zA-Z0-9_-]{11})/',
      // Embed URL.
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
      // Mobile URL.
      '/(?:https?:\/\/)?(?:www\.)?m\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $value, $matches)) {
        return $matches[1];
      }
    }

    // Check if it's just a video ID (11 characters, alphanumeric with dashes and underscores).
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
      return $value;
    }

    return NULL;
  }

  /**
   * Build YouTube embed parameters.
   *
   * @return array
   *   Array of parameters.
   */
  protected function buildParameters() {
    return [
      'autoplay' => $this->getSetting('autoplay') ? 1 : 0,
      'controls' => $this->getSetting('controls') ? 1 : 0,
      'modestbranding' => $this->getSetting('modestbranding') ? 1 : 0,
      'rel' => $this->getSetting('rel') ? 1 : 0,
      'showinfo' => $this->getSetting('showinfo') ? 1 : 0,
    ];
  }

}
