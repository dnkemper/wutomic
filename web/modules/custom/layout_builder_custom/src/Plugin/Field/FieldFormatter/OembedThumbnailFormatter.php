<?php

namespace Drupal\layout_builder_custom\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\IFrameUrlHelper;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\media\MediaInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\media\Plugin\Field\FieldFormatter\OEmbedFormatter;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'oembed_thumbnail' formatter.
 *
 * Displays the oEmbed thumbnail with a play button overlay. On click,
 * the thumbnail is replaced with the actual video iframe.
 *
 * @FieldFormatter(
 *   id = "oembed_thumbnail",
 *   label = @Translation("oEmbed Thumbnail (click to play)"),
 *   description = @Translation("Display the video thumbnail with a play button. Clicking loads the video."),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class OembedThumbnailFormatter extends OEmbedFormatter {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    MessengerInterface $messenger,
    ResourceFetcherInterface $resource_fetcher,
    UrlResolverInterface $url_resolver,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    IFrameUrlHelper $iframe_url_helper,
    FileUrlGeneratorInterface $file_url_generator
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $messenger,
      $resource_fetcher,
      $url_resolver,
      $logger_factory,
      $config_factory,
      $iframe_url_helper
    );
    $this->fileUrlGenerator = $file_url_generator;
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
      $container->get('messenger'),
      $container->get('media.oembed.resource_fetcher'),
      $container->get('media.oembed.url_resolver'),
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('media.oembed.iframe_url_helper'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'max_width' => 0,
      'max_height' => 0,
      'loading' => ['attribute' => 'lazy'],
      'autoplay' => TRUE,
      // Applied to the poster when the thumbnail is a local file, which is the
      // case for a custom thumbnail and for every provider whose thumbnail
      // Drupal caches locally. No crop is the right default because the facade
      // wrapper already imposes the aspect ratio with object-fit, so a server
      // side crop on top of it would crop the poster twice.
      'thumbnail_responsive_image_style' => 'full__no_crop',
      // Used for the YouTube poster, which is remote and therefore cannot go
      // through a responsive image style. The local path takes its sizes from
      // the responsive image style instead and ignores this.
      'thumbnail_sizes' => '100vw',
      // Visible call to action shown beside the play button before the video
      // starts, for example 'Watch' or 'Watch the video'. Empty is the round
      // play button on its own, which is the historic behaviour, so existing
      // view displays keep rendering exactly as they did.
      'play_label' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();

    $style = $this->loadResponsiveImageStyle($this->getSetting('thumbnail_responsive_image_style'));
    if ($style) {
      $dependencies[$style->getConfigDependencyKey()][] = $style->getConfigDependencyName();
    }

    return $dependencies;
  }

  /**
   * Loads a responsive image style, if it exists.
   *
   * Resolved through the container rather than injected: this formatter's
   * constructor already carries the full OEmbedFormatter argument list, and
   * widening it further would be a breaking change for anything that
   * instantiates it directly.
   *
   * @param string|null $id
   *   The responsive image style ID.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The style, or NULL when it is missing or disabled.
   */
  protected function loadResponsiveImageStyle(?string $id) {
    if (empty($id)) {
      return NULL;
    }

    try {
      return \Drupal::entityTypeManager()
        ->getStorage('responsive_image_style')
        ->load($id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['autoplay'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autoplay video when clicked'),
      '#default_value' => $this->getSetting('autoplay'),
      '#description' => $this->t('If enabled, the video will start playing automatically when the thumbnail is clicked.'),
    ];

    $options = [];
    try {
      foreach (\Drupal::entityTypeManager()->getStorage('responsive_image_style')->loadMultiple() as $id => $style) {
        $options[$id] = $style->label();
      }
    }
    catch (\Exception $e) {
      // Leave the list empty; the element below still allows none.
    }

    $form['thumbnail_responsive_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Poster responsive image style'),
      '#default_value' => $this->getSetting('thumbnail_responsive_image_style'),
      '#options' => $options,
      '#empty_option' => $this->t('- None -'),
      '#description' => $this->t('Applied to the poster when the thumbnail is a local file. YouTube posters are served from the provider and cannot use an image style, so they get a srcset built from the sizes YouTube publishes instead. Pick an uncropped style: the aspect ratio comes from the media format class in CSS.'),
    ];

    $form['thumbnail_sizes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Poster sizes attribute'),
      '#default_value' => $this->getSetting('thumbnail_sizes'),
      '#description' => $this->t('Used for the YouTube poster only. The local poster takes its sizes from the responsive image style above.'),
    ];

    $form['play_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Play button label'),
      '#default_value' => $this->getSetting('play_label'),
      '#size' => 30,
      '#maxlength' => 64,
      '#description' => $this->t('Optional text shown beside the play button before the video starts, for example Watch or Watch the video. Leave this empty to show the round play button on its own. The label is removed once the visitor starts the video, so the control goes back to a plain play/pause button.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    if ($this->getSetting('autoplay')) {
      $summary[] = $this->t('Autoplay on click: Yes');
    }

    if ($style = $this->loadResponsiveImageStyle($this->getSetting('thumbnail_responsive_image_style'))) {
      $summary[] = $this->t('Poster responsive image style: @style', ['@style' => $style->label()]);
    }
    else {
      $summary[] = $this->t('Poster responsive image style: none');
    }

    $play_label = trim((string) $this->getSetting('play_label'));
    if ($play_label !== '') {
      $summary[] = $this->t('Play button label: @label', ['@label' => $play_label]);
    }
    else {
      $summary[] = $this->t('Play button label: none, icon only');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};

      if (empty($value)) {
        continue;
      }

      $source_entity = $item->getEntity();

      // Resolve the primary thumbnail and an optional onerror fallback.
      $thumbnails = $this->getThumbnailUrls($source_entity, $value);

      // If no thumbnail, fall back to parent formatter.
      if (empty($thumbnails['primary'])) {
        $parent_elements = parent::viewElements($items, $langcode);
        if (isset($parent_elements[$delta])) {
          $elements[$delta] = $parent_elements[$delta];
        }
        continue;
      }

      // Remote videos used as a banner/card background autoplay on load (muted,
      // per browser policy) when the referring block requests it, mirroring the
      // local video behavior. Otherwise the poster stays until the visitor
      // clicks, and that click can start playback with sound.
      $referring_block = $source_entity instanceof MediaInterface ? $this->getReferringBlock($source_entity) : NULL;
      $autoplay_on_load = $referring_block && $referring_block->hasField('field_artsci_banner_autoplay') && (bool) $referring_block->get('field_artsci_banner_autoplay')->value;
      // Cookie id used to remember an explicit pause across page loads, keyed on
      // the referring block just like the local video.
      $cookie_id = $referring_block ? $referring_block->uuid() : NULL;

      // Build the direct embed URL (YouTube/Vimeo). Autoplay on inject, and mute
      // only when it must start without a click.
      $embed_url = $this->buildDirectEmbedUrl($value, $this->getSetting('autoplay') || $autoplay_on_load, $autoplay_on_load);

      // If we couldn't build the embed URL, fall back to parent.
      if (!$embed_url) {
        $parent_elements = parent::viewElements($items, $langcode);
        if (isset($parent_elements[$delta])) {
          $elements[$delta] = $parent_elements[$delta];
        }
        continue;
      }

      $elements[$delta] = [
        '#theme' => 'oembed_thumbnail',
        '#thumbnail_url' => $thumbnails['primary'],
        '#thumbnail_fallback_url' => $thumbnails['fallback'],
        // Set when the poster is a local file and a responsive image style is
        // configured. The template prefers this over the plain img.
        '#thumbnail_image' => $this->buildResponsivePoster($thumbnails['file'] ?? NULL, $source_entity instanceof MediaInterface ? (string) $source_entity->label() : ''),
        // Set for a remote poster that cannot use an image style, currently
        // YouTube. Ignored when thumbnail_image is present.
        '#thumbnail_srcset' => $thumbnails['srcset'] ?? NULL,
        '#thumbnail_sizes' => $this->getSetting('thumbnail_sizes'),
        '#video_url' => $value,
        '#iframe_url' => $embed_url,
        // Which provider API the facade JS should attach for play/pause.
        '#provider' => $this->extractYouTubeId($value) ? 'youtube' : ($this->extractVimeoId($value) ? 'vimeo' : NULL),
        '#max_width' => $this->getSetting('max_width'),
        '#max_height' => $this->getSetting('max_height'),
        '#autoplay' => $this->getSetting('autoplay'),
        // When TRUE the facade JS starts the video on load instead of waiting
        // for a click.
        '#autoplay_on_load' => $autoplay_on_load,
        // Remembers an explicit pause across reloads (empty when not in a block).
        '#cookie_id' => $cookie_id,
        // Visible call to action beside the play button. Empty renders the
        // round button on its own.
        '#play_label' => trim((string) $this->getSetting('play_label')),
        '#media_entity' => $source_entity,
        '#attached' => [
          'library' => [
            'layout_builder_custom/oembed-thumbnail',
          ],
        ],
        '#cache' => [
          // The poster can come from a file the media entity does not own, a
          // custom thumbnail on another image media, so its tags are merged in
          // as well or a replaced thumbnail would keep serving stale.
          'tags' => array_merge(
            $source_entity instanceof MediaInterface ? $source_entity->getCacheTags() : [],
            isset($thumbnails['file']) && $thumbnails['file'] ? $thumbnails['file']->getCacheTags() : []
          ),
          'contexts' => ['url.site'],
        ],
      ];
    }

    return $elements;
  }

  /**
   * Build the direct embed URL for YouTube or Vimeo.
   *
   * This bypasses Drupal's oEmbed iframe wrapper to allow proper autoplay.
   *
   * @param string $url
   *   The original video URL (YouTube or Vimeo).
   * @param bool $autoplay
   *   Whether to include autoplay parameter.
   *
   * @return string|null
   *   The direct embed URL or NULL if not supported.
   */
  protected function buildDirectEmbedUrl($url, $autoplay = FALSE, $muted = FALSE) {
    // Try YouTube first.
    $video_id = $this->extractYouTubeId($url);
    if ($video_id) {
      $embed_url = 'https://www.youtube.com/embed/' . $video_id;
      // enablejsapi lets the YouTube IFrame Player API attach to this iframe so
      // the shared play/pause button can drive it after load. playsinline keeps
      // iOS from forcing fullscreen. Mute is only forced when the video must
      // autoplay without a click, since browsers block unmuted autoplay then.
      $params = [
        'enablejsapi' => '1',
        'playsinline' => '1',
        'rel' => '0',
      ];
      if ($autoplay) {
        $params['autoplay'] = '1';
      }
      if ($muted) {
        $params['mute'] = '1';
      }
      $embed_url .= '?' . http_build_query($params);
      return $embed_url;
    }

    // Try Vimeo.
    $video_id = $this->extractVimeoId($url);
    if ($video_id) {
      $embed_url = 'https://player.vimeo.com/video/' . $video_id;
      // The Vimeo Player SDK attaches over postMessage, so no query flag is
      // needed. Mute is only forced for autoplay without a click.
      $params = [];
      if ($autoplay) {
        $params['autoplay'] = '1';
      }
      if ($muted) {
        $params['muted'] = '1';
      }
      if (!empty($params)) {
        $embed_url .= '?' . http_build_query($params);
      }
      return $embed_url;
    }

    return NULL;
  }

  /**
   * Extract YouTube video ID from various URL formats.
   *
   * @param string $url
   *   The YouTube URL.
   *
   * @return string|null
   *   The video ID or NULL.
   */
  protected function extractYouTubeId($url) {
    $patterns = [
      // youtu.be/VIDEO_ID
      '/youtu\.be\/([a-zA-Z0-9_-]+)/',
      // youtube.com/watch?v=VIDEO_ID
      '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
      // youtube.com/embed/VIDEO_ID
      '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
      // youtube.com/v/VIDEO_ID
      '/youtube\.com\/v\/([a-zA-Z0-9_-]+)/',
      // youtube.com/shorts/VIDEO_ID
      '/youtube\.com\/shorts\/([a-zA-Z0-9_-]+)/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        return $matches[1];
      }
    }

    return NULL;
  }

  /**
   * Extract Vimeo video ID from various URL formats.
   *
   * @param string $url
   *   The Vimeo URL.
   *
   * @return string|null
   *   The video ID or NULL.
   */
  protected function extractVimeoId($url) {
    $patterns = [
      // vimeo.com/VIDEO_ID
      '/vimeo\.com\/(\d+)/',
      // player.vimeo.com/video/VIDEO_ID
      '/player\.vimeo\.com\/video\/(\d+)/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        return $matches[1];
      }
    }

    return NULL;
  }

  /**
   * Resolve the primary and fallback thumbnail URLs for a media entity.
   *
   * Precedence:
   *   1. An editor-supplied custom thumbnail (field_video_thumbnail). This is
   *      a local file, so it needs no onerror fallback.
   *   2. For YouTube, the high-resolution maxresdefault image as the primary,
   *      with a reliable fallback (the auto-generated local thumbnail, or
   *      hqdefault) used by the template's onerror handler. maxresdefault is
   *      not generated for every video, so it can 404.
   *   3. For everything else (Vimeo, etc.), the auto-generated thumbnail.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The media entity.
   * @param string $video_url
   *   The original oEmbed video URL.
   *
   * @return array
   *   An array with 'primary' and 'fallback' keys. Either may be NULL.
   */
  protected function getThumbnailUrls(EntityInterface $entity, $video_url) {
    // 1. Custom thumbnail always wins. It is a local file, so it can go
    //    through a responsive image style.
    $custom = $this->getCustomThumbnailFile($entity);
    if ($custom) {
      return [
        'primary' => $this->fileUrlGenerator->generateAbsoluteString($custom->getFileUri()),
        'fallback' => NULL,
        'file' => $custom,
        'srcset' => NULL,
      ];
    }

    $auto_file = $this->getAutoThumbnailFile($entity);
    $auto = $auto_file ? $this->fileUrlGenerator->generateAbsoluteString($auto_file->getFileUri()) : NULL;

    // 2. YouTube: prefer maxresdefault, fall back to the reliable image.
    //    These are served from the provider, so no derivative can be built
    //    from them. YouTube does publish a fixed set of sizes though, so the
    //    poster still gets a real srcset, just a short one. Only mqdefault
    //    (320x180) and maxresdefault (1280x720) are true 16:9. hqdefault and
    //    sddefault are 4:3 with bars baked in, so putting them in the srcset
    //    would change the framing between breakpoints. hqdefault stays where
    //    it is useful, as the onerror fallback.
    $youtube_id = $this->extractYouTubeId($video_url);
    if ($youtube_id) {
      $base = 'https://i.ytimg.com/vi/' . $youtube_id . '/';
      return [
        'primary' => $base . 'maxresdefault.jpg',
        'fallback' => $auto ?: $base . 'hqdefault.jpg',
        'file' => NULL,
        'srcset' => $base . 'mqdefault.jpg 320w, ' . $base . 'maxresdefault.jpg 1280w',
      ];
    }

    // 3. Everything else uses the auto-generated thumbnail, which Drupal has
    //    already saved locally, so it can use a responsive image style too.
    return [
      'primary' => $auto,
      'fallback' => NULL,
      'file' => $auto_file,
      'srcset' => NULL,
    ];
  }

  /**
   * Builds the responsive poster render array for a local thumbnail file.
   *
   * @param \Drupal\file\FileInterface|null $file
   *   The local thumbnail file, or NULL.
   * @param string $alt
   *   The alt text.
   *
   * @return array|null
   *   A responsive_image render array, or NULL when the file is missing or no
   *   usable responsive image style is configured. The caller falls back to a
   *   plain img in that case.
   */
  protected function buildResponsivePoster($file, string $alt): ?array {
    if (!$file) {
      return NULL;
    }

    $style = $this->loadResponsiveImageStyle($this->getSetting('thumbnail_responsive_image_style'));
    if (!$style) {
      return NULL;
    }

    $uri = $file->getFileUri();

    $build = [
      '#theme' => 'responsive_image',
      '#responsive_image_style_id' => $style->id(),
      '#uri' => $uri,
      '#attributes' => [
        'alt' => $alt,
        'class' => ['oembed-thumbnail__image'],
        'loading' => 'lazy',
      ],
    ];

    // Intrinsic dimensions let the browser reserve the right box before the
    // poster loads. Missing dimensions are not fatal, the wrapper's
    // aspect-ratio still holds the space, so a failed read is ignored.
    $image = \Drupal::service('image.factory')->get($uri);
    if ($image->isValid()) {
      $build['#width'] = $image->getWidth();
      $build['#height'] = $image->getHeight();
    }

    return $build;
  }

  /**
   * Get the editor-supplied custom thumbnail file, if any.
   *
   * field_video_thumbnail references an image media entity (not a bare file),
   * so we resolve it to the media's source image file. Falls back to the
   * referenced media's own thumbnail if the source field is unexpectedly empty.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return \Drupal\file\FileInterface|null
   *   The custom thumbnail file, or NULL.
   */
  protected function getCustomThumbnailFile(EntityInterface $entity) {
    if (!($entity instanceof MediaInterface)) {
      return NULL;
    }

    if ($entity->hasField('field_video_thumbnail') && !$entity->get('field_video_thumbnail')->isEmpty()) {
      $referenced = $entity->get('field_video_thumbnail')->entity;
      if ($referenced instanceof MediaInterface) {
        // Prefer the image media's source file; fall back to its thumbnail.
        $file = NULL;
        if ($referenced->hasField('field_media_image') && !$referenced->get('field_media_image')->isEmpty()) {
          $file = $referenced->get('field_media_image')->entity;
        }
        if (!$file && $referenced->hasField('thumbnail') && !$referenced->get('thumbnail')->isEmpty()) {
          $file = $referenced->get('thumbnail')->entity;
        }
        if ($file) {
          return $file;
        }
      }
    }

    return NULL;
  }

  /**
   * Get the editor-supplied custom thumbnail URL, if any.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string|null
   *   Absolute URL to the custom thumbnail, or NULL.
   */
  protected function getCustomThumbnailUrl(EntityInterface $entity) {
    $file = $this->getCustomThumbnailFile($entity);

    return $file ? $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()) : NULL;
  }

  /**
   * Get the auto-generated thumbnail file from the media entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return \Drupal\file\FileInterface|null
   *   The auto-generated thumbnail file, or NULL.
   */
  protected function getAutoThumbnailFile(EntityInterface $entity) {
    if (!($entity instanceof MediaInterface)) {
      return NULL;
    }

    if ($entity->hasField('thumbnail') && !$entity->get('thumbnail')->isEmpty()) {
      return $entity->get('thumbnail')->entity ?: NULL;
    }

    return NULL;
  }

  /**
   * Get the auto-generated thumbnail URL from the media entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string|null
   *   Absolute URL to the auto-generated thumbnail, or NULL.
   */
  protected function getAutoThumbnailUrl(EntityInterface $entity) {
    $file = $this->getAutoThumbnailFile($entity);

    return $file ? $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()) : NULL;
  }

  /**
   * Get the block that renders this media, if any.
   *
   * The autoplay flag and the pause cookie id both live on the referring block
   * (banner or card), not on the media, so both are resolved through the
   * media's referring field item, the same walk the theme uses for local
   * videos.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The remote video media entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The referring block_content entity, or NULL.
   */
  protected function getReferringBlock(MediaInterface $media) {
    $referring_item = $media->_referringItem ?? NULL;
    if (!$referring_item) {
      return NULL;
    }
    $referring_field = $referring_item->getParent();
    if (!$referring_field) {
      return NULL;
    }
    $parent = $referring_field->getParent();
    if (!$parent instanceof EntityAdapter) {
      return NULL;
    }
    $parent_entity = $parent->getEntity();
    if ($parent_entity instanceof EntityInterface && $parent_entity->getEntityTypeId() === 'block_content') {
      return $parent_entity;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    if ($field_definition->getTargetEntityTypeId() !== 'media') {
      return FALSE;
    }

    return parent::isApplicable($field_definition);
  }

}
