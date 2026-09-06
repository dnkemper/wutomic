<?php

namespace Drupal\layout_builder_custom\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\file\IconMimeTypes;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Plugin implementation of the 'media_entity_download_download_link' formatter.
 *
 * @FieldFormatter(
 *   id = "media_entity_download_download_link",
 *   label = @Translation("Download link"),
 *   field_types = {
 *     "file",
 *     "image"
 *   }
 * )
 */
class DownloadLinkFieldFormatter extends LinkFormatter implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      'link_text' => 'Download',
      'show_icon' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['disposition'] = [
      '#type' => 'radios',
      '#title' => $this->t('Download behavior'),
      '#description' => $this->t('Whether browsers will open a "Save as..." dialog or automatically decide how to handle the download.'),
      '#default_value' => $this->getSetting('disposition'),
      '#options' => [
        ResponseHeaderBag::DISPOSITION_ATTACHMENT => $this->t('Force "Save as..." dialog'),
        ResponseHeaderBag::DISPOSITION_INLINE => $this->t('Browser default'),
      ],
    ];

    $elements['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#description' => $this->t('The text to display for the download link. Use [filename] as a placeholder for the file name.'),
      '#default_value' => $this->getSetting('link_text'),
      '#required' => TRUE,
    ];

    $elements['show_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show download icon'),
      '#default_value' => $this->getSetting('show_icon'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $settings = $this->getSettings();

    if ($settings['disposition'] == ResponseHeaderBag::DISPOSITION_ATTACHMENT) {
      $summary[] = $this->t('Force "Save as..." dialog');
    }
    else {
      $summary[] = $this->t('Browser default behavior');
    }

    $summary[] = $this->t('Link text: @text', ['@text' => $settings['link_text']]);

    if ($settings['show_icon']) {
      $summary[] = $this->t('Show download icon');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
public function viewElements(FieldItemListInterface $items, $langcode) {
  $elements = [];
  $parent_entity = $items->getEntity();
  $parent_id = $parent_entity->id();

  // Can't generate download links for unsaved entities (e.g., Layout Builder preview).
  if (empty($parent_id)) {
    return $elements;
  }

  $settings = $this->getSettings();


    foreach ($items as $delta => $item) {
      if (empty($item->target_id)) {
        continue;
      }

      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($item->target_id);

      if (!$file) {
        continue;
      }

      $filename = $file->getFilename();
      $mime_type = $file->getMimeType();

      // Build URL options.
      $url_options = [
        'absolute' => TRUE,
        'query' => [],
        'attributes' => [
          'type' => $mime_type . '; length=' . $file->getSize(),
          'title' => $filename,
          'class' => [
            'file',
            'file--mime-' . strtr($mime_type, ['/' => '-', '.' => '-']),
            'file--' . IconMimeTypes::getIconClass($mime_type),
          ],
        ],
      ];

      // Add delta parameter if needed.
      if ($delta > 0) {
        $url_options['query']['delta'] = $delta;
      }

      // Add disposition parameter.
      $url_options['query'][$settings['disposition']] = NULL;

      // Add target and rel attributes for inline disposition.
      if ($settings['disposition'] == ResponseHeaderBag::DISPOSITION_INLINE) {
        if (!empty($settings['target'])) {
          $url_options['attributes']['target'] = $settings['target'];
        }
      }

      if (!empty($settings['rel'])) {
        $url_options['attributes']['rel'] = $settings['rel'];
      }

      // Build the URL.
      $url = Url::fromRoute(
        'layout_builder_custom.download',
        ['media' => $parent_id],
        $url_options
      );

      // Prepare link text with placeholder replacement.
      $link_text = str_replace('[filename]', $filename, $settings['link_text']);

      // Build the link render array.
      $elements[$delta] = [
        '#type' => 'link',
        '#title' => $link_text,
        '#url' => $url,
        '#options' => $url_options,
      ];

      // Add icon if enabled.
      if ($settings['show_icon']) {
        $elements[$delta]['#title'] = [
          '#type' => 'inline_template',
          '#template' => '{{ text }} <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Pro v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2026 Fonticons, Inc.--><path fill="#ffffff" d="M331.3 411.3L320 422.6L308.7 411.3L164.7 267.3L153.4 256L176 233.4L187.3 244.7L304 361.4L304 64L336 64L336 361.4L452.7 244.7L464 233.4L486.6 256L475.3 267.3L331.3 411.3zM128 400L128 544L512 544L512 384L544 384L544 576L96 576L96 384L128 384L128 400z"/></svg>',
          '#context' => [
            'text' => $link_text,
          ],
        ];
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getFieldStorageDefinition()->getTargetEntityTypeId() === 'media';
  }

}
