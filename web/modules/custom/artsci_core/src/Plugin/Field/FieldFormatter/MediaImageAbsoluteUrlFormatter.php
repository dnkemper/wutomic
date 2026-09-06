<?php

namespace Drupal\artsci_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'media_image_absolute_url' formatter.
 *
 * @FieldFormatter(
 *   id = "media_image_absolute_url",
 *   label = @Translation("Media Image Absolute URL"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class MediaImageAbsoluteUrlFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MediaImageAbsoluteUrlFormatter object.
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, $label, $view_mode, array $third_party_settings, FileUrlGeneratorInterface $file_url_generator, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->fileUrlGenerator = $file_url_generator;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('file_url_generator'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'image_style' => '',
      'image_field' => 'field_media_image',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['image_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image field name'),
      '#description' => $this->t('The machine name of the image field on the media entity (e.g., field_media_image).'),
      '#default_value' => $this->getSetting('image_field'),
      '#required' => TRUE,
    ];

    // Get all available image styles.
    $image_styles = $this->entityTypeManager
      ->getStorage('image_style')
      ->loadMultiple();

    $style_options = ['' => $this->t('Original image (no style)')];
    foreach ($image_styles as $style_id => $style) {
      $style_options[$style_id] = $style->label();
    }

    $element['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style'),
      '#description' => $this->t('Select which image style to use for the absolute URL.'),
      '#options' => $style_options,
      '#default_value' => $this->getSetting('image_style'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $image_field = $this->getSetting('image_field');
    $summary[] = $this->t('Image field: @field', ['@field' => $image_field]);

    $image_style = $this->getSetting('image_style');
    if (empty($image_style)) {
      $summary[] = $this->t('Original image');
    }
    else {
      $style = $this->entityTypeManager
        ->getStorage('image_style')
        ->load($image_style);
      if ($style) {
        $summary[] = $this->t('Image style: @style', ['@style' => $style->label()]);
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $image_style = $this->getSetting('image_style');
    $image_field = $this->getSetting('image_field');

    foreach ($items as $delta => $item) {
      // Get the referenced media entity.
      $media = $item->entity;

      if (!$media || !$media->hasField($image_field)) {
        continue;
      }

      // Get the file from the media's image field.
      $file = $media->get($image_field)->entity;

      if (!$file) {
        continue;
      }

      $file_uri = $file->getFileUri();

      // If an image style is selected, use it.
      if (!empty($image_style)) {
        $style = $this->entityTypeManager
          ->getStorage('image_style')
          ->load($image_style);

        if ($style) {
          $url = $style->buildUrl($file_uri);
        }
        else {
          $url = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
        }
      }
      else {
        $url = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
      }

      $elements[$delta] = [
        '#markup' => $url,
      ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable($field_definition) {
    // Only show this formatter for entity_reference fields targeting media.
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'media';
  }

}
