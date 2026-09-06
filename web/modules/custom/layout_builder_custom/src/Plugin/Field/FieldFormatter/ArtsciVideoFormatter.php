<?php

namespace Drupal\layout_builder_custom\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_thumbnails_video\Plugin\Field\FieldFormatter\VideoExtendedFormatter;

/**
 * Plugin implementation of the 'artsci_file_video' formatter.
 *
 * Identical to the contributed 'file_video_extended' formatter it extends,
 * except that it adds an editable call to action beside the play button, the
 * same way OembedThumbnailFormatter does for remote videos. Local and remote
 * video share one control partial
 * (@atomic_artsci/artsci/video-controls.html.twig), so before this the remote
 * facade could say "Watch the video" and an uploaded video sitting next to it
 * could not.
 *
 * The setting lives on the formatter, so it is configured per entity view
 * display. Which display a video gets is decided by the Layout Builder
 * media_format and media_size styles, so in practice the label is chosen per
 * crop and size rather than per placement. That is the same trade-off the
 * remote formatter already makes.
 *
 * @FieldFormatter(
 *   id = "artsci_file_video",
 *   label = @Translation("Video extended (with play label)"),
 *   description = @Translation("Display the file using an HTML5 video tag, with an editable call to action beside the play button."),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class ArtsciVideoFormatter extends VideoExtendedFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      // Empty means the round play button renders on its own, which is exactly
      // what every display did before this setting existed. Nothing changes
      // until an editor types a label.
      'play_label' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['play_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Play button label'),
      '#description' => $this->t('Optional text shown beside the play button, for example Watch or Watch the video. Leave empty for the round play button on its own.'),
      '#default_value' => $this->getSetting('play_label'),
      '#maxlength' => 64,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $label = trim((string) $this->getSetting('play_label'));
    $summary[] = $label === ''
      ? $this->t('Play button label: none')
      : $this->t('Play button label: @label', ['@label' => $label]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);

    $label = trim((string) $this->getSetting('play_label'));

    foreach ($elements as $key => $element) {
      // Passed as a template variable rather than an attribute on the <video>
      // tag, because the label is a sibling of the button in the control
      // partial. 'play_label' is added to the core 'file_video' theme hook by
      // layout_builder_custom_theme_registry_alter(); without that, an
      // undeclared variable would be dropped before the template sees it.
      $elements[$key]['#play_label'] = $label;
    }

    return $elements;
  }

}
