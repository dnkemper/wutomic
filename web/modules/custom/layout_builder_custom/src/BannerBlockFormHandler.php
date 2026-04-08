<?php

namespace Drupal\layout_builder_custom;

use Drupal\Core\Form\FormStateInterface;
use Drupal\layout_builder\Form\ConfigureBlockFormBase;

/**
 * Handles form alterations for the artsci_banner block.
 */
class BannerBlockFormHandler {

  /**
   * Alters the banner block form.
   *
   * Weights:
   * -20: Banner heading (replaces admin_label)
   * -10: Headline group
   * 0: Background group
   * 50: Gradient options
   * 61: Excerpt group
   * 70: Button group
   * 94: Layout group heading
   * 97: Layout settings
   * 102: Style options
   * 200: Unique ID
   * 210: Actions (submit buttons).
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
    $form['#attached']['library'][] = 'layout_builder_custom/banner-block-form';

    // Classes we want to apply to all containers.
    $container_classes = [
      'off-canvas-background',
      'padding--inline--md',
      'padding--block-start--md',
      'padding--block-end--md',
      'margin--block-start--md',
    ];

    /*
     * Create all form sections and handle modifications.
     */

    // Block heading.
    $form['admin_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $form['settings']['admin_label']['#plain_text'],
      '#weight' => $form['settings']['admin_label']['#weight'],
      '#attributes' => ['class' => ['heading-a']],
    ];

    // Hide admin label in favor of custom heading.
    unset($form['settings']['admin_label']);
    unset($form['field_artsci_banner_title_size']);

    /*
     * Headline section.
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
      $form['layout_builder_style_headline_size']['#weight'] = 66;
    }

    // Duplicate headline fields into headline group.
    self::createDuplicateField($form, 'layout_builder_style_headline_type', 'headline_group');
    self::createDuplicateField($form, 'layout_builder_style_headline_size', 'headline_group');

    /*
     * Background section.
     */
    // Background group - add heading inside block_form container.
    if (isset($form['settings']['block_form'])) {
      $form['settings']['block_form']['background_group_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => t('Background'),
        '#weight' => 0,
        '#attributes' => ['class' => ['heading-a']],
        '#prefix' => '<div class="off-canvas-background padding--inline--md padding--block-start--md padding--block-end--md margin--block-start--md">',
      ];
    }

    // Gradient options details element.
    // Only visible when background type is media AND media is video/remote_video.
    $form['gradient_options'] = [
      '#type' => 'details',
      '#title' => t('Overlay options'),
      '#weight' => 50,
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'off-canvas-form-group__collapsible',
          'off-canvas-form-group__collapsible--overlay',
        ],
      ],
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          [
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'video']],
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'remote_video']],
          ],
        ],
      ],
    ];

    $form['gradient_options']['adjust_gradient_midpoint'] = [
      '#type' => 'checkbox',
      '#title' => t('Customize gradient midpoint'),
      '#default_value' => self::getGradientMidpointCheckboxDefaultValue($form_state),
      '#weight' => 3,
      '#states' => [
        'visible' => [
          ':input[name="layout_builder_style_media_overlay_duplicate"]' => ['!value' => ''],
        ],
      ],
    ];

    // Configure gradient option duplicate fields.
    if (isset($form['layout_builder_style_media_overlay'])) {
      $form['layout_builder_style_media_overlay']['#weight'] = 1;
      $form['layout_builder_style_media_overlay']['#empty_option'] = t('No gradient (default)');
    }

    if (isset($form['layout_builder_style_banner_gradient'])) {
      $form['layout_builder_style_banner_gradient']['#weight'] = 2;
      $form['layout_builder_style_banner_gradient']['#title_display'] = 'invisible';
    }

    // Duplicate gradient fields into gradient options container.
    self::createDuplicateField($form, 'layout_builder_style_media_overlay', 'gradient_options');
    self::createDuplicateField($form, 'layout_builder_style_banner_gradient', 'gradient_options');
    self::createDuplicateField($form, 'layout_builder_style_banner_gradient_midpoint', 'gradient_options');

    if (isset($form['layout_builder_style_background'])) {
      // Background style field visible when background type is color-pattern.
      $form['layout_builder_style_background']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'color-pattern'],
        ],
      ];

      // Set weight for background style field.
      $form['layout_builder_style_background']['#weight'] = -50;
    }

    /*
     * Excerpt section.
     */
    // Excerpt group.
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
    // Button group.
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
          ':input[name="settings[block_form][field_artsci_banner_link][0][uri]"]' => ['filled' => TRUE],
        ],
      ];
    }

    if (isset($form['button_group']['layout_builder_style_button_font_duplicate'])) {
      $form['button_group']['layout_builder_style_button_font_duplicate']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][field_artsci_banner_link][0][uri]"]' => ['filled' => TRUE],
        ],
      ];
    }
    $form['layout_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => t('Layout'),
      '#weight' => 94,
      '#attributes' => ['class' => ['heading-a']],
      '#prefix' => '<div class="off-canvas-background padding--inline--md padding--block-start--md margin--block-start--md">',
    ];

    // Layout settings details element.
    $form['layout_settings'] = [
      '#type' => 'details',
      '#title' => t('<span class="element-invisible">Layout</span> Options'),
      '#weight' => 97,
      '#attributes' => ['class' => ['off-canvas-form-group__collapsible']],
      '#open' => TRUE,
      '#suffix' => '</div>',
    ];

    // Set weights for layout fields.
    if (isset($form['layout_builder_style_horizontal_alignment'])) {
      $form['layout_builder_style_horizontal_alignment']['#weight'] = 95;
    }

    if (isset($form['layout_builder_style_vertical_alignment'])) {
      $form['layout_builder_style_vertical_alignment']['#weight'] = 96;
    }

    // Duplicate layout fields into layout settings container.
    self::createDuplicateField($form, 'layout_builder_style_vertical_alignment', 'layout_settings');
    self::createDuplicateField($form, 'layout_builder_style_horizontal_alignment', 'layout_settings');

    /*
     * Styles section.
     */
    // Styles group heading.
    $form['style_options_group_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => t('Styles'),
      '#weight' => 102,
      '#attributes' => ['class' => ['heading-a']],
      '#prefix' => '<div class="off-canvas-background padding--inline--md padding--block-start--md margin--block-start--md margin--block-end--md">',
    ];

    // Style options details element.
    $form['style_options'] = [
      '#type' => 'details',
      '#title' => t('<span class="element-invisible">Style</span> Options'),
      '#attributes' => ['class' => ['off-canvas-form-group__collapsible']],
      '#weight' => 102,
      '#open' => TRUE,
      '#suffix' => '</div>',
    ];

    // Duplicate style fields into style options container.
    self::createDuplicateField($form, 'layout_builder_style_container', 'style_options');
    self::createDuplicateField($form, 'layout_builder_style_banner_height', 'style_options');
    self::createDuplicateField($form, 'layout_builder_style_banner_card_background', 'style_options');
    self::createDuplicateField($form, 'layout_builder_style_margin', 'style_options');
    self::createDuplicateField($form, 'layout_builder_style_default', 'style_options');

    // Only show banner card background when background type is color-pattern.
    if (isset($form['style_options']['layout_builder_style_banner_card_background_duplicate'])) {
      $form['style_options']['layout_builder_style_banner_card_background_duplicate']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          ':input[name="layout_builder_style_default_duplicate"]' => ['value' => 'banner_offset_content'],
        ],
      ];
    }

    if (isset($form['layout_builder_style_banner_card_background'])) {
      $form['layout_builder_style_banner_card_background']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          ':input[name="layout_builder_style_default_duplicate"]' => ['value' => 'banner_offset_content'],
        ],
      ];
    }

    /*
     * Bottom section.
     */

    // Move unique_id to the bottom.
    $form['unique_id']['#weight'] = 200;

    // Make sure the actions (buttons) come after everything.
    if (isset($form['actions'])) {
      $form['actions']['#weight'] = 210;
    }
  }

  /**
   * Validates the banner block form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateForm(array &$form, FormStateInterface $form_state) {
    // Sync duplicated fields back to original fields.
    $fields_to_sync = [
      'layout_builder_style_banner_card_background',
      'layout_builder_style_banner_gradient',
      'layout_builder_style_banner_height',
      'layout_builder_style_button_style',
      'layout_builder_style_button_font',
      'layout_builder_style_container',
      'layout_builder_style_default',
      'layout_builder_style_headline_type',
      'layout_builder_style_headline_size',
      'layout_builder_style_horizontal_alignment',
      'layout_builder_style_margin',
      'layout_builder_style_media_overlay',
      'layout_builder_style_vertical_alignment',
    ];

    self::syncDuplicateFields($form_state, $fields_to_sync);

    // Validation for links.
    $link_set = FALSE;
    $link_text = FALSE;

    // First check if there is a link set.
    $links = $form_state->getValue([
      'settings',
      'block_form',
      'field_artsci_banner_link',
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
      'field_artsci_banner_title',
      0,
      'container',
      'text',
    ]))) {
      $link_text = TRUE;
    }

    // If there is a link and no text we can use, we have a problem.
    if ($link_set && !$link_text) {
      $form_state->setErrorByName('settings][block_form][field_artsci_banner_link][0][title', t('Link text must be set if no title is present.'));
    }
  }

  /**
   * Handles submission of the banner block form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submitForm(array &$form, FormStateInterface $form_state) {
    // Load the component so it is available.
    $component = self::getFormComponent($form_state);
    // Heading style radio selection.
    $heading_style = $form_state->getValue('heading_style');
    if ($heading_style) {
      $form_state->setValue('layout_builder_style_headline_type', $heading_style);
    }

    // Gradient midpoint checkbox.
    $adjust_gradient = $form_state->getValue(['gradient_options', 'adjust_gradient_midpoint']);

    // Save checkbox state as a third-party setting.
    if (!is_null($component)) {
      $component->setThirdPartySetting('layout_builder_custom', 'adjust_gradient_midpoint', $adjust_gradient ? 1 : 0);
    }

    if (!$adjust_gradient) {
      // Clear the gradient midpoint value if checkbox is unchecked.
      $form_state->setValue('layout_builder_style_banner_gradient_midpoint', '');
      // Also clear the field_styles_gradient_midpoint value if checkbox is
      // unchecked.
      $form_state->setValue(['settings', 'block_form', 'field_styles_gradient_midpoint'], []);
    }

    $background_type = $form_state->getValue([
      'settings',
      'block_form',
      'background_type',
    ]);

    if ($background_type) {
      $form_media_selection = [
        'settings',
        'block_form',
        'field_artsci_banner_image',
        'selection',
      ];

      if ($background_type === 'media') {
        // Check if media was selected.
        if ($form_state->getValue($form_media_selection)) {
          // Clear any background style when media is selected.
          $form_state->setValue('layout_builder_style_background', '');
        }
        else {
          // If no media selected, switch to color-pattern with black
          // background.
          $form_state->setValue('layout_builder_style_background', 'block_background_style_black');
          $background_type = 'color-pattern';
        }
      }
      elseif ($background_type === 'color-pattern') {
        // For color-pattern, clear any media reference if it was previously
        // set.
        $form_state->unsetValue($form_media_selection);
      }
    }

    // Save background_type as a third-party setting.
    if ($background_type && !is_null($component)) {
      $component->setThirdPartySetting('layout_builder_custom', 'background_type', $background_type);
    }

    // Save the media type for future reference.
    $media_type = $form_state->getValue([
      'settings',
      'block_form',
      'media_type_tracker',
    ]);
    if ($media_type && !is_null($component)) {
      $component->setThirdPartySetting('layout_builder_custom', 'media_type', $media_type);
    }
  }

  /**
   * Processes the banner block form element.
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

    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $form_object->getCurrentComponent();

    $complete_form = &$form_state->getCompleteForm();
    $plugin = $component->getPlugin();
    $configuration = [];
    if (method_exists($plugin, 'getConfiguration')) {
      // @phpstan-ignore-next-line
      $configuration = $plugin->getConfiguration();
    }

    /*
     * Add hidden field to track media type for #states.
     */
    $default_media_type = self::getDefaultMediaType($component, $configuration);

    $element['media_type_tracker'] = [
      '#type' => 'hidden',
      '#default_value' => $default_media_type,
      '#attributes' => [
        'data-media-type-tracker' => TRUE,
        'class' => ['banner-media-type-tracker'],
      ],
      '#weight' => 5,
    ];

    /*
     * Assign fields to groups.
     */
    if (isset($element['field_artsci_banner_pre_title'])) {
      $element['field_artsci_banner_pre_title']['#group'] = 'headline_group';
      $element['field_artsci_banner_pre_title']['#weight'] = 60;
    }

    if (isset($element['field_artsci_headline'])) {
      $element['field_artsci_headline']['#group'] = 'headline_group';
      $element['field_artsci_headline']['#weight'] = 61;
    }

    if (isset($element['field_artsci_banner_title'])) {
      $element['field_artsci_banner_title']['#group'] = 'headline_group';
      $element['field_artsci_banner_title']['#weight'] = 62;
      // Update the label for the Heading sizes to remove Size label.
      if (isset($element['field_artsci_banner_title']['widget'][0]['container']['size']['#title'])) {
        $element['field_artsci_banner_title']['widget'][0]['container']['size']['#title'] = t('Level');
      }
    }

    if (isset($element['field_artsci_banner_excerpt'])) {
      $element['field_artsci_banner_excerpt']['#group'] = 'excerpt_group';
      $element['field_artsci_banner_excerpt']['#weight'] = 62;
      $element['field_artsci_banner_excerpt']['widget'][0]['#title_display'] = 'invisible';
    }

    if (isset($element['field_artsci_banner_link'])) {
      $element['field_artsci_banner_link']['#group'] = 'button_group';
      $element['field_artsci_banner_link']['#weight'] = 70;
      $element['field_artsci_banner_link']['#attributes']['class'][] = 'padding--inline--md';
    }

    /*
     * Configure background type.
     */

    // Determine default background type based on existing values.
    $default_bg_type = 'media';

    // Check third-party settings first, then fallback to layout builder styles.
    $stored_background_type = $component->getThirdPartySetting('layout_builder_custom', 'background_type');
    if (!empty($stored_background_type)) {
      $default_bg_type = $stored_background_type;
    }
    else {
      // Check if there's a background style set that indicates color-pattern.
      if (!empty($configuration['layout_builder_style_background'])) {
        // Any background style (including black) is color-pattern type.
        $default_bg_type = 'color-pattern';
      }
    }

    // Add radio buttons for background type selection.
    $element['background_type'] = [
      '#type' => 'radios',
      '#title' => t('Background type'),
      '#options' => [
        'media' => t('Image / Video'),
        'color-pattern' => t('Color / Pattern'),
      ],
      '#default_value' => $default_bg_type,
      '#weight' => 10,
    ];

    // Move layout_builder_style_background into block_form for proper
    // positioning.
    if (isset($complete_form['layout_builder_style_background'])) {
      $element['layout_builder_style_background'] = $complete_form['layout_builder_style_background'];
      $element['layout_builder_style_background']['#weight'] = 20;
      $element['layout_builder_style_background']['#tree'] = FALSE;
      $element['layout_builder_style_background']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'color-pattern'],
        ],
      ];
      // Hide the original field.
      $complete_form['layout_builder_style_background']['#access'] = FALSE;
    }

    if (isset($complete_form['style_options']['layout_builder_style_banner_card_background_duplicate'])) {
      $complete_form['style_options']['layout_builder_style_banner_card_background_duplicate']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
        ],
      ];
    }

    /*
     * Configure media fields.
     */
    if (isset($element['field_artsci_banner_image'])) {
      $element['field_artsci_banner_image'] = [
        '#states' => [
          'visible' => [
            ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          ],
        ],
        '#weight' => 30,
      ] + $element['field_artsci_banner_image'];
      unset($element['field_artsci_banner_image']['widget']['#title']);
    }

    // Autoplay only visible when background type is media AND media is video.
    if (isset($element['field_artsci_banner_autoplay'])) {
      $element['field_artsci_banner_autoplay'] = [
        '#states' => [
          'visible' => [
            ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
            [
              [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'video']],
              [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'remote_video']],
            ],
          ],
        ],
        '#weight' => 40,
      ] + $element['field_artsci_banner_autoplay'];
      unset($element['field_artsci_banner_autoplay']['widget']['#title']);
    }

    /*
     * Configure gradient fields.
     */
    if (isset($complete_form['layout_builder_style_media_overlay'])) {
      $complete_form['layout_builder_style_media_overlay']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          [
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'video']],
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'remote_video']],
          ],
        ],
      ];
    }

    if (isset($complete_form['layout_builder_style_banner_gradient'])) {
      $complete_form['layout_builder_style_banner_gradient']['#states'] = [
        'visible' => [
          ':input[name="settings[block_form][background_type]"]' => ['value' => 'media'],
          [
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'video']],
            [':input[name="settings[block_form][media_type_tracker]"]' => ['value' => 'remote_video']],
          ],
        ],
      ];
    }

    // Handle field_styles_gradient_midpoint field placement and behavior.
    if (isset($element['field_styles_gradient_midpoint'])) {
      // Remove the _none option from the original element.
      if (isset($element['field_styles_gradient_midpoint']['widget']['#options']['_none'])) {
        unset($element['field_styles_gradient_midpoint']['widget']['#options']['_none']);
      }

      // Move the field to gradient options if the container exists in form.
      if (isset($complete_form['gradient_options'])) {
        $complete_form['gradient_options']['field_styles_gradient_midpoint'] = $element['field_styles_gradient_midpoint'];
        $complete_form['gradient_options']['field_styles_gradient_midpoint']['#weight'] = 4;
        $complete_form['gradient_options']['field_styles_gradient_midpoint']['#states'] = [
          'visible' => [
            ':input[name="gradient_options[adjust_gradient_midpoint]"]' => ['checked' => TRUE],
            ':input[name="layout_builder_style_media_overlay_duplicate"]' => ['!value' => ''],
          ],
        ];

        // Remove the _none option from the moved field as well.
        if (isset($complete_form['gradient_options']['field_styles_gradient_midpoint']['widget']['#options']['_none'])) {
          unset($complete_form['gradient_options']['field_styles_gradient_midpoint']['widget']['#options']['_none']);
        }

        // Visually hide the fieldset legend span.
        $complete_form['gradient_options']['field_styles_gradient_midpoint']['widget']['#title_display'] = 'invisible';

        // Hide the original field.
        $element['field_styles_gradient_midpoint']['#access'] = FALSE;
      }
    }

    /*
     * Configure link fields.
     */
    if (isset($element['field_artsci_banner_link'])) {
      $element['field_artsci_banner_link']['#weight'] = -69;

      // Check the max_delta to see how many banner links have been added
      // and unset the add more button if we've reached the third link.
      if ($element['field_artsci_banner_link']['widget']['#max_delta'] >= 2) {
        unset($element['field_artsci_banner_link']['widget']['add_more']);
        // If we're editing a banner with 3 existing links
        // we also need to unset the fourth pre-added link field.
        if (isset($element['field_artsci_banner_link']['widget'][3])) {
          unset($element['field_artsci_banner_link']['widget'][3]);
        }
      }
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
   * Gets the default media type from the existing block content.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   * @param array $configuration
   *   The block configuration.
   *
   * @return string
   *   The media bundle type (e.g., 'image', 'video', 'remote_video') or empty.
   */
  protected static function getDefaultMediaType($component, array $configuration) {
    // First check third-party settings for cached media type.
    $stored_media_type = $component->getThirdPartySetting('layout_builder_custom', 'media_type');
    if (!empty($stored_media_type)) {
      return $stored_media_type;
    }

    // Try to get media type from the block content entity.
    if (isset($configuration['block_serialized'])) {
      try {
        $block_content = unserialize($configuration['block_serialized']);
        if ($block_content instanceof \Drupal\block_content\BlockContentInterface) {
          return self::getMediaTypeFromBlockContent($block_content);
        }
      }
      catch (\Exception $e) {
        // Ignore unserialization errors.
      }
    }

    // Try via block_revision_id.
    if (isset($configuration['block_revision_id'])) {
      try {
        $block_content = \Drupal::entityTypeManager()
          ->getStorage('block_content')
          ->loadRevision($configuration['block_revision_id']);
        if ($block_content instanceof \Drupal\block_content\BlockContentInterface) {
          return self::getMediaTypeFromBlockContent($block_content);
        }
      }
      catch (\Exception $e) {
        // Ignore loading errors.
      }
    }

    return '';
  }

  /**
   * Extracts the media type from a block content entity.
   *
   * @param \Drupal\block_content\BlockContentInterface $block_content
   *   The block content entity.
   *
   * @return string
   *   The media bundle type or empty string.
   */
  protected static function getMediaTypeFromBlockContent($block_content) {
    if ($block_content->hasField('field_artsci_banner_image') &&
        !$block_content->get('field_artsci_banner_image')->isEmpty()) {
      $media = $block_content->get('field_artsci_banner_image')->entity;
      if ($media instanceof \Drupal\media\MediaInterface) {
        return $media->bundle();
      }
    }
    return '';
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
   * Gets the default value for the gradient midpoint checkbox.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   The default value for the checkbox.
   */
  protected static function getGradientMidpointCheckboxDefaultValue(FormStateInterface $form_state) {
    // Get the saved checkbox state from third-party settings.
    $default_value = FALSE;
    $component = self::getFormComponent($form_state);

    if (!is_null($component)) {
      // Check third-party settings for the checkbox state.
      $stored_checkbox_value = $component->getThirdPartySetting('layout_builder_custom', 'adjust_gradient_midpoint');
      if ($stored_checkbox_value !== NULL) {
        $default_value = (bool) $stored_checkbox_value;
      }
    }

    return $default_value;
  }

  /**
   * Gets the block component from the the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The block component or NULL.
   */
  protected static function getFormComponent(FormStateInterface $form_state) {
    // Initialize component to NULL.
    $component = NULL;
    $form_object = $form_state->getFormObject();

    if ($form_object instanceof ConfigureBlockFormBase) {
      /** @var \Drupal\layout_builder\SectionComponent $component */
      $component = $form_object->getCurrentComponent();
    }
    return $component;
  }

}