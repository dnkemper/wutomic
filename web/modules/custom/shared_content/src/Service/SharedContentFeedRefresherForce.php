<?php

namespace Drupal\shared_content\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that force-refreshes shared content feeds with hash-based optimization.
 *
 * All XML to field mapping is delegated to SharedContentFieldMapper via
 * $this->fieldMapper->mapAllFields(). This service is responsible only for
 * fetching XML with conditional headers, deciding whether the content has
 * changed, and orchestrating create/update/delete on the node side.
 */
class SharedContentFeedRefresherForce {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The shared content field mapper service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFieldMapper
   */
  protected $fieldMapper;

  /**
   * Constructs a SharedContentFeedRefresherForce object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    QueueFactory $queueFactory,
    StateInterface $state,
    LoggerInterface $logger,
    SharedContentFieldMapper $fieldMapper,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->queueFactory = $queueFactory;
    $this->state = $state;
    $this->logger = $logger;
    $this->fieldMapper = $fieldMapper;
  }

  /**
   * Main entry point — processes nodes directly (not via queue).
   *
   * During cron, nodes are processed in-process rather than queued, because
   * the module does not register a QueueWorker plugin, so queue items would
   * never be consumed. Use Drush commands (sc-queue + sc-process) for
   * queue-based processing when you need batching.
   *
   * @param bool $force_all
   *   If TRUE, ignore field_last_fetch and process all nodes.
   * @param int $min_age
   *   Minimum age in seconds since last fetch before re-processing. Default 3600.
   */
  public function run($force_all = FALSE, $min_age = 3600) {
    $this->processNodes($force_all, $min_age);
    $this->processDeletedNodes();
  }
/**
 * Creates a new media image entity from a URL with type support.
 *
 * @param string $image_url
 *   The source image URL.
 * @param string $url_hash
 *   A hash of the URL for filename uniqueness.
 * @param string $title
 *   The title to use for alt text and media name.
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover').
 *
 * @return \Drupal\media\Entity\Media|null
 *   The created media entity, or NULL on failure.
 */
protected function createMediaImageWithType($image_url, $url_hash, $title, $type = 'headshot') {
    $image_data = $this->downloadImage($image_url);
    if (!$image_data) {
        \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
            '@url' => $image_url,
        ]);
        return NULL;
    }

    $file = $this->createFileEntityWithType($image_url, $url_hash, $image_data, $type);
    if (!$file) {
        return NULL;
    }

    $media = Media::create([
        'bundle' => 'image',
        'name' => $title . ' ' . $type,
        'uid' => \Drupal::currentUser()->id(),
        'status' => 1,
        'field_media_image' => [
            'target_id' => $file->id(),
            'alt' => $title,
        ],
    ]);
    $media->save();

    return $media;
}

/**
 * Updates an existing media entity with a new image with type support.
 *
 * @param \Drupal\media\Entity\Media $media
 *   The existing media entity.
 * @param string $image_url
 *   The new source image URL.
 * @param string $url_hash
 *   A hash of the URL for filename uniqueness.
 * @param string $title
 *   The title to use for alt text.
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover').
 *
 * @return \Drupal\media\Entity\Media|null
 *   The updated media entity, or NULL on failure.
 */
protected function updateMediaImageWithType(Media $media, $image_url, $url_hash, $title, $type = 'headshot') {
    $image_data = $this->downloadImage($image_url);
    if (!$image_data) {
        \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
            '@url' => $image_url,
        ]);
        return NULL;
    }

    // Get the old file for cleanup.
    $old_file = $media->get('field_media_image')->entity;

    // Create new file entity.
    $file = $this->createFileEntityWithType($image_url, $url_hash, $image_data, $type);
    if (!$file) {
        return NULL;
    }

    // Update media entity.
    $media->set('field_media_image', [
        'target_id' => $file->id(),
        'alt' => $title,
    ]);
    $media->set('name', $title . ' ' . $type);
    $media->save();

    // Delete old file if it exists and is no longer used.
    if ($old_file) {
        $this->cleanupOrphanedFile($old_file);
    }

    return $media;
}

/**
 * Creates a file entity from image data with type support.
 *
 * @param string $image_url
 *   The source URL (used for filename generation).
 * @param string $url_hash
 *   A hash of the URL for filename uniqueness.
 * @param string $image_data
 *   The raw image data.
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover').
 *
 * @return \Drupal\file\Entity\File|null
 *   The created file entity, or NULL on failure.
 */
protected function createFileEntityWithType($image_url, $url_hash, $image_data, $type = 'headshot') {
    // Parse filename and extension from URL.
    $parsed_url = parse_url($image_url);
    $path = $parsed_url['path'] ?? '';
    $path_info = pathinfo($path);
    $filename = $path_info['filename'] ?? $type;
    $extension = $path_info['extension'] ?? 'jpg';

    // Handle double extensions like .png.webp.
    if (preg_match('/\.(\w+)\.(\w+)$/', $path, $matches)) {
        $extension = $matches[2];
    }

    // Clean and build the filename.
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    $filename = $filename . '_' . $url_hash . '.' . $extension;

    // Prepare directory based on type.
    $directory = 'public://shared_content/' . $type . 's';
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    $uri = $directory . '/' . $filename;

    try {
        // Save the file data.
        $uri = $file_system->saveData($image_data, $uri, FileSystemInterface::EXISTS_REPLACE);

        $file = File::create([
            'filename' => $filename,
            'uri' => $uri,
            'status' => 1,
            'uid' => \Drupal::currentUser()->id(),
        ]);
        $file->save();

        return $file;
    }
    catch (\Exception $e) {
        \Drupal::logger('shared_content')->error('Failed to save file @filename: @message', [
            '@filename' => $filename,
            '@message' => $e->getMessage(),
        ]);
        return NULL;
    }
}
    /**
     * Maps the headshot image to a media entity.
     *
     * @param \Drupal\node\Entity\Node $node
     *   The node to update.
     * @param \SimpleXMLElement $xml
     *   The XML element containing the data.
     */
    protected function mapHeadshot(Node $node, \SimpleXMLElement $xml) {
        if (!isset($xml->headshot) || !$node->hasField('field_image')) {
            return;
        }

        $image_url = trim((string) $xml->headshot);

        if (empty($image_url)) {
            return;
        }

        // Generate a hash from the URL for comparison and filename uniqueness.
        $url_hash = substr(md5($image_url), 0, 8);

        try {
            // Check if we already have media attached.
            $current_media = $node->get('field_image')->entity;

            if ($current_media) {
                // Check if the source URL matches by comparing the hash in the filename.
                $current_file = $current_media->get('field_media_image')->entity;
                if ($current_file) {
                    $current_filename = $current_file->getFilename();
                    if (strpos($current_filename, $url_hash) !== FALSE) {
                        // Same image URL, no update needed.
                        \Drupal::logger('shared_content')->debug('Headshot unchanged for node @nid, skipping.', [
                            '@nid' => $node->id() ?? 'new',
                        ]);
                        return;
                    }
                }

                // URL has changed, update the existing media entity.
                $media = $this->updateMediaImage($current_media, $image_url, $url_hash, $node->getTitle());
                if ($media) {
                    \Drupal::logger('shared_content')->info('Updated headshot media @mid for node @title from @url.', [
                        '@mid' => $media->id(),
                        '@title' => $node->getTitle(),
                        '@url' => $image_url,
                    ]);
                }
                return;
            }

            // No existing media, create new.
            $media = $this->createMediaImage($image_url, $url_hash, $node->getTitle());
            if ($media) {
                $node->set('field_image', [
                    'target_id' => $media->id(),
                ]);
                \Drupal::logger('shared_content')->info('Created headshot media @mid for node @title from @url.', [
                    '@mid' => $media->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $image_url,
                ]);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error processing headshot for node @title: @message', [
                '@title' => $node->getTitle(),
                '@message' => $e->getMessage(),
            ]);
        }
    }
    /**
     * Maps the cover image to a media entity.
     *
     * @param \Drupal\node\Entity\Node $node
     *   The node to update.
     * @param \SimpleXMLElement $xml
     *   The XML element containing the data.
     */
    protected function mapCover(Node $node, \SimpleXMLElement $xml) {
        if (!isset($xml->cover) || !$node->hasField('field_image')) {
            return;
        }

        $image_url = trim((string) $xml->cover);

        if (empty($image_url)) {
            return;
        }

        // Generate a hash from the URL for comparison and filename uniqueness.
        $url_hash = substr(md5($image_url), 0, 8);

        try {
            // Check if we already have media attached.
            $current_media = $node->get('field_image')->entity;

            if ($current_media) {
                // Check if the source URL matches by comparing the hash in the filename.
                $current_file = $current_media->get('field_media_image')->entity;
                if ($current_file) {
                    $current_filename = $current_file->getFilename();
                    if (strpos($current_filename, $url_hash) !== FALSE) {
                        // Same image URL, no update needed.
                        \Drupal::logger('shared_content')->debug('Cover unchanged for node @nid, skipping.', [
                            '@nid' => $node->id() ?? 'new',
                        ]);
                        return;
                    }
                }

                // URL has changed, update the existing media entity.
                $media = $this->updateCoverImage($current_media, $image_url, $url_hash, $node->getTitle());
                if ($media) {
                    \Drupal::logger('shared_content')->info('Updated cover media @mid for node @title from @url.', [
                        '@mid' => $media->id(),
                        '@title' => $node->getTitle(),
                        '@url' => $image_url,
                    ]);
                }
                return;
            }

            // No existing media, create new.
            $media = $this->createCoverImage($image_url, $url_hash, $node->getTitle());
            if ($media) {
                $node->set('field_image', [
                    'target_id' => $media->id(),
                ]);
                \Drupal::logger('shared_content')->info('Created cover media @mid for node @title from @url.', [
                    '@mid' => $media->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $image_url,
                ]);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error processing cover for node @title: @message', [
                '@title' => $node->getTitle(),
                '@message' => $e->getMessage(),
            ]);
        }
    }
    /**
     * Creates a new media image entity from a URL.
     *
     * @param string $image_url
     *   The source image URL.
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $title
     *   The title to use for alt text and media name.
     *
     * @return \Drupal\media\Entity\Media|null
     *   The created media entity, or NULL on failure.
     */
    protected function createMediaImage($image_url, $url_hash, $title) {
        $image_data = $this->downloadImage($image_url);
        if (!$image_data) {
            \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
                '@url' => $image_url,
            ]);
            return NULL;
        }

        $file = $this->createFileEntity($image_url, $url_hash, $image_data);
        if (!$file) {
            return NULL;
        }

        $media = Media::create([
            'bundle' => 'image',
            'name' => $title . ' headshot',
            'uid' => \Drupal::currentUser()->id(),
            'status' => 1,
            'field_media_image' => [
                'target_id' => $file->id(),
                'alt' => $title,
            ],
        ]);
        $media->save();

        return $media;
    }

    /**
     * Creates a new media image entity from a URL.
     *
     * @param string $image_url
     *   The source image URL.
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $title
     *   The title to use for alt text and media name.
     *
     * @return \Drupal\media\Entity\Media|null
     *   The created media entity, or NULL on failure.
     */
    protected function createCoverImage($image_url, $url_hash, $title) {
        $image_data = $this->downloadImage($image_url);
        if (!$image_data) {
            \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
                '@url' => $image_url,
            ]);
            return NULL;
        }

        $file = $this->createCoverEntity($image_url, $url_hash, $image_data);
        if (!$file) {
            return NULL;
        }

        $media = Media::create([
            'bundle' => 'image',
            'name' => $title . ' cover',
            'uid' => \Drupal::currentUser()->id(),
            'status' => 1,
            'field_media_image' => [
                'target_id' => $file->id(),
                'alt' => $title,
            ],
        ]);
        $media->save();

        return $media;
    }

    /**
     * Updates an existing cover media entity with a new image.
     *
     * Parallel to updateMediaImage() but for book covers — uses
     * createCoverEntity() (which writes to public://shared_content/covers)
     * and names the media "{title} cover" so it stays distinguishable from
     * headshot media.
     *
     * @param \Drupal\media\Entity\Media $media
     *   The existing media entity.
     * @param string $image_url
     *   The new source image URL.
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $title
     *   The title to use for alt text.
     *
     * @return \Drupal\media\Entity\Media|null
     *   The updated media entity, or NULL on failure.
     */
    protected function updateCoverImage(Media $media, $image_url, $url_hash, $title) {
        $image_data = $this->downloadImage($image_url);
        if (!$image_data) {
            \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
                '@url' => $image_url,
            ]);
            return NULL;
        }

        // Get the old file for cleanup.
        $old_file = $media->get('field_media_image')->entity;

        // Create new file entity in the covers directory.
        $file = $this->createCoverEntity($image_url, $url_hash, $image_data);
        if (!$file) {
            return NULL;
        }

        // Update media entity.
        $media->set('field_media_image', [
            'target_id' => $file->id(),
            'alt' => $title,
        ]);
        $media->set('name', $title . ' cover');
        $media->save();

        // Delete old file if it exists and is no longer used.
        if ($old_file) {
            $this->cleanupOrphanedFile($old_file);
        }

        return $media;
    }

    /**
     * Updates an existing media entity with a new image.
     *
     * @param \Drupal\media\Entity\Media $media
     *   The existing media entity.
     * @param string $image_url
     *   The new source image URL.
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $title
     *   The title to use for alt text.
     *
     * @return \Drupal\media\Entity\Media|null
     *   The updated media entity, or NULL on failure.
     */
    protected function updateMediaImage(Media $media, $image_url, $url_hash, $title) {
        $image_data = $this->downloadImage($image_url);
        if (!$image_data) {
            \Drupal::logger('shared_content')->warning('Failed to download image from @url.', [
                '@url' => $image_url,
            ]);
            return NULL;
        }

        // Get the old file for cleanup.
        $old_file = $media->get('field_media_image')->entity;

        // Create new file entity.
        $file = $this->createFileEntity($image_url, $url_hash, $image_data);
        if (!$file) {
            return NULL;
        }

        // Update media entity.
        $media->set('field_media_image', [
            'target_id' => $file->id(),
            'alt' => $title,
        ]);
        $media->set('name', $title . ' headshot');
        $media->save();

        // Delete old file if it exists and is no longer used.
        if ($old_file) {
            $this->cleanupOrphanedFile($old_file);
        }

        return $media;
    }

    /**
     * Creates a file entity from image data.
     *
     * @param string $image_url
     *   The source URL (used for filename generation).
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $image_data
     *   The raw image data.
     *
     * @return \Drupal\file\Entity\File|null
     *   The created file entity, or NULL on failure.
     */
    protected function createFileEntity($image_url, $url_hash, $image_data) {
        // Parse filename and extension from URL.
        $parsed_url = parse_url($image_url);
        $path = $parsed_url['path'] ?? '';
        $path_info = pathinfo($path);
        $filename = $path_info['filename'] ?? 'headshot';
        $extension = $path_info['extension'] ?? 'jpg';

        // Handle double extensions like .png.webp.
        if (preg_match('/\.(\w+)\.(\w+)$/', $path, $matches)) {
            $extension = $matches[2];
        }

        // Clean and build the filename.
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = $filename . '_' . $url_hash . '.' . $extension;

        // Prepare directory.
        $directory = 'public://shared_content/headshots';
        /** @var \Drupal\Core\File\FileSystemInterface $file_system */
        $file_system = \Drupal::service('file_system');
        $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

        $uri = $directory . '/' . $filename;

        try {
            // Save the file data.
            $uri = $file_system->saveData($image_data, $uri, FileSystemInterface::EXISTS_REPLACE);

            $file = File::create([
                'filename' => $filename,
                'uri' => $uri,
                'status' => 1,
                'uid' => \Drupal::currentUser()->id(),
            ]);
            $file->save();

            return $file;
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Failed to save file @filename: @message', [
                '@filename' => $filename,
                '@message' => $e->getMessage(),
            ]);
            return NULL;
        }
    }
    /**
     * Creates a file entity from image data.
     *
     * @param string $image_url
     *   The source URL (used for filename generation).
     * @param string $url_hash
     *   A hash of the URL for filename uniqueness.
     * @param string $image_data
     *   The raw image data.
     *
     * @return \Drupal\file\Entity\File|null
     *   The created file entity, or NULL on failure.
     */
    protected function createCoverEntity($image_url, $url_hash, $image_data) {
        // Parse filename and extension from URL.
        $parsed_url = parse_url($image_url);
        $path = $parsed_url['path'] ?? '';
        $path_info = pathinfo($path);
        $filename = $path_info['filename'] ?? 'cover';
        $extension = $path_info['extension'] ?? 'jpg';

        // Handle double extensions like .png.webp.
        if (preg_match('/\.(\w+)\.(\w+)$/', $path, $matches)) {
            $extension = $matches[2];
        }

        // Clean and build the filename.
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = $filename . '_' . $url_hash . '.' . $extension;

        // Prepare directory.
        $directory = 'public://shared_content/covers';
        /** @var \Drupal\Core\File\FileSystemInterface $file_system */
        $file_system = \Drupal::service('file_system');
        $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

        $uri = $directory . '/' . $filename;

        try {
            // Save the file data.
            $uri = $file_system->saveData($image_data, $uri, FileSystemInterface::EXISTS_REPLACE);

            $file = File::create([
                'filename' => $filename,
                'uri' => $uri,
                'status' => 1,
                'uid' => \Drupal::currentUser()->id(),
            ]);
            $file->save();

            // Set a default centered focal_point Crop entity on the file so
            // image styles that use focal_point_scale_and_crop (and the
            // focal point preview UI) have explicit coordinates to work
            // with. focal_point falls back to a transient center crop when
            // none is saved, but in practice the preview UI and some style
            // pipelines render more reliably when an actual Crop entity
            // exists on the file. Mirrors what a manual upload through the
            // focal point widget does when the editor leaves the indicator
            // at center. Wrapped in module checks so the action stays
            // working if crop or focal_point ever get disabled.
            $module_handler = \Drupal::moduleHandler();
            if ($module_handler->moduleExists('crop') && $module_handler->moduleExists('focal_point')) {
                $image = \Drupal::service('image.factory')->get($uri);
                if ($image->isValid()) {
                    \Drupal\crop\Entity\Crop::create([
                        'type' => 'focal_point',
                        'entity_id' => $file->id(),
                        'entity_type' => 'file',
                        'uri' => $uri,
                        'x' => (int) round($image->getWidth() / 2),
                        'y' => (int) round($image->getHeight() / 2),
                    ])->save();
                }
            }

            return $file;
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Failed to save file @filename: @message', [
                '@filename' => $filename,
                '@message' => $e->getMessage(),
            ]);
            return NULL;
        }
    }
    /**
     * Downloads an image from a URL.
     *
     * @param string $url
     *   The image URL.
     *
     * @return string|null
     *   The image data, or NULL on failure.
     */
    protected function downloadImage($url) {
        try {
            /** @var \GuzzleHttp\ClientInterface $client */
            $client = \Drupal::httpClient();
            $response = $client->get($url, [
                'timeout' => 30,
                'http_errors' => FALSE,
            ]);

            if ($response->getStatusCode() !== 200) {
                \Drupal::logger('shared_content')->warning('HTTP @status fetching image @url.', [
                    '@status' => $response->getStatusCode(),
                    '@url' => $url,
                ]);
                return NULL;
            }

            $content_type = $response->getHeaderLine('Content-Type');
            if (!empty($content_type) && strpos($content_type, 'image/') === FALSE) {
                \Drupal::logger('shared_content')->warning('Non-image content type @type for @url.', [
                    '@type' => $content_type,
                    '@url' => $url,
                ]);
                return NULL;
            }

            return $response->getBody()->getContents();
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error downloading image from @url: @message', [
                '@url' => $url,
                '@message' => $e->getMessage(),
            ]);
            return NULL;
        }
    }

    /**
     * Removes a file entity if it's no longer referenced.
     *
     * @param \Drupal\file\Entity\File $file
     *   The file entity to potentially remove.
     */
    protected function cleanupOrphanedFile(File $file) {
        // Check if file is used elsewhere.
        $usage = \Drupal::service('file.usage')->listUsage($file);

        if (empty($usage) || $this->isFileOnlyUsedOnce($usage)) {
            try {
                $file->delete();
                \Drupal::logger('shared_content')->debug('Deleted orphaned file @fid.', [
                    '@fid' => $file->id(),
                ]);
            }
            catch (\Exception $e) {
                \Drupal::logger('shared_content')->warning('Could not delete orphaned file @fid: @message', [
                    '@fid' => $file->id(),
                    '@message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Checks if a file usage array indicates only one reference.
     *
     * @param array $usage
     *   The usage array from file.usage service.
     *
     * @return bool
     *   TRUE if the file has only one reference total.
     */
    protected function isFileOnlyUsedOnce(array $usage) {
        $total = 0;
        foreach ($usage as $module => $types) {
            foreach ($types as $type => $ids) {
                $total += count($ids);
            }
        }
        return $total <= 1;
    }

  /**
   * Cron-safe chunked processing.
   *
   * Processes a fixed number of nodes per cron run using State API to track
   * the current offset across runs. This prevents timeouts on sites with
   * hundreds of shared content nodes. processDeletedNodes() is only called
   * at the start of each full cycle (when offset resets to 0).
   *
   * @param int $chunk_size
   *   How many nodes to process per cron run. Default 25.
   * @param int $min_age
   *   Minimum seconds since last fetch before re-processing. Default 3600.
   */
  public function runChunked($chunk_size = 20, $min_age = 3600) {
    $all_nids = array_values($this->getEligibleNodeIds(FALSE, $min_age));

    if (empty($all_nids)) {
      $this->state->delete('shared_content.cron_offset');
      return;
    }

    $total = count($all_nids);
    $offset = (int) $this->state->get('shared_content.cron_offset', 0);

    // Reset if offset has gone past the end of the list.
    if ($offset >= $total) {
      $offset = 0;
    }

    $chunk = array_slice($all_nids, $offset, $chunk_size);

    $this->logger->info('Cron chunk: processing @count nodes (offset @offset of @total).', [
      '@count' => count($chunk),
      '@offset' => $offset,
      '@total' => $total,
    ]);

    foreach ($chunk as $nid) {
      $this->processNode($nid);
    }

    // Advance offset; wrap back to 0 after completing a full cycle.
    $next_offset = $offset + $chunk_size;
    $this->state->set(
      'shared_content.cron_offset',
      $next_offset >= $total ? 0 : $next_offset
    );

    // No separate processDeletedNodes() call needed — processNode() already
    // deletes nodes immediately when it encounters a 404 or empty XML.
  }

  /**
   * Queues eligible nodes for refresh (used by Drush sc-queue command).
   *
   * @param bool $force_all
   *   If TRUE, ignore field_last_fetch and queue everything.
   * @param int $min_age
   *   Minimum seconds since last fetch before re-queuing.
   */
  public function queueNodesForRefresh($force_all = FALSE, $min_age = 3600) {
    $queue = $this->queueFactory->get('shared_content_refresh');
    $nids = $this->getEligibleNodeIds($force_all, $min_age);
    $queued_count = 0;

    foreach ($nids as $nid) {
      if ($queue->createItem(['nid' => $nid])) {
        $queued_count++;
      }
    }

    $this->logger->info('Queued @count nodes for shared content refresh (force_all: @force, min_age: @age).', [
      '@count' => $queued_count,
      '@force' => $force_all ? 'yes' : 'no',
      '@age' => $min_age,
    ]);
  }

  /**
   * Returns eligible node IDs based on fetch age.
   *
   * Visibility is public so Drush commands and Batch callbacks can retrieve
   * the candidate list without duplicating the query logic.
   *
   * @param bool $force_all
   *   If TRUE, return all shared content nodes regardless of last fetch time.
   * @param int $min_age
   *   Minimum seconds since last fetch before a node is considered eligible.
   *
   * @return int[]
   *   Array of eligible node IDs.
   */
  public function getEligibleNodeIds($force_all = FALSE, $min_age = 3600) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['person', 'event', 'article', 'book'], 'IN')
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');

    if (!$force_all) {
      $threshold = time() - $min_age;
      $query->condition(
        $query->orConditionGroup()
          ->condition('field_last_fetch', $threshold, '<')
          ->condition('field_last_fetch', NULL, 'IS NULL')
      );
    }

    return $query->execute();
  }

  /**
   * Processes eligible nodes directly (used during cron).
   *
   * @param bool $force_all
   *   If TRUE, ignore field_last_fetch.
   * @param int $min_age
   *   Minimum seconds since last fetch.
   */
  protected function processNodes($force_all = FALSE, $min_age = 3600) {
    $nids = $this->getEligibleNodeIds($force_all, $min_age);

    if (empty($nids)) {
      return;
    }

    $this->logger->info('Processing @count shared content nodes (force_all: @force).', [
      '@count' => count($nids),
      '@force' => $force_all ? 'yes' : 'no',
    ]);

    foreach ($nids as $nid) {
      $this->processNode($nid);
    }
  }

  /**
   * Processes a single node — fetches XML, compares hash, updates if changed.
   *
   * @param int $nid
   *   The node ID to process.
   * @param bool $force_remap
   *   If TRUE, skip both the 304 short circuit and the local hash equality
   *   short circuit and always run the field mapper. Use this when new
   *   mapper logic needs to be applied to already imported nodes whose
   *   source XML hasn't changed since last fetch.
   */
  public function processNode($nid, $force_remap = FALSE) {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      $this->logger->warning('Node @nid not found during processing.', ['@nid' => $nid]);
      return;
    }

    $shared_url = $node->get('field_shared_content_xml')->value;
    if (empty($shared_url)) {
      return;
    }

    $result = $this->fetchXmlWithCaching($shared_url, $node, $force_remap);

    if ($result === 'NOT_FOUND') {
      $this->deleteNodeWithEmptySource($node, 'source not found (HTTP 404)');
      return;
    }

    if ($result === 'NOT_MODIFIED') {
      // Server confirmed nothing changed — just update tracking.
      // This branch cannot be reached when $force_remap is TRUE because
      // fetchXmlWithCaching() omits the conditional request headers in
      // that mode, so the server will always return a full 200 body.
      $this->trackNodeAsActive($node, $shared_url);
      $this->logger->debug('Node @nid not modified (304).', ['@nid' => $nid]);
      return;
    }

    if (!$result) {
      return;
    }

    [$xml] = $result;

    // Compute hash from the same value that hook_node_presave() uses:
    // field_shared_content stores base64_encode($xml->asXML()), and presave
    // hashes that value into field_content_hash. We must compare apples to
    // apples or the check never matches and every node gets re-saved.
    $shared_content_value = base64_encode($xml->asXML());
    $new_hash = hash('sha256', $shared_content_value);
    $current_hash = $node->hasField('field_content_hash') ? $node->get('field_content_hash')->value : NULL;

    // Content unchanged — normally we'd bump field_last_fetch via direct DB
    // write and skip. In force_remap mode we ignore the hash match so the
    // mapper actually runs against the stored XML, letting new mapper logic
    // reach nodes whose feeds haven't changed.
    if (!$force_remap && $current_hash === $new_hash) {
      $this->stampLastFetch($nid);
      $this->trackNodeAsActive($node, $shared_url);
      return;
    }

    // Content has changed (or force_remap) — update all fields.
    $xml_element = $this->getXmlElement($xml, $shared_url);

    if ($this->isEmptyXml($xml_element)) {
      $this->deleteNodeWithEmptySource($node, 'empty or invalid XML');
      return;
    }

    $this->fieldMapper->mapAllFields($node, $xml_element, $shared_url);

    // Refresh attached image media for bundles that carry one. Uses this
    // service's local image methods (writes to covers/ and headshots/
    // subdirectories on disk) on top of whatever the field mapper already
    // did during mapAllFields(). The dedup checks inside each map method
    // short circuit when nothing has changed.
    switch ($node->bundle()) {
      case 'book':
        $this->mapCover($node, $xml_element);
        break;

      case 'person':
        $this->mapHeadshot($node, $xml_element);
        break;
    }

    if ($node->hasField('field_last_fetch')) {
      $node->set('field_last_fetch', time());
    }

    $node->set('field_shared_content', $shared_content_value);
    // field_content_hash is set automatically by hook_node_presave().
    $node->save();

    $this->trackNodeAsActive($node, $shared_url);
    $this->logger->info('Updated node @nid with fresh content from @url@forced.', [
      '@nid' => $nid,
      '@url' => $shared_url,
      '@forced' => $force_remap ? ' (forced remap)' : '',
    ]);
  }

  /**
   * Re-runs the field mapper against every shared content node.
   *
   * Ignores hash equality so that newly added or newly fixed mapper logic
   * actually reaches nodes whose source feeds are byte for byte identical
   * to what was imported originally. Deletion handling still fires when
   * the source returns 404 or empty XML.
   *
   * @param array|null $types
   *   Optional bundle filter (e.g. ['person']). NULL → all shared bundles.
   * @param int|null $limit
   *   Optional cap on how many nodes to remap. NULL → no cap.
   */
  public function remapAll(?array $types = NULL, ?int $limit = NULL) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL');
    if ($types) {
      $query->condition('type', $types, 'IN');
    }
    else {
      $query->condition('type', ['person', 'event', 'article', 'book'], 'IN');
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    $nids = $query->execute();

    $this->logger->info('remapAll: processing @count nodes (force_remap=TRUE).', [
      '@count' => count($nids),
    ]);

    foreach ($nids as $nid) {
      $this->processNode($nid, TRUE);
    }
  }

  /**
   * Fetches XML with HTTP caching headers (If-None-Match + If-Modified-Since).
   *
   * @param string $url
   *   The XML feed URL.
   * @param \Drupal\node\NodeInterface $node
   *   The node being refreshed (used for cache headers).
   * @param bool $bypass_conditional
   *   If TRUE, omit If-None-Match and If-Modified-Since headers so the
   *   server returns a full 200 body instead of 304. Used when the caller
   *   wants to remap the node regardless of whether the feed content has
   *   changed since last fetch.
   *
   * @return array|string|null
   *   [SimpleXMLElement, hash] on success, 'NOT_FOUND' on 404,
   *   'NOT_MODIFIED' on 304, NULL on other failure.
   */
  public function fetchXmlWithCaching($url, NodeInterface $node, $bypass_conditional = FALSE) {
    $options = [
      'headers' => ['Accept' => 'application/xml'],
      'timeout' => 120,
      'http_errors' => FALSE,
    ];

    if (!$bypass_conditional) {
      // Add If-None-Match using the stored content hash.
      $current_hash = $node->hasField('field_content_hash') ? $node->get('field_content_hash')->value : NULL;
      if ($current_hash) {
        $options['headers']['If-None-Match'] = '"' . $current_hash . '"';
      }

      // Add If-Modified-Since using the stored last fetch timestamp.
      $last_fetch = $node->hasField('field_last_fetch') ? $node->get('field_last_fetch')->value : NULL;
      if ($last_fetch) {
        $options['headers']['If-Modified-Since'] = gmdate('D, d M Y H:i:s \G\M\T', $last_fetch);
      }
    }
    else {
      // Belt and suspenders: tell upstream caches we want a fresh response.
      $options['headers']['Cache-Control'] = 'no-cache';
      $options['headers']['Pragma'] = 'no-cache';
    }

    try {
      $response = $this->httpClient->request('GET', $url, $options);
      $status_code = $response->getStatusCode();

      if ($status_code === 404) {
        $this->logger->debug('Source not found (404) for @url.', ['@url' => $url]);
        return 'NOT_FOUND';
      }

      if ($status_code === 304) {
        $this->logger->debug('Content not modified (304) for @url.', ['@url' => $url]);
        return 'NOT_MODIFIED';
      }

      if ($status_code !== 200) {
        $this->logger->error('HTTP @status fetching @url.', [
          '@status' => $status_code,
          '@url' => $url,
        ]);
        return NULL;
      }

      $content = $response->getBody()->getContents();
      if (empty($content)) {
        $this->logger->warning('Empty response body from @url.', ['@url' => $url]);
        return NULL;
      }

      $xml = @simplexml_load_string($content);
      if ($xml === FALSE) {
        $this->logger->error('Failed to parse XML from @url.', ['@url' => $url]);
        return NULL;
      }

      return [$xml, hash('sha256', $content)];
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Determines the XML element to read based on feed source.
   *
   * @param \SimpleXMLElement $xml
   *   The parsed XML document.
   * @param string $shared_url
   *   The shared content URL.
   *
   * @return \SimpleXMLElement|null
   *   The XML node element, or NULL.
   */
  protected function getXmlElement($xml, $shared_url) {
    return $xml->node;
  }

  /**
   * Checks if an XML element is effectively empty.
   *
   * @param \SimpleXMLElement|null $xml_element
   *   The XML element to check.
   *
   * @return bool
   *   TRUE if the XML element is empty or NULL.
   */
  protected function isEmptyXml($xml_element) {
    if (!$xml_element) {
      return TRUE;
    }
    return count($xml_element->children()) === 0 && trim((string) $xml_element) === '';
  }

  /**
   * Deletes a node whose source returned 404 or empty XML.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to delete.
   * @param string $reason
   *   The reason for deletion (e.g. '404' or 'empty XML').
   */
  protected function deleteNodeWithEmptySource(NodeInterface $node, $reason) {
    $nid = $node->id();
    $title = $node->getTitle();
    $source_url = $node->get('field_shared_content_xml')->value;

    try {
      // Derive the aggregator guid from field_shared_content_xml.
      // field_shared_content_xml = {base_url}/xml/{type}/{orig_nid}/rss.xml
      // aggregator guid = {orig_nid} at {base_url}.
      $iid = NULL;
      $guid = NULL;
      if (preg_match('#^(https?://[^/]+)/xml/[^/]+/(\d+)/rss\.xml$#', $source_url, $matches)) {
        $base_url = $matches[1];
        $orig_nid = $matches[2];
        $guid = $orig_nid . ' at ' . $base_url;

        $iid = $this->database->select('aggregator_item', 'ai')
          ->fields('ai', ['iid'])
          ->condition('ai.guid', $guid)
          ->execute()
          ->fetchField();
      }

      $node->delete();

      $this->logger->info('Auto-deleted node @nid (@title) — @reason — @url.', [
        '@nid' => $nid,
        '@title' => $title,
        '@reason' => $reason,
        '@url' => $source_url,
      ]);

      if ($iid) {
        $aggregator_item = $this->entityTypeManager
          ->getStorage('aggregator_item')
          ->load($iid);
        if ($aggregator_item) {
          $aggregator_item->delete();
          $this->logger->info('Deleted aggregator_item @iid (guid: @guid) for node @nid.', [
            '@iid' => $iid,
            '@guid' => $guid,
            '@nid' => $nid,
          ]);
        }
      }
      else {
        $this->logger->warning('No aggregator_item found for node @nid — could not parse guid from @url.', [
          '@nid' => $nid,
          '@url' => $source_url,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Records a node as actively synced (kept for reference/debugging).
   *
   * Note: deletion detection no longer relies on this state tracking —
   * processDeletedNodes() actively checks sources via HTTP instead.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to track.
   * @param string $source_url
   *   The source XML URL for the node.
   */
  protected function trackNodeAsActive(NodeInterface $node, $source_url) {
    // State tracking kept for backward compatibility with any external
    // tooling that reads shared_content.active_nodes. The actual deletion
    // check in processDeletedNodes() uses live HTTP probes instead.
    $feed_id = hash('md5', $source_url);
    $tracking = $this->state->get('shared_content.active_nodes', []);

    $tracking[$feed_id][$node->id()] = [
      'nid' => $node->id(),
      'url' => $source_url,
      'last_seen' => time(),
    ];

    $this->state->set('shared_content.active_nodes', $tracking);
  }

  /**
   * Updates field_last_fetch via direct DB write, bypassing entity hooks.
   *
   * Used when content hasn't changed — avoids the overhead of a full
   * $node->save() (presave hooks, cache invalidation, entity hooks) just to
   * bump a timestamp. Updates both the node field table and the revision
   * field table so that the current revision stays in sync.
   *
   * @param int $nid
   *   The node ID to update.
   */
  protected function stampLastFetch($nid) {
    $now = time();
    try {
      // Row must exist — stampLastFetch is only called when field_content_hash
      // matches, meaning $node->save() ran at least once before to set it.
      $this->database->update('node__field_last_fetch')
        ->fields(['field_last_fetch_value' => $now])
        ->condition('entity_id', $nid)
        ->execute();
      $this->database->update('node_revision__field_last_fetch')
        ->fields(['field_last_fetch_value' => $now])
        ->condition('entity_id', $nid)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Direct DB update for field_last_fetch failed on node @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Checks all shared content nodes for deleted/gone sources and unpublishes them.
   *
   * This actively queries source URLs rather than relying on state-based
   * tracking, which was only populated if processNode() had run before.
   * Nodes whose source returns 404 or empty XML for 7+ days are unpublished.
   * Nodes whose source returns 404 immediately (no last_fetch recorded) are
   * auto-deleted since they were never successfully synced.
   */
  public function processDeletedNodes() {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['person', 'event', 'article', 'book'], 'IN')
      ->condition('field_shared_content_xml', NULL, 'IS NOT NULL')
      ->condition('status', 1);

    $nids = $query->execute();

    if (empty($nids)) {
      return;
    }

    $nodes = $node_storage->loadMultiple($nids);
    $threshold = time() - (86400 * 7);
    $unpublished_count = 0;
    $deleted_count = 0;

    foreach ($nodes as $node) {
      $source_url = $node->get('field_shared_content_xml')->value;
      if (empty($source_url)) {
        continue;
      }

      // Only check nodes that haven't been fetched recently to avoid hammering sources.
      $last_fetch = $node->hasField('field_last_fetch') ? $node->get('field_last_fetch')->value : NULL;
      // Only check nodes not fetched in 2 days.
      $check_threshold = time() - (86400 * 2);
      if ($last_fetch && $last_fetch > $check_threshold) {
        continue;
      }

      try {
        $response = $this->httpClient->request('GET', $source_url, [
          'timeout' => 30,
          'http_errors' => FALSE,
        ]);
        $status_code = $response->getStatusCode();

        if ($status_code === 404 || $status_code === 410) {
          // If no successful fetch was ever recorded, delete immediately.
          if (empty($last_fetch)) {
            $nid = $node->id();
            $title = $node->getTitle();
            try {
              $node->delete();
              $deleted_count++;
              $this->logger->info('Deleted node @nid (@title) — source gone (@status) and never successfully fetched: @url.', [
                '@nid' => $nid,
                '@title' => $title,
                '@status' => $status_code,
                '@url' => $source_url,
              ]);
            }
            catch (\Exception $e) {
              $this->logger->error('Failed to delete node @nid: @message', [
                '@nid' => $nid,
                '@message' => $e->getMessage(),
              ]);
            }
            continue;
          }

          // If last successful fetch was more than 7 days ago, unpublish.
          if ($last_fetch < $threshold) {
            $node->setUnpublished();
            $node->save();
            $unpublished_count++;
            $this->logger->info('Unpublished node @nid (@title) — source gone (@status) for 7+ days: @url.', [
              '@nid' => $node->id(),
              '@title' => $node->getTitle(),
              '@status' => $status_code,
              '@url' => $source_url,
            ]);
          }
        }
        elseif ($status_code === 200) {
          $content = $response->getBody()->getContents();
          $xml = @simplexml_load_string($content);

          if ($xml !== FALSE) {
            $xml_element = $this->getXmlElement($xml, $source_url);
            // If XML parses but is effectively empty, treat as deleted.
            if ($xml_element && count($xml_element->children()) === 0 && trim((string) $xml_element) === '') {
              if ($last_fetch && $last_fetch < $threshold) {
                $node->setUnpublished();
                $node->save();
                $unpublished_count++;
                $this->logger->info('Unpublished node @nid — empty XML response for 7+ days: @url.', [
                  '@nid' => $node->id(),
                  '@url' => $source_url,
                ]);
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        // Network errors are not treated as deletion signals — could be transient.
        $this->logger->warning('Error checking source for node @nid (@url): @message', [
          '@nid' => $node->id(),
          '@url' => $source_url,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($deleted_count > 0 || $unpublished_count > 0) {
      $this->logger->info('Deleted @del, unpublished @unpub stale shared content nodes.', [
        '@del' => $deleted_count,
        '@unpub' => $unpublished_count,
      ]);
    }
  }

}
