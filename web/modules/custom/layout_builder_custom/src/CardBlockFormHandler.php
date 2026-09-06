<?php

namespace Drupal\layout_builder_custom;

use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Form\ConfigureBlockFormBase;

/**
 * Handles form alterations for the artsci_card block.
 */
class CardBlockFormHandler {

  /**
   * Alters the card block form.
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
    $form['#attached']['library'][] = 'layout_builder_custom/card-block-form';

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

    // Only show media style fields when media is selected.
    // Note: The selector may need adjustment based on your media field widget.
    // Inspect the form to find the correct input name for the media field.
    $media_states = [
      'visible' => [
        [':input[name="settings[block_form][field_artsci_card_image][selection][0][target_id]"]' => ['filled' => TRUE]],
      ],
    ];

    if (isset($form['media_group']['layout_builder_style_card_media_position_duplicate'])) {
      $form['media_group']['layout_builder_style_card_media_position_duplicate']['#states'] = $media_states;
    }

    if (isset($form['media_group']['layout_builder_style_media_format_duplicate'])) {
      $form['media_group']['layout_builder_style_media_format_duplicate']['#states'] = $media_states;
    }

    if (isset($form['media_group']['layout_builder_style_media_size_duplicate'])) {
      $form['media_group']['layout_builder_style_media_size_duplicate']['#states'] = $media_states;
    }

    /*
     * Excerpt section.
     */
    $form['excerpt_group'] = [
      '#type' => 'container',
      '#weight' => 61,
      '#attributes' => [
        'class' => $container_classes,
      ],
    ];

    $form['excerpt_group']['excerpt_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => t('Excerpt'),
      '#attributes' => ['class' => ['heading-a']],
    ];

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

    // Only show button style when a link URI is entered.
    if (isset($form['button_group']['layout_builder_style_button_style_duplicate'])) {
      $form['button_group']['layout_builder_style_button_style_duplicate']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][field_artsci_card_link][0][uri]"]' => ['filled' => TRUE],
        ],
      ];
    }

    if (isset($form['button_group']['layout_builder_style_button_font_duplicate'])) {
      $form['button_group']['layout_builder_style_button_font_duplicate']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][field_artsci_card_link][0][uri]"]' => ['filled' => TRUE],
        ],
      ];
    }

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

    // Validation for links.
    $link_set = FALSE;
    $link_text = FALSE;

    // First check if there is a link set.
    $links = $form_state->getValue([
      'settings',
      'block_form',
      'field_artsci_card_link',
    ]);

    if (is_array($links)) {
      foreach ($links as $key => $link) {
        if ($key === 'add_more' || empty($link['uri'])) {
          // If there is no uri, then we don't care about anything else.
          continue;
        }
        else {
          $link_set = TRUE;
        }

        if (!empty($link['title'])) {
          $link_text = TRUE;
        }
      }
    }

    // If there is a link and no text, check if there is a title.
    if ($link_set && !empty($form_state->getValue([
      'settings',
      'block_form',
      'field_artsci_card_title',
      0,
      'container',
      'text',
    ]))) {
      $link_text = TRUE;
    }

    // If there is a link and no text we can use, we have a problem.
    if ($link_set && !$link_text) {
      $form_state->setErrorByName('settings][block_form][field_artsci_card_link][0][title', t('Link text must be set if no title is present.'));
    }
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

    // Auto-check button display field if the link title field is filled in.
    $links = $form_state->getValue([
      'settings',
      'block_form',
      'field_artsci_card_link',
    ]);

    if (is_array($links) && !empty($links[0]['title'])) {
      $form_state->setValue(
        [
          'settings',
          'block_form',
          'field_artsci_card_button_display',
        ],
        [
          'value' => 1,
        ]
      );
    }
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

    // Headline group fields.
    if (isset($element['field_pre_title'])) {
      $element['field_pre_title']['#group'] = 'headline_group';
      $element['field_pre_title']['#weight'] = 60;
    }

    if (isset($element['field_artsci_card_title'])) {
      $element['field_artsci_card_title']['#group'] = 'headline_group';
      $element['field_artsci_card_title']['#weight'] = 61;
      // Hide the size dropdown (heading level) for cards.
      if (isset($element['field_artsci_card_title']['widget'][0]['container']['size'])) {
        $element['field_artsci_card_title']['widget'][0]['container']['size']['#access'] = FALSE;
      }
    }

    // Media group fields.
    if (isset($element['field_artsci_card_image'])) {
      $element['field_artsci_card_image']['#group'] = 'media_group';
      $element['field_artsci_card_image']['#weight'] = 1;
    }

    if (isset($element['field_icon'])) {
      $element['field_icon']['#group'] = 'media_group';
      $element['field_icon']['#weight'] = 2;
    }

    if (isset($element['field_artsci_banner_autoplay'])) {
      $element['field_artsci_banner_autoplay']['#group'] = 'media_group';
      $element['field_artsci_banner_autoplay']['#weight'] = 3;
    }

    // Excerpt group fields.
    if (isset($element['field_artsci_card_excerpt'])) {
      $element['field_artsci_card_excerpt']['#group'] = 'excerpt_group';
      $element['field_artsci_card_excerpt']['#weight'] = 62;
      $element['field_artsci_card_excerpt']['widget'][0]['#title_display'] = 'invisible';
    }

    // Button group fields.
    if (isset($element['field_artsci_card_link'])) {
      $element['field_artsci_card_link']['#group'] = 'button_group';
      $element['field_artsci_card_link']['#weight'] = 70;

      // Check the max_delta to see how many links have been added
      // and unset the add more button if we've reached the third link.
      if (isset($element['field_artsci_card_link']['widget']['#max_delta']) &&
          $element['field_artsci_card_link']['widget']['#max_delta'] >= 2) {
        unset($element['field_artsci_card_link']['widget']['add_more']);
        // If we're editing a card with 3 existing links
        // we also need to unset the fourth pre-added link field.
        if (isset($element['field_artsci_card_link']['widget'][3])) {
          unset($element['field_artsci_card_link']['widget'][3]);
        }
      }
    }

    if (isset($element['field_artsci_card_button_display'])) {
      $element['field_artsci_card_button_display']['#group'] = 'button_group';
      $element['field_artsci_card_button_display']['#weight'] = 71;
      // Only show when a link URI is entered.
      $element['field_artsci_card_button_display']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][field_artsci_card_link][0][uri]"]' => ['filled' => TRUE],
        ],
      ];
    }

    /*
     * Misc. field configuration.
     */
    if (isset($element['langcode'])) {
      $element['langcode']['#weight'] = 100;
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

}
