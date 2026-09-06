<?php

namespace Drupal\layout_builder_custom;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\layout_builder\Form\ConfigureBlockFormBase;

/**
 * Handles form alterations for the artsci_slider block.
 */
class SliderBlockFormHandler {

  /**
   * Alters the slider block form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public static function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    // Attach library.
    $form['#attached']['library'][] = 'layout_builder_custom/slider-block-form';

    // Classes we want to apply to all containers.
    $container_classes = [
      'off-canvas-background',
      'padding--inline--md',
      'padding--block-start--md',
      'padding--block-end--md',
      'margin--block-start--md',
    ];

    /*
     * Block heading.
     */
    if (isset($form['settings']['admin_label']['#plain_text'])) {
      $form['admin_label'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $form['settings']['admin_label']['#plain_text'],
        '#weight' => $form['settings']['admin_label']['#weight'] ?? -100,
        '#attributes' => ['class' => ['heading-a']],
      ];
      // Hide admin label in favor of custom heading.
      unset($form['settings']['admin_label']);
    }

    /*
     * Headline section.
     * Block content fields will be assigned via #group in processElement().
     */
    $form['headline_group'] = [
      '#type' => 'container',
      '#weight' => -10,
      '#attributes' => [
        'class' => $container_classes,
      ],
    ];

    $form['headline_group']['headline_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => t('Headline'),
      '#attributes' => ['class' => ['heading-a']],
    ];

    // Set weights and clean up defaults for headline fields.
    if (isset($form['layout_builder_style_headline_type'])) {
      $form['layout_builder_style_headline_type']['#weight'] = 65;
    }

    if (isset($form['layout_builder_style_headline_size'])) {
      $form['layout_builder_style_headline_size']['#access'] = FALSE;
    }

    // Duplicate headline fields into headline group.
    self::createDuplicateField($form, 'layout_builder_style_headline_type', 'headline_group');

    /*
     * Media section.
     * Block content fields will be assigned via #group in processElement().
     */
    $form['media_group'] = [
      '#type' => 'container',
      '#weight' => 0,
      '#attributes' => [
        'class' => $container_classes,
      ],
    ];

    $form['media_group']['media_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => t('Media'),
      '#attributes' => ['class' => ['heading-a']],
    ];

    // Duplicate media-related layout builder style fields into media group.
    self::createDuplicateField($form, 'layout_builder_style_card_media_position', 'media_group');
    self::createDuplicateField($form, 'layout_builder_style_media_format', 'media_group');
    self::createDuplicateField($form, 'layout_builder_style_media_size', 'media_group');

    // Unlike the card, the slider has no single block-level media field to gate
    // on: each slide carries its own image inside the slides paragraph. The
    // media style selectors apply to every slide, so they are always shown.

    /*
     * Button section.
     */
    $form['button_group'] = [
      '#type' => 'container',
      '#weight' => 70,
      '#attributes' => [
        'class' => $container_classes,
      ],
    ];

    $form['button_group']['button_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#weight' => -70,
      '#value' => t('Buttons'),
      '#attributes' => ['class' => ['heading-a']],
    ];

    // Set weights for button fields.
    if (isset($form['layout_builder_style_button_style'])) {
      $form['layout_builder_style_button_style']['#weight'] = 71;
    }

    if (isset($form['layout_builder_style_button_font'])) {
      $form['layout_builder_style_button_font']['#weight'] = 72;
    }

    // Duplicate button fields into button group.
    self::createDuplicateField($form, 'layout_builder_style_button_style', 'button_group');
    self::createDuplicateField($form, 'layout_builder_style_button_font', 'button_group');

    // The slide link is per-slide (inside the slides paragraph), so there is no
    // block-level link to gate on. The button style applies to every slide
    // button, so the selectors are always shown.

    // Hide margin field (will default to block_margin_default_removed).
    if (isset($form['layout_builder_style_margin'])) {
      $form['layout_builder_style_margin']['#access'] = FALSE;
    }

    // Hide default/card style field (will default to card_style_button_position).
    if (isset($form['layout_builder_style_default'])) {
      $form['layout_builder_style_default']['#access'] = FALSE;
    }

    /*
     * Bottom section.
     */

    // Move unique_id to the bottom.
    if (isset($form['unique_id'])) {
      $form['unique_id']['#weight'] = -200;
    }

    // Make sure the actions (buttons) come after everything.
    if (isset($form['actions'])) {
      $form['actions']['#weight'] = 210;
    }
  }

  /**
   * Validates the card block form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateForm(array &$form, FormStateInterface $form_state) {
    // Sync duplicated fields back to original fields.
    $fields_to_sync = [
      'layout_builder_style_button_font',
      'layout_builder_style_button_style',
      'layout_builder_style_card_media_position',
      'layout_builder_style_headline_type',
      'layout_builder_style_media_format',
      'layout_builder_style_media_size',
    ];

    self::syncDuplicateFields($form_state, $fields_to_sync);
  }

  /**
   * Handles submission of the card block form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submitForm(array &$form, FormStateInterface $form_state) {
    // Force default values for hidden style fields.
    $form_state->setValue('layout_builder_style_headline_size', 'headline_medium');
    $form_state->setValue('layout_builder_style_margin', 'block_margin_default_removed');
    $form_state->setValue('layout_builder_style_default', 'card_style_button_position');
  }

  /**
   * Processes the card block form element.
   *
   * @param array $element
   *   The current block element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The processed block element.
   */
  public static function processElement(array $element, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ConfigureBlockFormBase) {
      return $element;
    }

    /*
     * Assign fields to groups using #group.
     */

    // Headline group fields. The slider's headline lives on the block itself
    // (field_artsci_headline). The per-slide headline (field_collection_headline)
    // is inside the slides paragraph widget and is left in its default position.
    if (isset($element['field_artsci_headline'])) {
      $element['field_artsci_headline']['#group'] = 'headline_group';
      $element['field_artsci_headline']['#weight'] = 60;
    }

    // The slider block itself carries no media, link, excerpt, icon, autoplay,
    // or button-display fields. Those all live per-slide inside the slides
    // paragraph and are handled in the per-slide section below; only the
    // headline is a block-level field on this block.

    /*
     * Misc. field configuration.
     */
    if (isset($element['langcode'])) {
      $element['langcode']['#weight'] = 100;
    }

    /*
     * Per-slide media-type gating for the slide link.
     *
     * Each slide carries its own media (field_artsci_slide_image) and its own
     * link (field_artsci_slide_link). The media library widget only exposes the
     * selected media's entity ID to the browser, never its bundle, so #states
     * cannot tell an image from a video on its own. To bridge that, we add a
     * hidden media_type_tracker input to each slide subform. The
     * slider-block-form JS resolves the selected media's bundle (via the
     * layout_builder_custom.media_type route) and writes it into that slide's
     * tracker; the link's #states then hides the link only when the tracker
     * reads "remote_video". A link is allowed on image and (local) video
     * slides, and on slides with no media yet.
     */
    if (isset($element['field_artsci_slider_slides']['widget'])
      && is_array($element['field_artsci_slider_slides']['widget'])) {
      // Saved media bundle per slide delta, so each slide's link starts in the
      // correct state before the JS resolves the bundle (avoids first-paint
      // flicker on already-saved slides).
      $slider_plugin = $form_object->getCurrentComponent()->getPlugin();
      $slider_configuration = method_exists($slider_plugin, 'getConfiguration')
        ? $slider_plugin->getConfiguration()
        : [];
      $saved_slide_bundles = self::getSlideMediaBundles($slider_configuration);

      foreach (Element::children($element['field_artsci_slider_slides']['widget']) as $delta) {
        if (!is_numeric($delta)) {
          continue;
        }

        if (!isset($element['field_artsci_slider_slides']['widget'][$delta]['subform'])) {
          continue;
        }

        $subform = &$element['field_artsci_slider_slides']['widget'][$delta]['subform'];

        // Only gate when both the media and link fields are present.
        if (!isset($subform['field_artsci_slide_image'], $subform['field_artsci_slide_link'])) {
          continue;
        }

        // Build a deterministic input name for this slide's tracker so the
        // link's #states selector can target exactly this slide. Setting
        // #parents explicitly avoids depending on #tree propagation through the
        // paragraph subform.
        $tracker_parents = array_merge(
          $subform['#parents'] ?? [
            'settings',
            'block_form',
            'field_artsci_slider_slides',
            $delta,
            'subform',
          ],
          ['media_type_tracker']
        );
        $tracker_name = self::parentsToInputName($tracker_parents);

        // Hidden tracker, seeded with the saved media bundle so the link is
        // correct on first paint and then kept in sync by JS. This is not a
        // real paragraph field, so the subform entity mapping ignores it.
        $subform['media_type_tracker'] = [
          '#type' => 'hidden',
          '#default_value' => $saved_slide_bundles[$delta] ?? '',
          '#parents' => $tracker_parents,
          '#attributes' => [
            'data-media-type-tracker' => TRUE,
            'class' => ['slide-media-type-tracker'],
          ],
        ];

        // Hide the link only when the selected media is a remote video. It
        // stays available for images, local video, and slides with no media.
        $subform['field_artsci_slide_link']['#states'] = [
          'invisible' => [
            ':input[name="' . $tracker_name . '"]' => ['value' => 'remote_video'],
          ],
        ];
      }
    }

    return $element;
  }

  /**
   * Creates a duplicate field in a container and hides the original.
   *
   * @param array $form
   *   The form array.
   * @param string $original_field_name
   *   The name of the original field.
   * @param string $container_name
   *   The name of the container to place the duplicate in.
   */
  protected static function createDuplicateField(array &$form, $original_field_name, $container_name) {
    $duplicate_field_name = $original_field_name . '_duplicate';

    if (isset($form[$original_field_name])) {
      $form[$container_name][$duplicate_field_name] = $form[$original_field_name];
      $form[$container_name][$duplicate_field_name]['#parents'] = [$duplicate_field_name];
      // Hide the original field.
      $form[$original_field_name]['#access'] = FALSE;
    }
  }

  /**
   * Syncs duplicate field values back to their original fields.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $field_names
   *   Array of field names to sync (original field name as key).
   */
  protected static function syncDuplicateFields(FormStateInterface $form_state, array $field_names) {
    foreach ($field_names as $original_field) {
      $duplicate_field = $original_field . '_duplicate';
      $duplicate_value = $form_state->getValue($duplicate_field);
      if ($duplicate_value !== NULL) {
        $form_state->setValue($original_field, $duplicate_value);
      }
    }
  }

  /**
   * Converts a #parents array into a rendered form input name.
   *
   * For example, ['settings', 'block_form', 'field_artsci_slider_slides', 1,
   * 'subform', 'media_type_tracker'] becomes
   * "settings[block_form][field_artsci_slider_slides][1][subform][media_type_tracker]".
   *
   * @param array $parents
   *   The element #parents.
   *
   * @return string
   *   The corresponding input name attribute value.
   */
  protected static function parentsToInputName(array $parents) {
    $name = (string) array_shift($parents);
    foreach ($parents as $parent) {
      $name .= '[' . $parent . ']';
    }
    return $name;
  }

  /**
   * Builds a map of the saved media bundle for each slide delta.
   *
   * @param array $configuration
   *   The block plugin configuration.
   *
   * @return array
   *   Keyed by slide delta, with the media bundle ('image', 'video',
   *   'remote_video') as the value. Slides without media are omitted.
   */
  protected static function getSlideMediaBundles(array $configuration) {
    $bundles = [];
    $block_content = self::loadBlockContent($configuration);

    if ($block_content instanceof \Drupal\block_content\BlockContentInterface
      && $block_content->hasField('field_artsci_slider_slides')) {
      foreach ($block_content->get('field_artsci_slider_slides') as $delta => $item) {
        $paragraph = $item->entity;
        if ($paragraph instanceof \Drupal\paragraphs\ParagraphInterface
          && $paragraph->hasField('field_artsci_slide_image')
          && !$paragraph->get('field_artsci_slide_image')->isEmpty()) {
          $media = $paragraph->get('field_artsci_slide_image')->entity;
          if ($media instanceof \Drupal\media\MediaInterface) {
            $bundles[$delta] = $media->bundle();
          }
        }
      }
    }

    return $bundles;
  }

  /**
   * Loads the block content entity backing the current block configuration.
   *
   * @param array $configuration
   *   The block plugin configuration.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The block content entity, or NULL if it cannot be loaded.
   */
  protected static function loadBlockContent(array $configuration) {
    if (isset($configuration['block_serialized'])) {
      try {
        $block_content = unserialize($configuration['block_serialized']);
        if ($block_content instanceof \Drupal\block_content\BlockContentInterface) {
          return $block_content;
        }
      }
      catch (\Exception $e) {
        // Ignore unserialization errors and fall through.
      }
    }

    if (isset($configuration['block_revision_id'])) {
      try {
        $block_content = \Drupal::entityTypeManager()
          ->getStorage('block_content')
          ->loadRevision($configuration['block_revision_id']);
        if ($block_content instanceof \Drupal\block_content\BlockContentInterface) {
          return $block_content;
        }
      }
      catch (\Exception $e) {
        // Ignore loading errors.
      }
    }

    return NULL;
  }

}
