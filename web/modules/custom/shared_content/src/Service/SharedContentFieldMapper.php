<?php

namespace Drupal\shared_content\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps XML data to node fields for shared content.
 */
class SharedContentFieldMapper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a SharedContentFieldMapper object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
    ClientInterface $http_client,
    FileSystemInterface $file_system
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
  }

  /**
   * Maps all XML data to node fields in one place.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to update.
   * @param \SimpleXMLElement $xml_element
   *   The XML element containing the data.
   * @param string $source_url
   *   The source URL (used for fixing relative URLs).
   */
  public function mapAllFields(Node $node, \SimpleXMLElement $xml_element, $source_url) {
    $type = $node->bundle();

    // Common fields for all types.
    $this->mapTitle($node, $xml_element);
    $this->mapBody($node, $xml_element, $source_url);
    $this->mapExternalLink($node, $xml_element);

    // Type-specific mappings.
    switch ($type) {
      case 'event':
        $this->mapEventFields($node, $xml_element);
        break;

      case 'article':
        $this->mapArticleFields($node, $xml_element, $source_url);
        break;

      case 'person':
        $this->mapFacultyFields($node, $xml_element, $source_url);
        break;

      case 'book':
        $this->mapBookFields($node, $xml_element, $source_url);
        break;
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

  $image_url = $this->extractUrl((string) $xml->headshot);
  if ($image_url === '') {
    return;
  }

  $url_hash = substr(md5($image_url), 0, 8);

  try {
    $current_media = $node->get('field_image')->entity;

    // Dedup via remembered source URL stored in the media name suffix.
    // Format: "{title} headshot #{url_hash}". Filename comparison is too
    // fragile because filename sanitization differs from the hash token.
    if ($current_media && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
      $this->logger->debug('Headshot unchanged for node @nid, skipping.', [
        '@nid' => $node->id(),
      ]);
      return;
    }

    if ($current_media) {
      $media = $this->updateMediaImage($current_media, $image_url, $url_hash, $node->getTitle(), 'headshot');
      if ($media) {
        $this->logger->info('Updated headshot media @mid for node @nid from @url.', [
          '@mid' => $media->id(),
          '@nid' => $node->id(),
          '@url' => $image_url,
        ]);
      }
      return;
    }

    $media = $this->createMediaImage($image_url, $url_hash, $node->getTitle(), 'headshot');
    if ($media) {
      $node->set('field_image', ['target_id' => $media->id()]);
      $this->logger->info('Created headshot media @mid for node @nid from @url.', [
        '@mid' => $media->id(),
        '@nid' => $node->id(),
        '@url' => $image_url,
      ]);
    }
  }
  catch (\Exception $e) {
    $this->logger->error('Error processing headshot for node @nid: @message', [
      '@nid' => $node->id(),
      '@message' => $e->getMessage(),
    ]);
  }
}

/**
 * Returns TRUE if the media entity's name already carries this url_hash.
 *
 * Media name format for downloaded source media is
 * "{title} {type} #{url_hash}". Testing the name rather than the file's
 * filename works reliably regardless of how saveData() sanitized the
 * resulting on-disk filename, which is where the previous dedup broke.
 */
protected function mediaMatchesUrlHash(Media $media, $url_hash) {
  $name = (string) $media->get('name')->value;
  return $name !== '' && str_ends_with($name, '#' . $url_hash);
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
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover'). Used for directory and naming.
 *
 * @return \Drupal\media\Entity\Media|null
 *   The created media entity, or NULL on failure.
 */
protected function createMediaImage($image_url, $url_hash, $title, $type = 'headshot') {
  $image_data = $this->downloadImage($image_url);
  if (!$image_data) {
    $this->logger->warning('Failed to download image from @url.', [
      '@url' => $image_url,
    ]);
    return NULL;
  }

  $file = $this->createFileEntity($image_url, $url_hash, $image_data, $type);
  if (!$file) {
    return NULL;
  }

  // Name ends in '#{url_hash}' so mediaMatchesUrlHash() can dedup on later runs.
  $media = Media::create([
    'bundle' => 'image',
    'name' => $title . ' ' . $type . ' #' . $url_hash,
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
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover'). Used for directory and naming.
 *
 * @return \Drupal\media\Entity\Media|null
 *   The updated media entity, or NULL on failure.
 */
protected function updateMediaImage(Media $media, $image_url, $url_hash, $title, $type = 'headshot') {
  $image_data = $this->downloadImage($image_url);
  if (!$image_data) {
    $this->logger->warning('Failed to download image from @url.', [
      '@url' => $image_url,
    ]);
    return NULL;
  }

  // Get the old file for cleanup.
  $old_file = $media->get('field_media_image')->entity;

  // Create new file entity.
  $file = $this->createFileEntity($image_url, $url_hash, $image_data, $type);
  if (!$file) {
    return NULL;
  }

  // Update media entity. Embed url_hash in the name so future runs dedup.
  $media->set('field_media_image', [
    'target_id' => $file->id(),
    'alt' => $title,
  ]);
  $media->set('name', $title . ' ' . $type . ' #' . $url_hash);
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
 * @param string $type
 *   The type of image (e.g., 'headshot', 'cover'). Used for subdirectory.
 *
 * @return \Drupal\file\Entity\File|null
 *   The created file entity, or NULL on failure.
 */
protected function createFileEntity($image_url, $url_hash, $image_data, $type = 'headshot') {
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
  $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

  $uri = $directory . '/' . $filename;

  try {
    // Save the file data.
    $uri = $this->fileSystem->saveData($image_data, $uri, FileSystemInterface::EXISTS_REPLACE);

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
    $this->logger->error('Failed to save file @filename: @message', [
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
    $response = $this->httpClient->get($url, [
      'timeout' => 30,
      'http_errors' => FALSE,
    ]);

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('HTTP @status fetching image @url.', [
        '@status' => $response->getStatusCode(),
        '@url' => $url,
      ]);
      return NULL;
    }

    $content_type = $response->getHeaderLine('Content-Type');
    if (!empty($content_type) && strpos($content_type, 'image/') === FALSE) {
      $this->logger->warning('Non-image content type @type for @url.', [
        '@type' => $content_type,
        '@url' => $url,
      ]);
      return NULL;
    }

    return $response->getBody()->getContents();
  }
  catch (\Exception $e) {
    $this->logger->error('Error downloading image from @url: @message', [
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

  // Remove our usage first (the old media reference).
  // The file_usage table should update when media saves, but we check anyway.
  if (empty($usage) || $this->isFileOnlyUsedOnce($usage)) {
    try {
      $file->delete();
      $this->logger->debug('Deleted orphaned file @fid.', [
        '@fid' => $file->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not delete orphaned file @fid: @message', [
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
   * Maps title field.
   */
  protected function mapTitle(Node $node, \SimpleXMLElement $xml) {
    if (isset($xml->title)) {
      $node->setTitle((string) $xml->title);
    }
  }

  /**
   * Maps body field with URL fixes for shared content.
   */
  protected function mapBody(Node $node, \SimpleXMLElement $xml, $source_url) {
    if ($node->bundle() === 'person') {
      return;
    }
    if (!isset($xml->description)) {
      return;
    }

    $body = (string) $xml->description;
    $host = parse_url($source_url, PHP_URL_HOST);

    // Fix relative URLs to absolute URLs.
    if ($host) {
      $body = preg_replace(
        '/(?<=[\",\',\,,\s])\/sites\/' . preg_quote($host, '/') . '\/files\//',
        'https://' . $host . '/sites/' . $host . '/files/',
        $body
      );
    }

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => $body,
        'format' => 'full_html',
      ]);
    }
  }

  /**
   * Maps external link field for events only.
   *
   * Articles previously routed externalURL here as well, but with the new
   * article mapping externalURL → field_article_source_link is handled
   * inside mapArticleFields() instead — the event_series_link field is
   * semantically wrong for articles. Person nodes have field_external_link
   * set in mapFacultyFields(); books don't write this field at all.
   */
  protected function mapExternalLink(Node $node, \SimpleXMLElement $xml) {
    if ($node->bundle() !== 'event') {
      return;
    }
    if ($node->hasField('field_event_series_link')) {
      if (isset($xml->externalURL) && !empty((string) $xml->externalURL)) {
        $node->set('field_event_series_link', ['uri' => (string) $xml->externalURL]);
      }
    }
  }

  /**
   * Maps event-specific fields.
   *
   * Source element to D11 field mapping (each guarded so a missing element
   * or absent field is a no-op):
   *   eventDateStart/eventDateEnd (fallback: eventDate split on ' to ')
   *                          -> field_event_when (smartdate; duration computed)
   *   introText              -> field_teaser (string_long)
   *   location +
   *   additionalLocationInformation
   *                          -> field_event_location (address: line1 + organization)
   *   eventContact           -> field_event_contact (email; first valid token)
   *   thumbnail              -> field_image (media:image)
   *   buttonText + buttonURL -> field_event_virtual (link)
   *   rsvpLink               -> field_rsvp_form (webform ref; machine name parsed
   *                             from the /form/{name} path, set only if that
   *                             webform exists locally)
   *
   * title -> title, description -> body, and externalURL ->
   * field_event_series_link are handled by mapTitle() / mapBody() /
   * mapExternalLink() in mapAllFields(), so they are not repeated here.
   *
   * Intentionally NOT mapped, matching the artsci_d10_event migration:
   *   - eventTBD: field_event_date_tbd does not exist on the D11 event
   *     bundle (dropped in the migration), so the old write was a dead
   *     no-op and has been removed.
   *   - eventGeolocation: field_event_geolocation is a geofield on D11 and
   *     the migration deliberately does not carry the source string into
   *     it. The venue (location) is what lands in field_event_location, so
   *     the old eventGeolocation -> field_event_location string write
   *     (a type mismatch against the address field) has been removed.
   *   - field_event_category: the feed emits no category element, so there
   *     is no source to map. The migration backfills it from D10 taxonomy.
   */
  protected function mapEventFields(Node $node, \SimpleXMLElement $xml) {
    // When (smartdate). Prefer the discrete start/end, fall back to
    // splitting eventDate on ' to '. Duration (minutes) is required for
    // smartdate to render correctly and was missing from the previous
    // bare value/end_value write.
    $start = isset($xml->eventDateStart) ? trim((string) $xml->eventDateStart) : '';
    $end = isset($xml->eventDateEnd) ? trim((string) $xml->eventDateEnd) : '';
    if ($start === '' && isset($xml->eventDate)) {
      $parts = explode(' to ', (string) $xml->eventDate);
      if (count($parts) === 2) {
        $start = trim($parts[0]);
        $end = trim($parts[1]);
      }
    }
    if ($start !== '') {
      $start_ts = strtotime($start) ?: NULL;
      $end_ts = $end !== '' ? (strtotime($end) ?: NULL) : NULL;
      $this->writeEventWhen($node, $start_ts, $end_ts);
    }

    // Teaser: the source introduction excerpt arrives as introText.
    if (isset($xml->introText) && $node->hasField('field_teaser')) {
      $teaser = trim((string) $xml->introText);
      if ($teaser !== '') {
        $node->set('field_teaser', $teaser);
      }
    }
    if (isset($xml->introText) && $node->hasField('body')) {
      $summary = trim(strip_tags((string) $xml->introText));
      $body_item = $node->get('body')->first();
      if ($summary !== '' && $body_item && (string) $body_item->value !== '') {
        $node->set('body', [
          'value' => $body_item->value,
          'format' => $body_item->format ?: 'full_html',
          'summary' => $summary,
        ]);
      }
    }
    if (isset($xml->eventGeolocation) && $node->hasField('field_event_geolocation')) {
      $eventGeolocation = trim((string) $xml->eventGeolocation);
      if ($eventGeolocation !== '') {
        $node->set('field_event_geolocation', $eventGeolocation);
      }
    }
    // Location (address).
    $this->mapEventLocation($node, $xml);

    // Contact (email). First valid token only; leave the field untouched
    // when none is present rather than storing an invalid value.
    if (isset($xml->eventContact) && $node->hasField('field_event_contact')) {
      $email = $this->extractEmail((string) $xml->eventContact);
      if ($email !== '') {
        $node->set('field_event_contact', $email);
      }
    }

    // Thumbnail -> field_image (media:image), via the generic image
    // pipeline shared with the article thumbnail.
    if (isset($xml->thumbnail) && $node->hasField('field_image')) {
      $image_url = $this->extractUrl((string) $xml->thumbnail);
      if ($image_url !== '') {
        $this->setImageMedia($node, $image_url, 'field_image', 'thumbnail');
      }
    }
    if (isset($xml->rsvpFormLink) && $node->hasField('field_rsvp_form_link')) {
      $rsvp = trim((string) $xml->rsvpFormLink);
      if ($rsvp !== '') {
        $node->set('field_rsvp_form_link', [
          'uri' => $rsvp,
          'title' => 'RSVP Link',
        ]);
      }
    }

    // Virtual / CTA link: buttonText + buttonURL -> field_event_virtual.
    if (isset($xml->buttonURL) && $node->hasField('field_event_virtual')) {
      $button_url = trim((string) $xml->buttonURL);
      if ($button_url !== '') {
        $node->set('field_event_virtual', [
          'uri' => $button_url,
          'title' => isset($xml->buttonText) ? trim((string) $xml->buttonText) : '',
        ]);
      }
    }

    // RSVP webform reference.
    $this->mapRsvpForm($node, $xml);
  }

  /**
   * Writes field_event_when as a smartdate value with computed duration.
   *
   * Timestamps come straight from strtotime (consistent with the rest of
   * the shared content date handling) and timezone is left empty so the
   * field's site-default timezone applies on display. The only addition
   * over the previous bare write is duration in minutes, which smartdate
   * needs to render the end time correctly.
   */
  protected function writeEventWhen(Node $node, $start_ts, $end_ts) {
    if (!$start_ts || !$node->hasField('field_event_when')) {
      return;
    }
    if (!$end_ts) {
      $end_ts = $start_ts;
    }
    $node->set('field_event_when', [
      'value' => $start_ts,
      'end_value' => $end_ts,
      'duration' => (int) round(($end_ts - $start_ts) / 60),
      'rrule' => NULL,
      'timezone' => '',
    ]);
  }

  /**
   * Maps the event location string(s) onto the address field.
   *
   * field_event_location is an address field. The free-text venue
   * (location) lands in address_line1 and the supplementary string
   * (additionalLocationInformation) in organization, with country US.
   * This mirrors the migration's parse_mailing_address mapping; the street
   * address the feed carries in eventGeolocation is intentionally not used
   * (it targets the geofield, which the migration does not populate).
   */
  protected function mapEventLocation(Node $node, \SimpleXMLElement $xml) {
    if (!$node->hasField('field_event_location')) {
      return;
    }
    $location = isset($xml->location) ? trim((string) $xml->location) : '';
    $additional = isset($xml->additionalLocationInformation)
      ? trim((string) $xml->additionalLocationInformation)
      : '';
    if ($location === '' && $additional === '') {
      return;
    }
    $address = ['country_code' => 'US'];
    if ($location !== '') {
      $address['address_line1'] = $location;
    }
    if ($additional !== '') {
      $address['organization'] = $additional;
    }
    $node->set('field_event_location', $address);
  }

  /**
   * Sets the RSVP webform reference from the feed's rsvpLink URL.
   *
   * field_rsvp_form is a webform entity reference, so it stores a webform
   * machine name in target_id, not a URL. The feed carries a full
   * submission URL like https://{source}/form/{machine_name}?...; we parse
   * the machine name out of the /form/{name} path and set the reference
   * only when a webform with that id exists on this site. A reference to a
   * missing webform would fail validation on save, so a missing local
   * webform is logged and skipped.
   */
  protected function mapRsvpForm(Node $node, \SimpleXMLElement $xml) {
    if (!isset($xml->rsvpLink) || !$node->hasField('field_rsvp_form')) {
      return;
    }
    $rsvp_url = $this->extractUrl((string) $xml->rsvpLink);
    if ($rsvp_url === '') {
      return;
    }
    $path = (string) parse_url($rsvp_url, PHP_URL_PATH);
    if (!preg_match('#/form/([a-z0-9_]+)#i', $path, $m)) {
      return;
    }
    $machine_name = $m[1];
    $webform = $this->entityTypeManager->getStorage('webform')->load($machine_name);
    if ($webform) {
      $node->set('field_rsvp_form', ['target_id' => $machine_name]);
    }
    else {
      $this->logger->debug('RSVP webform "@name" not found locally for node @nid; skipping field_rsvp_form.', [
        '@name' => $machine_name,
        '@nid' => $node->id() ?? 'new',
      ]);
    }
  }

  /**
   * Extracts the first valid email address from a free-text string.
   *
   * Handles values like "Name | a@b.edu" and ignores tokens that are not
   * valid addresses (e.g. unresolved "[site:mail]" placeholders), matching
   * the migration's extractEmailOrEmpty intent.
   */
  protected function extractEmail($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
      return '';
    }
    if (preg_match('/[^\s,;|<>"\']+@[^\s,;|<>"\']+\.[^\s,;|<>"\']+/', $raw, $m)) {
      $candidate = rtrim($m[0], '.');
      if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        return $candidate;
      }
    }
    return '';
  }

  /**
   * Maps article-specific fields.
   *
   * Field mapping (all are no-ops when the destination field does not
   * exist on the bundle):
   *   introText   → field_article_subhead
   *   summary     → field_link_excerpt
   *   author      → field_article_source_org
   *   externalURL → field_article_source_link
   *   headerImage → field_header_image (image media bundle)
   *   video       → field_header_image (remote_video media bundle, wins over headerImage)
   *   thumbnail   → field_image (image media bundle)
   *   authornid   → field_contact_reference (person node reference)
   *   postDate    → node created time
   *
   * title → title and description → body are handled by the common
   * mapTitle() / mapBody() helpers in mapAllFields().
   *
   * @param \Drupal\node\Entity\Node $node
   * @param \SimpleXMLElement $xml
   * @param string $source_url
   *   The feed URL, used to resolve authornid to a local person node.
   */
  protected function mapArticleFields(Node $node, \SimpleXMLElement $xml, $source_url = '') {
    // postDate → created time. Mirrors the action: on parse failure the
    // created time is reset to "now". Note: this can move the created
    // timestamp on every refresh of an article whose postDate is
    // unparseable, since each refresh effectively re-imports the value.
    if (isset($xml->postDate)) {
      $post_date = (string) $xml->postDate;
      $date_time = \DateTime::createFromFormat('n.j.y', $post_date);
      if ($date_time !== FALSE) {
        $node->setCreatedTime($date_time->getTimestamp());
      }
      else {
        $node->setCreatedTime(\Drupal::time()->getRequestTime());
        $this->logger->warning('Unparseable postDate "@date" on article node @nid; created time reset to now (matching action behavior).', [
          '@date' => $post_date,
          '@nid' => $node->id() ?? 'new',
        ]);
      }
    }

    // introText → field_article_subhead.
    if (isset($xml->introText) && $node->hasField('field_article_subhead')) {
      $node->set('field_article_subhead', (string) $xml->summary);
    }

    // introText → body summary. mapBody() (called earlier in mapAllFields)
    // has already set the body value; we layer the summary on top without
    // disturbing it. Tags are stripped to match the person body/summary
    // handling. Skipped when there is no body value, since a summary
    // without a body is meaningless.
    if (isset($xml->introText) && $node->hasField('body')) {
      $summary = trim(strip_tags((string) $xml->introText));
      $body_item = $node->get('body')->first();
      if ($summary !== '' && $body_item && (string) $body_item->value !== '') {
        $node->set('body', [
          'value' => $body_item->value,
          'format' => $body_item->format ?: 'full_html',
          'summary' => $summary,
        ]);
      }
    }

    // summary → field_link_excerpt.
    if (isset($xml->summary) && $node->hasField('field_link_excerpt')) {
      $node->set('field_link_excerpt', (string) $xml->summary);
    }

    // author → field_article_source_org.
    if (isset($xml->author) && $node->hasField('field_article_source_org')) {
      $node->set('field_article_source_org', (string) $xml->author);
    }

    // externalURL → field_article_source_link. The link's title is the
    // "Name of Publication" shown as the link text; the feed has no
    // dedicated title element, so prefer the source org/author it carries
    // and fall back to the article title so the link always has text.
    if (isset($xml->externalURL) && $node->hasField('field_article_source_link')) {
      $external_url = (string) $xml->externalURL;
      if (!empty($external_url)) {
        $link_title = isset($xml->externalURLText) ? trim((string) $xml->externalURLText) : '';
        if ($link_title === '') {
          $link_title = isset($xml->title) ? trim((string) $xml->title) : '';
        }
        $node->set('field_article_source_link', [
          'uri' => $external_url,
          'title' => $link_title,
        ]);
      }
    }

    // Header media → field_header_image. Video wins over a still header
    // image when both are present, because field_header_image is a media
    // reference that accepts either image or remote_video bundle and the
    // moving asset is the more meaningful headline.
    $this->mapArticleHeaderMedia($node, $xml);

    // thumbnail → field_image (image media bundle).
    $this->mapArticleThumbnail($node, $xml);

    // soundCloud → field_soundcloud_media (soundcloud media bundle).
    $this->mapArticleSoundcloud($node, $xml);

    // authornid → field_contact_reference (Associated person).
    $this->mapArticleContactReference($node, $xml, $source_url);
    // authornid → field_contact_reference (Associated person).
    $this->mapArticleAuthor($node, $xml, $source_url);
  }

  /**
   * Maps the article author (authornid) onto field_contact_reference.
   *
   * field_contact_reference ("Associated person") is a multi-value entity
   * reference to person nodes. The feed's <authornid> is the author's node
   * ID on the source site that served this feed, so we resolve the local
   * person the same way mapBookAuthor() resolves a book author: find the
   * local person whose field_shared_content_xml matches the source site's
   * /xml/faculty_staff/{authornid}/rss.xml URL.
   *
   * Unlike mapBookAuthor()/findLocalPersonByRemoteId(), the base host is
   * derived from $source_url (the feed URL), NOT the article's <externalURL>.
   * For articles externalURL points at the external publication, not the
   * source site, so it would resolve the person against the wrong host.
   *
   * Set-only, mirroring mapBookAuthor(): a no-op when the element or field is
   * absent, the id is non-positive, the source base can't be derived, or no
   * local person matches. An existing reference is left untouched in those
   * cases rather than cleared.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param \SimpleXMLElement $xml
   * @param string $source_url
   *   The feed URL stored in field_shared_content_xml.
   */
  protected function mapArticleContactReference(Node $node, \SimpleXMLElement $xml, $source_url) {
    if (!isset($xml->authornid) || !$node->hasField('field_contact_reference')) {
      return;
    }
    $author_nid = (int) $xml->authornid;
    if ($author_nid <= 0) {
      return;
    }

    $base_url = $this->deriveProductionBase($source_url);
    if ($base_url === NULL) {
      return;
    }

    $person_shared_url = $base_url . '/xml/faculty_staff/' . $author_nid . '/rss.xml';
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'person')
      ->condition('field_shared_content_xml', $person_shared_url)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      $this->logger->debug('Article author (remote nid @rnid) not found locally for node @nid; skipping field_contact_reference.', [
        '@rnid' => $author_nid,
        '@nid' => $node->id() ?? 'new',
      ]);
      return;
    }

    $node->set('field_contact_reference', [['target_id' => reset($nids)]]);
  }
  /**
   * Maps the article author (authornid) onto field_contact_reference.
   *
   * field_contact_reference ("Associated person") is a multi-value entity
   * reference to person nodes. The feed's <authornid> is the author's node
   * ID on the source site that served this feed, so we resolve the local
   * person the same way mapBookAuthor() resolves a book author: find the
   * local person whose field_shared_content_xml matches the source site's
   * /xml/faculty_staff/{authornid}/rss.xml URL.
   *
   * Unlike mapBookAuthor()/findLocalPersonByRemoteId(), the base host is
   * derived from $source_url (the feed URL), NOT the article's <externalURL>.
   * For articles externalURL points at the external publication, not the
   * source site, so it would resolve the person against the wrong host.
   *
   * Set-only, mirroring mapBookAuthor(): a no-op when the element or field is
   * absent, the id is non-positive, the source base can't be derived, or no
   * local person matches. An existing reference is left untouched in those
   * cases rather than cleared.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param \SimpleXMLElement $xml
   * @param string $source_url
   *   The feed URL stored in field_shared_content_xml.
   */
  protected function mapArticleAuthor(Node $node, \SimpleXMLElement $xml, $source_url) {
    if (!isset($xml->authornid) || !$node->hasField('field_article_author')) {
      return;
    }
    $author_nid = (int) $xml->authornid;
    if ($author_nid <= 0) {
      return;
    }

    $base_url = $this->deriveProductionBase($source_url);
    if ($base_url === NULL) {
      return;
    }

    $person_shared_url = $base_url . '/xml/faculty_staff/' . $author_nid . '/rss.xml';
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'person')
      ->condition('field_shared_content_xml', $person_shared_url)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      $this->logger->debug('Article author (remote nid @rnid) not found locally for node @nid; skipping field_contact_reference.', [
        '@rnid' => $author_nid,
        '@nid' => $node->id() ?? 'new',
      ]);
      return;
    }

    $node->set('field_article_author', [['target_id' => reset($nids)]]);
  }
  /**
   * Maps the article header media onto field_header_image.
   *
   * field_header_image is a media reference that accepts both image and
   * remote_video bundles. When the feed carries a video URL we route that
   * through setRemoteVideoMedia(); otherwise we fall back to the still
   * headerImage URL through setImageMedia(). When neither is present this
   * is a no-op and leaves any existing reference untouched.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to update.
   * @param \SimpleXMLElement $xml
   *   The XML element containing the data.
   */
  protected function mapArticleHeaderMedia(Node $node, \SimpleXMLElement $xml) {
    if (!$node->hasField('field_header_image')) {
      return;
    }

    // Video wins over still header image when both are present. The feed
    // element is <Video> (capital V) and carries a rendered <iframe> embed,
    // not a bare URL or anchor, so it needs the iframe-aware extractor
    // rather than the generic anchor-based extractUrl().
    if (isset($xml->Video)) {
      $video_url = $this->extractVideoUrl((string) $xml->Video);
      if ($video_url !== '') {
        // The <Video> blob also carries the editorial poster <img>; use it as
        // the remote_video thumbnail instead of the auto-fetched provider one.
        $poster_url = $this->extractVideoPosterUrl((string) $xml->Video);
        $this->setRemoteVideoMedia($node, $video_url, 'field_header_image', $poster_url);
        return;
      }
    }

    if (isset($xml->headerImage)) {
      $image_url = $this->extractUrl((string) $xml->headerImage);
      if ($image_url !== '') {
        $this->setImageMedia($node, $image_url, 'field_header_image', 'header');
      }
    }
  }

  /**
   * Maps the article thumbnail onto field_image.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to update.
   * @param \SimpleXMLElement $xml
   *   The XML element containing the data.
   */
  protected function mapArticleThumbnail(Node $node, \SimpleXMLElement $xml) {
    if (!isset($xml->thumbnail) || !$node->hasField('field_image')) {
      return;
    }
    $image_url = $this->extractUrl((string) $xml->thumbnail);
    if ($image_url === '') {
      return;
    }
    $this->setImageMedia($node, $image_url, 'field_image', 'thumbnail');
  }

  /**
   * Creates or updates an image media entity on a node field.
   *
   * Generic counterpart to the bundle-specific mapHeadshot() and
   * mapBookCover() — caller supplies the destination field, image type
   * label (used for naming and on-disk subdirectory), and the source URL.
   * Dedup uses the same url_hash suffix scheme as the bundle-specific
   * helpers so all image fields share the same caching semantics.
   *
   * If the field currently holds a non-image media (e.g. a remote_video,
   * because last refresh routed a video here and this refresh has only a
   * still image), the existing reference is replaced rather than mutated
   * in place — we don't try to convert media bundles.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param string $image_url
   * @param string $field_name
   * @param string $type
   *   Image type label (e.g. 'header', 'thumbnail'); drives subdirectory
   *   and media name suffix.
   */
  protected function setImageMedia(Node $node, $image_url, $field_name, $type) {
    $url_hash = substr(md5($image_url), 0, 8);

    try {
      $current_media = $node->get($field_name)->entity;

      // Existing image media with the same url_hash — nothing to do.
      if ($current_media && $current_media->bundle() === 'image'
        && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
        $this->logger->debug('@type unchanged for node @nid, skipping.', [
          '@type' => $type,
          '@nid' => $node->id() ?? 'new',
        ]);
        return;
      }

      // Mutate the existing media in place when it's already an image.
      // Wrong bundle (e.g. remote_video held the field last time) means
      // we drop the reference and create a fresh image media instead.
      if ($current_media && $current_media->bundle() === 'image') {
        $media = $this->updateMediaImage($current_media, $image_url, $url_hash, $node->getTitle(), $type);
        if ($media) {
          $this->logger->info('Updated @type media @mid for node @nid from @url.', [
            '@type' => $type,
            '@mid' => $media->id(),
            '@nid' => $node->id() ?? 'new',
            '@url' => $image_url,
          ]);
        }
        return;
      }

      $media = $this->createMediaImage($image_url, $url_hash, $node->getTitle(), $type);
      if ($media) {
        $node->set($field_name, ['target_id' => $media->id()]);
        $this->logger->info('Created @type media @mid for node @nid from @url.', [
          '@type' => $type,
          '@mid' => $media->id(),
          '@nid' => $node->id() ?? 'new',
          '@url' => $image_url,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing @type media for node @nid: @message', [
        '@type' => $type,
        '@nid' => $node->id() ?? 'new',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Creates or reuses a remote_video media entity referenced from a field.
   *
   * remote_video is a core Media bundle whose canonical field is
   * field_media_oembed_video — that's where the YouTube/Vimeo URL lives.
   * We dedup across the site rather than per node: if a remote_video
   * media for this URL already exists anywhere, reuse it. This keeps the
   * media library clean instead of accumulating duplicates each time the
   * same video appears on multiple articles.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param string $video_url
   * @param string $field_name
   * @param string $poster_url
   *   Optional editorial poster image URL from the feed. When the media is
   *   newly created, this overrides the auto-fetched provider thumbnail.
   */
  protected function setRemoteVideoMedia(Node $node, $video_url, $field_name, $poster_url = '') {
    if (!$node->hasField($field_name)) {
      return;
    }

    try {
      $current_media = $node->get($field_name)->entity;

      // Resolve the target media. When the field already references the
      // correct video we keep it (and skip the re-point and log below);
      // otherwise we reuse a site-wide media for this URL, or create one.
      if ($current_media
        && $current_media->bundle() === 'remote_video'
        && $current_media->hasField('field_media_oembed_video')
        && (string) $current_media->get('field_media_oembed_video')->value === $video_url) {
        $media = $current_media;
      }
      else {
        // Site-wide reuse: same URL → same media entity, so multiple
        // articles referencing the same video share one media row.
        $existing = $this->entityTypeManager->getStorage('media')->loadByProperties([
          'bundle' => 'remote_video',
          'field_media_oembed_video' => $video_url,
        ]);

        if (!empty($existing)) {
          $media = reset($existing);
        }
        else {
          $media = Media::create([
            'bundle' => 'remote_video',
            'uid' => \Drupal::currentUser()->id(),
            'status' => 1,
            'field_media_oembed_video' => $video_url,
          ]);
          $media->save();
        }

        $node->set($field_name, ['target_id' => $media->id()]);
        $this->logger->info('Set remote_video media @mid for node @nid from @url.', [
          '@mid' => $media->id(),
          '@nid' => $node->id() ?? 'new',
          '@url' => $video_url,
        ]);
      }

      // Apply the editorial poster to field_video_thumbnail on every path,
      // including when the video media already existed (force refresh) or was
      // reused from the migration or another article. applyVideoPoster() is
      // idempotent: it no-ops when field_video_thumbnail already references
      // this poster.
      if ($poster_url !== '') {
        $this->applyVideoPoster($media, $poster_url);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error setting remote_video for node @nid: @message', [
        '@nid' => $node->id() ?? 'new',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Extracts the editorial poster image URL from the source <Video> blob.
   *
   * The <Video> section embeds an <img> (the poster shown before play) with a
   * srcset of absolute, styled derivatives and a root-relative src fallback.
   * We prefer the highest-resolution srcset candidate; failing that we
   * reconstruct an absolute URL from the src's /sites/{host}/files/ segment.
   * Returns '' when no poster is present.
   *
   * @param string $html
   *   The raw <Video> blob.
   *
   * @return string
   *   An absolute poster image URL, or '' when none is found.
   */
  protected function extractVideoPosterUrl($html) {
    $html = (string) $html;
    if (trim($html) === '') {
      return '';
    }
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    if (!preg_match('/<img\b[^>]*>/i', $decoded, $img_m)) {
      return '';
    }
    $img_tag = $img_m[0];

    // Prefer the highest-resolution srcset candidate (absolute URLs).
    if (preg_match('/\bsrcset=["\']([^"\']+)["\']/i', $img_tag, $ss)) {
      $best_url = '';
      $best_w = -1;
      foreach (explode(',', $ss[1]) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
          continue;
        }
        $parts = preg_split('/\s+/', $candidate);
        $w = (isset($parts[1]) && preg_match('/^(\d+)w$/', $parts[1], $wm)) ? (int) $wm[1] : 0;
        if ($w >= $best_w) {
          $best_w = $w;
          $best_url = $parts[0];
        }
      }
      $abs = $this->absolutizePosterUrl($best_url);
      if ($abs !== '') {
        return $abs;
      }
    }

    // Fallback: the src attribute (often root-relative).
    if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $img_tag, $sm)) {
      return $this->absolutizePosterUrl(trim($sm[1]));
    }

    return '';
  }

  /**
   * Resolves a poster image URL to an absolute form.
   *
   * Absolute and protocol-relative URLs pass through. Root-relative Drupal
   * file paths carry the originating host inside their /sites/{host}/files/
   * segment, so we reconstruct an absolute URL from that. Anything else
   * yields '' rather than a value the downloader cannot use.
   *
   * @param string $url
   *
   * @return string
   */
  protected function absolutizePosterUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $url)) {
      return $url;
    }
    if (strpos($url, '//') === 0) {
      return 'https:' . $url;
    }
    if (preg_match('#/sites/([^/]+)/files/#', $url, $hm)) {
      return 'https://' . $hm[1] . $url;
    }
    return '';
  }

  /**
   * Applies a feed poster image as a remote_video media's custom thumbnail.
   *
   * remote_video media auto-fetch the provider (YouTube/Vimeo) thumbnail into
   * the base thumbnail field, and the oembed_thumbnail formatter only uses
   * that as a YouTube onerror fallback. The editorial poster must instead go
   * into field_video_thumbnail — a reference to an image media — which the
   * formatter shows as the primary thumbnail. This mirrors what the
   * artsci_d10_remote_video_thumbnail migration populates, so feed-sourced
   * and migrated videos display the same way. The poster is wrapped in an
   * image media (downloaded to public://shared_content/video_posters) and
   * referenced; the base thumbnail is left as the provider image. Dedup is by
   * the url_hash embedded in the referenced image media's name, so repeated
   * refreshes are no-ops once the poster is in place.
   *
   * @param \Drupal\media\Entity\Media $media
   * @param string $poster_url
   */
  protected function applyVideoPoster(Media $media, $poster_url) {
    if (!$media->hasField('field_video_thumbnail')) {
      return;
    }
    $url_hash = substr(md5($poster_url), 0, 8);
    try {
      $storage = $this->entityTypeManager->getStorage('media');

      // If field_video_thumbnail already references an image media for this
      // exact poster, nothing to do. Resolve through target_id + load()
      // rather than ->entity, which can return NULL for a freshly referenced
      // media in some load contexts and would defeat this dedup.
      $current_id = $media->get('field_video_thumbnail')->target_id;
      if ($current_id) {
        $current = $storage->load($current_id);
        if ($current && $this->mediaMatchesUrlHash($current, $url_hash)) {
          return;
        }
      }

      // Reuse an existing poster image media for this hash site-wide rather
      // than creating a duplicate on every refresh. Dedup on the name's
      // '#{url_hash}' suffix, matching createMediaImage()'s naming.
      $found = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', 'image')
        ->condition('name', '%#' . $url_hash, 'LIKE')
        ->sort('mid')
        ->range(0, 1)
        ->execute();
      $image_media = !empty($found)
        ? $storage->load(reset($found))
        : $this->createMediaImage($poster_url, $url_hash, $media->label(), 'video_poster');
      if (!$image_media) {
        return;
      }

      // Only write + save when the reference actually changes, so repeated
      // refreshes of an already-correct media are true no-ops.
      if ((string) $current_id !== (string) $image_media->id()) {
        $media->set('field_video_thumbnail', ['target_id' => $image_media->id()]);
        $media->save();
        $this->logger->info('Applied feed poster thumbnail media @img to remote_video media @mid.', [
          '@img' => $image_media->id(),
          '@mid' => $media->id(),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error applying poster to remote_video media @mid: @message', [
        '@mid' => $media->id() ?? 'new',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Maps the article SoundCloud embed onto field_soundcloud_media.
   *
   * The feed element is <soundCloud> (capital C) and carries a rendered
   * SoundCloud player <iframe>; the real track URL lives in the player's
   * url= query parameter. We extract that and route it through
   * setSoundcloudMedia(). No-op when the element or field is absent.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param \SimpleXMLElement $xml
   */
  protected function mapArticleSoundcloud(Node $node, \SimpleXMLElement $xml) {
    if (!isset($xml->soundCloud) || !$node->hasField('field_soundcloud_media')) {
      return;
    }
    $sc_url = $this->extractSoundcloudUrl((string) $xml->soundCloud);
    if ($sc_url === '') {
      return;
    }
    $this->setSoundcloudMedia($node, $sc_url, 'field_soundcloud_media');
  }

  /**
   * Creates or reuses a soundcloud media entity referenced from a field.
   *
   * Mirrors setRemoteVideoMedia(): soundcloud is a Media bundle whose
   * canonical field is field_media_soundcloud. We dedup site-wide so the
   * same track shared across articles reuses one media row rather than
   * piling up duplicates.
   *
   * @param \Drupal\node\Entity\Node $node
   * @param string $sc_url
   * @param string $field_name
   */
  protected function setSoundcloudMedia(Node $node, $sc_url, $field_name) {
    if (!$node->hasField($field_name)) {
      return;
    }

    try {
      $current_media = $node->get($field_name)->entity;

      // Already pointing at this exact track — nothing to do.
      if ($current_media
        && $current_media->bundle() === 'soundcloud'
        && $current_media->hasField('field_media_soundcloud')
        && (string) $current_media->get('field_media_soundcloud')->value === $sc_url) {
        return;
      }

      // Site-wide reuse: same URL → same media entity.
      $existing = $this->entityTypeManager->getStorage('media')->loadByProperties([
        'bundle' => 'soundcloud',
        'field_media_soundcloud' => $sc_url,
      ]);

      if (!empty($existing)) {
        $media = reset($existing);
      }
      else {
        $media = Media::create([
          'bundle' => 'soundcloud',
          'uid' => \Drupal::currentUser()->id(),
          'status' => 1,
          'field_media_soundcloud' => $sc_url,
        ]);
        $media->save();
      }

      $node->set($field_name, ['target_id' => $media->id()]);
      $this->logger->info('Set soundcloud media @mid for node @nid from @url.', [
        '@mid' => $media->id(),
        '@nid' => $node->id() ?? 'new',
        '@url' => $sc_url,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error setting soundcloud for node @nid: @message', [
        '@nid' => $node->id() ?? 'new',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Extracts a provider watch URL from the source <Video> HTML blob.
   *
   * <Video> is a rendered HTML section containing an <iframe> embed. The
   * remote_video oEmbed source only accepts provider canonical URLs, so
   * YouTube embed/short forms are normalized to a watch URL and Vimeo
   * player URLs to the canonical vimeo.com form. Anything else (an empty
   * blob, or one carrying only the poster background image) yields '' so
   * the caller falls back to the still header image.
   *
   * @param string $html
   *   The raw <Video> blob.
   *
   * @return string
   *   A canonical video URL, or '' when none is present.
   */
  protected function extractVideoUrl($html) {
    $html = (string) $html;
    if (trim($html) === '') {
      return '';
    }
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    // Prefer the iframe src; fall back to scanning the whole blob.
    $src = $html;
    if (preg_match('/<iframe[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) {
      $src = $m[1];
    }

    // YouTube embed/watch/short forms -> canonical watch URL.
    if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:embed|v)/|youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_\-]{6,})#i', $src, $m)) {
      return 'https://www.youtube.com/watch?v=' . $m[1];
    }
    // Vimeo player/video -> canonical vimeo URL.
    if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $src, $m)) {
      return 'https://vimeo.com/' . $m[1];
    }

    return '';
  }

  /**
   * Extracts a SoundCloud track/playlist URL from the source <soundCloud> blob.
   *
   * The feed stores the player as an <iframe> whose real track URL lives in
   * the player's url= query parameter (e.g.
   * https://api.soundcloud.com/tracks/316458048). Falls back to a bare
   * soundcloud URL, and returns '' when nothing usable is present.
   *
   * @param string $value
   *   The raw <soundCloud> blob.
   *
   * @return string
   *   A SoundCloud URL, or '' when none can be extracted.
   */
  protected function extractSoundcloudUrl($value) {
    $value = (string) $value;
    if (trim($value) === '') {
      return '';
    }
    $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

    $src = $text;
    if (preg_match('/src=["\']([^"\']+)["\']/i', $text, $m)) {
      $src = $m[1];
    }

    $query = parse_url($src, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
      parse_str($query, $params);
      if (!empty($params['url'])) {
        return (string) $params['url'];
      }
    }

    if (preg_match('#https?://(?:api\.|w\.|on\.)?soundcloud\.com/[^\s"\'<>]+#i', $src, $m2)) {
      return $m2[0];
    }

    return '';
  }

/**
 * Maps faculty/staff-specific fields.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 * @param string $source_url
 *   The source URL (used for fixing relative URLs).
 */
protected function mapFacultyFields(Node $node, \SimpleXMLElement $xml, $source_url) {
  // First name and last name - also update title.
  $firstName = isset($xml->firstName) ? (string) $xml->firstName : '';
  $lastName = isset($xml->lastName) ? (string) $xml->lastName : '';

  if (!empty($firstName) || !empty($lastName)) {
    if ($node->hasField('field_person_first_name')) {
      $node->set('field_person_first_name', $firstName);
    }
    if ($node->hasField('field_person_last_name')) {
      $node->set('field_person_last_name', $lastName);
    }
    $node->setTitle(trim("$firstName $lastName"));
  }
  elseif (isset($xml->title)) {
    $node->setTitle((string) $xml->title);
  }

  // External link. Mirrors the action: raw URL stored, no HTML extraction,
  // requires non-empty value.
  if ($node->hasField('field_external_link')) {
    if (isset($xml->externalURL) && !empty((string) $xml->externalURL)) {
      $node->set('field_external_link', ['uri' => (string) $xml->externalURL]);
    }
  }

  // Combine position and additional titles.
  $position = isset($xml->position) ? trim((string) $xml->position) : '';
  $additional_titles = isset($xml->additionalTitles) ? trim((string) $xml->additionalTitles) : '';

  $all_positions = [];
  if (!empty($position)) {
    $all_positions[] = $position;
  }
  if (!empty($additional_titles)) {
    $additional_items = array_filter(
      array_map('trim', preg_split('/\s*<br\s*\/?>\s*/i', $additional_titles))
    );
    $all_positions = array_merge($all_positions, $additional_items);
  }

  if (!empty($all_positions) && $node->hasField('field_person_position')) {
    $node->set('field_person_position', array_map(
      fn($item) => ['value' => $item],
      $all_positions
    ));
  }

  // Scalar person fields. Mirrors the action: written unconditionally
  // when the field exists, so an empty/absent XML value clears the local
  // field. field_external_url stores the raw officeDirectionsURL without
  // HTML extraction.
  if ($node->hasField('field_person_email')) {
    $node->set('field_person_email', (string) $xml->emailAddress);
  }
  if ($node->hasField('field_person_phone')) {
    $node->set('field_person_phone', (string) $xml->phoneNumber);
  }
  if ($node->hasField('field_person_fax')) {
    $node->set('field_person_fax', (string) $xml->faxNumber);
  }
  if ($node->hasField('field_external_url')) {
    $node->set('field_external_url', (string) $xml->officeDirectionsURL);
  }
  if ($node->hasField('field_person_credential')) {
    $node->set('field_person_credential', (string) $xml->pronouns);
  }
  $this->mapHeadshot($node, $xml);

  // Full HTML fields.
  if (isset($xml->buildingNameRoomNumber) && $node->hasField('field_building_name_and_room_num')) {
    $node->set('field_building_name_and_room_num', [
      'value' => (string) $xml->buildingNameRoomNumber,
      'format' => 'full_html',
    ]);
  }
  if (isset($xml->officeHours) && $node->hasField('field_office_hours')) {
    $node->set('field_office_hours', [
      'value' => (string) $xml->officeHours,
      'format' => 'full_html',
    ]);
  }
// Body, with introText routed into the body summary.
//
// introText (the link excerpt) was previously stripped of tags and
// stored in field_teaser. We now treat it as the body's summary so the
// teaser-style excerpt lives alongside the long-form bio in a single
// text_with_summary field. This matches the migration's
// `'body/summary': link_excerpt` mapping.
//
// If both biography and introText are absent there's nothing to write;
// if biography is absent but introText is present we still skip — body
// summary without a body value is meaningless.
if (isset($xml->biography) && $node->hasField('body')) {
  $body = (string) $xml->biography;

  // Fix relative URLs to absolute URLs (same logic as mapBody).
  $host = parse_url($source_url, PHP_URL_HOST);
  if ($host) {
    $body = preg_replace(
      '/(?<=[\",\',\,,\s])\/sites\/' . preg_quote($host, '/') . '\/files\//',
      'https://' . $host . '/sites/' . $host . '/files/',
      $body
    );
  }

  $body_value = [
    'value' => $body,
    'format' => 'full_html',
  ];

  // Body summary sourced from introText. Tags are stripped to preserve
  // the previous field_teaser treatment of this same data.
  if (isset($xml->introText)) {
    $intro_text = trim(strip_tags((string) $xml->introText));
    if ($intro_text !== '') {
      $body_value['summary'] = $intro_text;
    }
  }

  $node->set('body', $body_value);
}
  if (isset($xml->description) && $node->hasField('field_person_description')) {
    $node->set('field_person_description', [
      'value' => (string) $xml->description,
      'format' => 'full_html',
    ]);
  }

  // NOTE: introText is no longer written to field_teaser. It now feeds
  // body/summary above. See the body block for details.

  // Interests - convert br-separated list to HTML ul/li.
  if (isset($xml->interests) && $node->hasField('field_person_interests')) {
    $interests = (string) $xml->interests;
    if (!empty($interests)) {
      $node->set('field_person_interests', [
          'value' => $interests,
          'format' => 'full_html',
        ]);
    }
  }

  // Education - multivalue field from br-separated list.
  if (isset($xml->education) && $node->hasField('field_person_education')) {
    $education = (string) $xml->education;
    if (!empty($education)) {
      $education_items = array_filter(
        array_map('trim', preg_split('/\s*<br\s*\/?>\s*/i', $education))
      );
      $node->set('field_person_education', array_map(
        fn($item) => ['value' => $item],
        $education_items
      ));
    }
  }

  // Department - add to research areas taxonomy.
  // if (isset($xml->department) && $node->hasField('field_person_research_areas')) {
  //   $term = $this->getOrCreateTerm((string) $xml->department, 'research_areas');
  //   $this->addTermToField($node, 'field_person_research_areas', $term);
  // }

  // Department - also reference on field_person_department against the
  // 'department' vocabulary. Mirrors the import action: getOrCreateTerm
  // selects an existing matching term or creates a new one, then
  // addTermToField adds it without duplicating an already-attached value.
  if (isset($xml->department) && $node->hasField('field_person_department')) {
    $department_term = $this->getOrCreateTerm((string) $xml->department, 'department');
    $this->addTermToField($node, 'field_person_department', $department_term);
  }

  // Links - parse anchor tags into link field values.
  if (isset($xml->links) && $node->hasField('field_person_website')) {
    $links_raw = (string) $xml->links;
    if (!empty($links_raw)) {
      if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $links_raw, $matches, PREG_SET_ORDER)) {
        $link_values = [];
        foreach ($matches as $match) {
          $href = trim($match[1]);
          $title = trim(strip_tags($match[2]));

          // Normalize the href into a valid link-field URI. Root-relative
          // paths (including a bare "/") resolve against the source site's
          // production base; values that cannot be resolved are skipped so
          // we never store a schemeless URI (which makes Url::fromUri()
          // throw and trips entity_usage tracking on save).
          $uri = $this->normalizeLinkUri($href, $source_url);
          if ($uri !== NULL) {
            $link_values[] = [
              'uri' => $uri,
              'title' => $title,
            ];
          }
        }
        if (!empty($link_values)) {
          $node->set('field_person_website', $link_values);
        }
      }
    }
  }

  // Additional entity references (CV media, person types, social media).
  // See each method for caveats about speculative XML element names.
  $this->mapCv($node, $xml);
  $this->mapPersonTypes($node, $xml);
  $this->mapSocialMediaParagraph($node, $xml, $source_url);

  // Contact paragraph with address.
  $this->mapContactParagraph($node, $xml);
}

/**
 * Normalizes a raw anchor href into a valid link-field URI.
 *
 * Source anchors may contain absolute URLs, protocol-relative URLs, or
 * root-relative paths (including a bare "/"). A schemeless value such as
 * "/" or "/about" is invalid for a link field: Url::fromUri() throws on it,
 * which trips entity_usage tracking when the node is saved. Root-relative
 * paths are resolved against the originating site's production base (derived
 * from $source_url, e.g. https://anthropology.washu.edu) so the link points
 * at the source department site rather than the shared destination host.
 *
 * @param string $href
 *   The raw href value from the source anchor tag.
 * @param string $source_url
 *   The feed URL stored in field_shared_content_xml, of the form
 *   {base_url}/xml/{type}/{orig_nid}/rss.xml.
 *
 * @return string|null
 *   A valid link-field URI, or NULL if the href should be skipped.
 */
protected function normalizeLinkUri(string $href, string $source_url): ?string {
  $href = trim($href);

  // Empty values and in-page anchors carry no usable destination.
  if ($href === '' || $href[0] === '#') {
    return NULL;
  }

  // Already absolute (http:, https:, mailto:, tel:, ...). Keep as-is.
  if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
    return $href;
  }

  // Protocol-relative //host/path. Assume https.
  if (strpos($href, '//') === 0) {
    return 'https:' . $href;
  }

  // Root-relative path, including a bare "/". Resolve against the source
  // site's production base so the link targets the originating site.
  if ($href[0] === '/') {
    $base = $this->deriveProductionBase($source_url);
    if ($base !== NULL) {
      // A bare "/" maps to the site home; "/path" appends to the base.
      return $href === '/' ? $base : $base . $href;
    }
    // No base could be derived. Store a sub-path as an internal URI so the
    // value is at least valid; a lone "/" has no internal target, so skip.
    return $href === '/' ? NULL : 'internal:' . $href;
  }

  // Any other relative form (e.g. "about", "../x") is ambiguous cross-site
  // and cannot be resolved reliably; skip rather than store an invalid URI.
  return NULL;
}

/**
 * Derives the production base (scheme://host) from a source feed URL.
 *
 * @param string $source_url
 *   The feed URL stored in field_shared_content_xml.
 *
 * @return string|null
 *   The base such as "https://anthropology.washu.edu", or NULL if scheme
 *   and host cannot both be parsed from $source_url.
 */
protected function deriveProductionBase(string $source_url): ?string {
  $parsed = parse_url($source_url);
  if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
    return $parsed['scheme'] . '://' . $parsed['host'];
  }
  return NULL;
}

/**
 * Maps contact paragraph for person nodes.
 *
 * Paragraph revision state must stay synced with the host node's revisions.
 * We therefore bind the paragraph to its parent node and field before saving
 * and explicitly flag it as a new default revision. Without both of those,
 * paragraphs_item rows can end up with NULL parent_id/parent_field_name and
 * revision_default flags that drift out of sync with the node, which later
 * surfaces as "An existing default revision of the 'paragraph' entity type
 * can not be changed to a non-default revision" whenever someone edits the
 * person node through IEF.
 */
protected function mapContactParagraph(Node $node, \SimpleXMLElement $xml) {
  if (!isset($xml->mailingAddress) || !$node->hasField('field_person_contact_information')) {
    return;
  }

  $address_raw = trim((string) $xml->mailingAddress);
  if (empty($address_raw)) {
    return;
  }

  // Check if paragraph already exists.
  $existing_paragraph = $node->get('field_person_contact_information')->entity;

  if ($existing_paragraph) {
    $paragraph = $existing_paragraph;
  }
  else {
    $paragraph = Paragraph::create(['type' => 'artsci_contact']);
  }

  // Parse address with line breaks into structured components.
  $address_lines = array_filter(
    array_map('trim', preg_split('/\s*<br\s*\/?>\s*/i', $address_raw))
  );

  if (!empty($address_lines)) {
    $address_data = ['country_code' => 'US'];

    // Last line should be "City, ST ZIP".
    $last_line = array_pop($address_lines);
    if (preg_match('/^(.+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/', $last_line, $address_matches)) {
      $address_data['locality'] = trim($address_matches[1]);
      $address_data['administrative_area'] = $address_matches[2];
      $address_data['postal_code'] = $address_matches[3];
    }
    else {
      $address_lines[] = $last_line;
    }

    if (count($address_lines) >= 1) {
      $address_data['address_line1'] = array_shift($address_lines);
    }
    if (count($address_lines) >= 1) {
      $address_data['address_line2'] = implode(', ', $address_lines);
    }

    $paragraph->set('field_artsci_contact_address', $address_data);
  }

  // Bind to host and force correct revision state before saving. If the node
  // is new, parent_id will be NULL here and the ERR field will populate it
  // during the node's save pass — parent_type and parent_field_name still
  // need to be set now so the paragraphs module's hooks route correctly.
  $paragraph->setParentEntity($node, 'field_person_contact_information');
  $paragraph->setNewRevision(TRUE);
  $paragraph->isDefaultRevision(TRUE);
  $paragraph->save();

  $node->set('field_person_contact_information', [
    'target_id' => $paragraph->id(),
    'target_revision_id' => $paragraph->getRevisionId(),
  ]);
}

/**
 * Maps person type(s) to field_person_types.
 *
 * field_person_types is an entity_reference to artsci_people.person_type
 * config entities. Valid machine names:
 *   author, college_office, college_registrar, dean_of_arts_sciences_office,
 *   faculty, leadership, pre_graduate_advisors, pre_health_advisors,
 *   pre_law_advisors, staff.
 *
 * The migration feeds this field from two D10 sources: field_type (a
 * "Faculty"/"Staff" list) and field_type_of_staff (a taxonomy with
 * leadership / college_office / etc.). The shared content XML likely
 * surfaces these under one or more of the elements we check below.
 *
 * TODO: once you have a real feed sample, narrow $candidate_elements to
 * the element name(s) the feed actually emits and delete the rest.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 */
protected function mapPersonTypes(Node $node, \SimpleXMLElement $xml) {
  if (!$node->hasField('field_person_types')) {
    return;
  }

  // Label → person_type machine name. Keys are lowercased/trimmed so we
  // tolerate variants like "Pre-Health Advisors" or "pre_health_advisors".
  $label_map = [
    'faculty' => 'faculty',
    'staff' => 'staff',
    'author' => 'author',
    'leadership' => 'leadership',
    'college office' => 'college_office',
    'college_office' => 'college_office',
    'college registrar' => 'college_registrar',
    'college_registrar' => 'college_registrar',
    'dean of arts sciences office' => 'dean_of_arts_sciences_office',
    'dean of arts & sciences office' => 'dean_of_arts_sciences_office',
    'dean of arts &amp; sciences office' => 'dean_of_arts_sciences_office',
    'dean_of_arts_sciences_office' => 'dean_of_arts_sciences_office',
    'pre-graduate advisors' => 'pre_graduate_advisors',
    'pre graduate advisors' => 'pre_graduate_advisors',
    'pre_graduate_advisors' => 'pre_graduate_advisors',
    'pre-health advisors' => 'pre_health_advisors',
    'pre health advisors' => 'pre_health_advisors',
    'pre_health_advisors' => 'pre_health_advisors',
    'pre-law advisors' => 'pre_law_advisors',
    'pre law advisors' => 'pre_law_advisors',
    'pre_law_advisors' => 'pre_law_advisors',
  ];

  // Collect candidate labels from every plausible element name. Values may
  // arrive as a single string, repeated elements, or a <br>/comma-delimited
  // string — handle all three.
  $candidate_elements = ['personType', 'personTypes', 'typeOfStaff', 'type'];
  $raw_labels = [];
  foreach ($candidate_elements as $element_name) {
    if (!isset($xml->{$element_name})) {
      continue;
    }
    foreach ($xml->{$element_name} as $value) {
      $text = (string) $value;
      if ($text === '') {
        continue;
      }
      $parts = preg_split('/\s*<br\s*\/?>\s*|,/i', $text);
      foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
          $raw_labels[] = $part;
        }
      }
    }
  }

  $target_ids = [];
  $unknown_labels = [];
  foreach ($raw_labels as $label) {
    $key = strtolower(trim($label));
    if (isset($label_map[$key])) {
      $machine_name = $label_map[$key];
      if (!in_array($machine_name, $target_ids, TRUE)) {
        $target_ids[] = $machine_name;
      }
    }
    else {
      $unknown_labels[] = $label;
    }
  }

  if (!empty($unknown_labels)) {
    $this->logger->notice('Unmapped person_type label(s) on node @nid: @labels', [
      '@nid' => $node->id() ?? 'new',
      '@labels' => implode(', ', array_unique($unknown_labels)),
    ]);
  }

  if (!empty($target_ids)) {
    $node->set('field_person_types', array_map(
      fn($id) => ['target_id' => $id],
      $target_ids
    ));
  }
}

/**
 * Maps the CV file to field_person_file_upload (media:file reference).
 *
 * Parallel to mapHeadshot() but for documents: download the file, create a
 * file entity, wrap it in a media:file entity, then reference it from the
 * node. Uses a URL hash in the filename to skip re-downloading when the
 * source URL hasn't changed.
 *
 * TODO: verify $xml->cv is the actual element name in the source feed.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 */
protected function mapCv(Node $node, \SimpleXMLElement $xml) {
  if (!isset($xml->cv) || !$node->hasField('field_person_file_upload')) {
    return;
  }

  // $xml->cv arrives as an HTML fragment like:
  //   <div><a href="https://.../file.pdf" ...>Download CV <svg>…</svg></a></div>
  // not as a bare URL. Extract the href out of the first anchor. If there
  // is no anchor but the value is already a plain URL string, accept that
  // too as a fallback.
  $raw = trim((string) $xml->cv);
  if ($raw === '') {
    return;
  }
  $cv_url = $this->extractUrl($raw);
  if ($cv_url === '') {
    $this->logger->debug('CV value on node @nid had no extractable URL; skipping.', [
      '@nid' => $node->id() ?? 'new',
    ]);
    return;
  }

  $url_hash = substr(md5($cv_url), 0, 8);

  try {
    $current_media = $node->get('field_person_file_upload')->entity;

    // Dedup on media name suffix — see mediaMatchesUrlHash() for why.
    if ($current_media && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
      $this->logger->debug('CV unchanged for node @nid, skipping.', [
        '@nid' => $node->id() ?? 'new',
      ]);
      return;
    }

    if ($current_media) {
      $updated = $this->updateMediaFile($current_media, $cv_url, $url_hash, $node->getTitle());
      if ($updated) {
        $this->logger->info('Updated CV media @mid for node @nid from @url.', [
          '@mid' => $updated->id(),
          '@nid' => $node->id() ?? 'new',
          '@url' => $cv_url,
        ]);
      }
      return;
    }

    $media = $this->createMediaFile($cv_url, $url_hash, $node->getTitle());
    if ($media) {
      $node->set('field_person_file_upload', ['target_id' => $media->id()]);
      $this->logger->info('Created CV media @mid for node @nid from @url.', [
        '@mid' => $media->id(),
        '@nid' => $node->id() ?? 'new',
        '@url' => $cv_url,
      ]);
    }
  }
  catch (\Exception $e) {
    $this->logger->error('Error processing CV for node @nid: @message', [
      '@nid' => $node->id() ?? 'new',
      '@message' => $e->getMessage(),
    ]);
  }
}

/**
 * Extracts a usable URL from raw XML text that may be HTML-wrapped.
 *
 * Source feeds often wrap links in HTML fragments — e.g. <div><a href="…">
 * Download…</a></div> — instead of emitting bare URLs. We want just the
 * href. Returns an empty string if no URL is extractable.
 *
 * Shared by mapCv(), mapHeadshot(), mapBookCover(), mapExternalLink(),
 * mapFacultyFields() external link handling, mapEventFields() RSVP, etc.
 *
 * @param string $raw
 *   The raw XML element contents (may be an HTML fragment or a plain URL).
 *
 * @return string
 *   The extracted URL, trimmed; empty string if none found.
 */
protected function extractUrl($raw) {
  $raw = trim((string) $raw);
  if ($raw === '') {
    return '';
  }

  // HTML-encoded ampersands (&amp;) are valid in the feed but break
  // parse_url / Guzzle downstream. Decode first.
  $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

  // If there's an anchor tag, the href wins.
  if (preg_match('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $decoded, $m)) {
    return trim($m[1]);
  }

  // Fallback: if the whole thing already looks like a plain URL, use it.
  if (preg_match('#^https?://#i', $decoded)) {
    return $decoded;
  }

  return '';
}

/**
 * Extracts a usable CV URL from the raw XML value.
 *
 * @deprecated Use extractUrl() instead; kept for backwards compatibility.
 */
protected function extractCvUrl($raw) {
  return $this->extractUrl($raw);
}

/**
 * Downloads a CV URL and saves it as a File entity.
 *
 * Parallel to createFileEntity() but for documents rather than images.
 * Handles content-type validation (no strict image/* check), directory
 * separation (public://shared_content/cvs), and filename hashing so dedup
 * works on subsequent runs.
 *
 * @param string $cv_url
 *   The source CV URL.
 * @param string $url_hash
 *   8-char md5 substring of the URL, embedded in the filename for dedup.
 *
 * @return \Drupal\file\Entity\File|null
 *   The saved File entity, or NULL on download/save failure.
 */
protected function downloadCvToFile($cv_url, $url_hash) {
  $response = $this->httpClient->get($cv_url, [
    'timeout' => 30,
    'http_errors' => FALSE,
  ]);
  if ($response->getStatusCode() !== 200) {
    $this->logger->warning('HTTP @status fetching CV @url.', [
      '@status' => $response->getStatusCode(),
      '@url' => $cv_url,
    ]);
    return NULL;
  }
  $data = $response->getBody()->getContents();
  if ($data === '') {
    return NULL;
  }

  $parsed = parse_url($cv_url);
  $path = $parsed['path'] ?? '';
  $info = pathinfo($path);
  $basename = $info['filename'] ?? 'cv';
  $extension = $info['extension'] ?? 'pdf';
  $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
  $filename = $basename . '_' . $url_hash . '.' . $extension;

  $directory = 'public://shared_content/cvs';
  $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

  try {
    $uri = $this->fileSystem->saveData(
      $data,
      $directory . '/' . $filename,
      FileSystemInterface::EXISTS_REPLACE
    );
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
    $this->logger->error('Failed to save CV file @filename: @message', [
      '@filename' => $filename,
      '@message' => $e->getMessage(),
    ]);
    return NULL;
  }
}

/**
 * Creates a new media:file entity wrapping a downloaded CV.
 *
 * @param string $cv_url
 *   Source URL.
 * @param string $url_hash
 *   8-char md5 substring of the URL.
 * @param string $title
 *   Person node title, used for the media name.
 *
 * @return \Drupal\media\Entity\Media|null
 */
protected function createMediaFile($cv_url, $url_hash, $title) {
  $file = $this->downloadCvToFile($cv_url, $url_hash);
  if (!$file) {
    return NULL;
  }

  // Name ends in '#{url_hash}' so mediaMatchesUrlHash() can dedup later.
  $media = Media::create([
    'bundle' => 'file',
    'name' => $title . ' CV #' . $url_hash,
    'uid' => \Drupal::currentUser()->id(),
    'status' => 1,
    'field_media_file' => [
      'target_id' => $file->id(),
    ],
  ]);
  $media->save();

  return $media;
}

/**
 * Updates an existing media:file entity with a freshly-downloaded CV.
 *
 * Mutates the existing media entity in place rather than creating a new
 * one — keeps the node's field_person_file_upload reference stable and
 * avoids piling up orphan media entities each time the source changes.
 * Old file is queued for cleanup if nothing else references it.
 *
 * @param \Drupal\media\Entity\Media $media
 *   Existing media entity to update.
 * @param string $cv_url
 *   New source URL.
 * @param string $url_hash
 *   8-char md5 substring of the URL.
 * @param string $title
 *   Person node title, used to refresh the media name.
 *
 * @return \Drupal\media\Entity\Media|null
 */
protected function updateMediaFile(Media $media, $cv_url, $url_hash, $title) {
  $old_file = $media->hasField('field_media_file')
    ? $media->get('field_media_file')->entity
    : NULL;

  $file = $this->downloadCvToFile($cv_url, $url_hash);
  if (!$file) {
    return NULL;
  }

  $media->set('field_media_file', ['target_id' => $file->id()]);
  $media->set('name', $title . ' CV #' . $url_hash);
  $media->save();

  if ($old_file && $old_file->id() !== $file->id()) {
    $this->cleanupOrphanedFile($old_file);
  }

  return $media;
}

/**
 * Maps social media URLs to the social_media_links paragraph.
 *
 * Mirrors mapContactParagraph: reuse the existing paragraph when present,
 * otherwise create a new one. Fields absent from the XML are explicitly
 * cleared so the paragraph accurately reflects the source.
 *
 * Paragraph revision state must stay synced with the host node's revisions.
 * See mapContactParagraph() for the full explanation — same fix applies
 * here: bind parent, force new default revision, save.
 *
 * Source format: the feed delivers social links as an HTML
 * <ul class="social-links"> blob inside <socialMediaLinks>. Each <a>
 * carries the platform in its class (twitter, linkedin, instagram,
 * youtube; "twitter" also covers x.com links) and the destination in
 * href. See socialPlatformField() for the class/host resolution.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 * @param string $source_url
 *   The source feed URL, passed to normalizeLinkUri for href validation.
 */
protected function mapSocialMediaParagraph(Node $node, \SimpleXMLElement $xml, $source_url) {
  if (!$node->hasField('field_social_media_links_p')) {
    return;
  }

  // The four link fields on the social_media_links paragraph.
  $platform_fields = [
    'field_instagram_url',
    'field_linkedin_url',
    'field_twitter_url',
    'field_youtube_url',
    'field_bluesky_url',
    'field_google_scholar_url',
  ];

  // Parse the <socialMediaLinks> HTML blob: each <a> gives its platform
  // via the class attribute and its URL via href. First non-empty URL per
  // platform wins; hrefs run through normalizeLinkUri so we never store a
  // schemeless value.
  $urls = [];
  $social_raw = isset($xml->socialMediaLinks) ? (string) $xml->socialMediaLinks : '';
  if ($social_raw !== '' && preg_match_all('/<a\b[^>]*>/i', $social_raw, $anchor_tags)) {
    foreach ($anchor_tags[0] as $tag) {
      if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm)) {
        continue;
      }
      $href = trim(html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5));
      $class = '';
      if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/i', $tag, $cm)) {
        $class = strtolower($cm[1]);
      }
      $field = $this->socialPlatformField($class, $href);
      if ($field === NULL || isset($urls[$field])) {
        continue;
      }
      $uri = $this->normalizeLinkUri($href, $source_url);
      if ($uri !== NULL) {
        $urls[$field] = $uri;
      }
    }
  }

  // No social links present and no existing paragraph — nothing to do.
  $existing = $node->get('field_social_media_links_p')->entity;
  if (empty($urls) && !$existing) {
    return;
  }

  try {
    $paragraph = $existing ?: Paragraph::create(['type' => 'social_media_links']);

    foreach ($platform_fields as $paragraph_field) {
      if (!$paragraph->hasField($paragraph_field)) {
        continue;
      }
      if (isset($urls[$paragraph_field])) {
        // field_*_url are link fields: store the uri with an empty title.
        $paragraph->set($paragraph_field, ['uri' => $urls[$paragraph_field], 'title' => '']);
      }
      else {
        // Source no longer lists this platform: clear it.
        $paragraph->set($paragraph_field, NULL);
      }
    }

    // Bind to host and force correct revision state before saving. Same
    // rationale as mapContactParagraph — without these calls, paragraphs
    // end up detached from their host and trigger "existing default
    // revision can not be changed to non-default" on future IEF edits.
    $paragraph->setParentEntity($node, 'field_social_media_links_p');
    $paragraph->setNewRevision(TRUE);
    $paragraph->isDefaultRevision(TRUE);
    $paragraph->save();

    $node->set('field_social_media_links_p', [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ]);
  }
  catch (\Exception $e) {
    $this->logger->error('Error processing social media for node @nid: @message', [
      '@nid' => $node->id() ?? 'new',
      '@message' => $e->getMessage(),
    ]);
  }
}

/**
 * Resolves a social anchor's platform to its paragraph link field.
 *
 * Detection is class-first (the feed marks each <a> with a platform class
 * such as "twitter", which also covers x.com), then falls back to the
 * href host. Returns NULL for anything outside the four known platforms.
 *
 * @param string $class
 *   The lowercased class attribute of the anchor.
 * @param string $href
 *   The anchor href.
 *
 * @return string|null
 *   The target paragraph field name, or NULL if unrecognized.
 */
protected function socialPlatformField(string $class, string $href): ?string {
  $by_class = [
    'instagram' => 'field_instagram_url',
    'linkedin' => 'field_linkedin_url',
    'twitter' => 'field_twitter_url',
    'youtube' => 'field_youtube_url',
    'bluesky' => 'field_bluesky_url',
    'google_scholar' => 'field_google_scholar_url',
  ];
  foreach ($by_class as $needle => $field) {
    if (strpos($class, $needle) !== FALSE) {
      return $field;
    }
  }

  // Fallback: identify by the link host.
  $host = strtolower((string) parse_url($href, PHP_URL_HOST));
  if (strpos($host, 'instagram.com') !== FALSE) {
    return 'field_instagram_url';
  }
  if (strpos($host, 'linkedin.com') !== FALSE) {
    return 'field_linkedin_url';
  }
  if (strpos($host, 'twitter.com') !== FALSE || $host === 'x.com' || substr($host, -6) === '.x.com') {
    return 'field_twitter_url';
  }
  if (strpos($host, 'youtube.com') !== FALSE || strpos($host, 'youtu.be') !== FALSE) {
    return 'field_youtube_url';
  }
  if (strpos($host, 'bsky.app') !== FALSE) {
    return 'field_bluesky_url';
  }
  if (strpos($host, 'https://libguides.washu.edu') !== FALSE) {
    return 'field_google_scholar_url';
  }
  return NULL;
}

/**
 * Gets or creates a taxonomy term by name.
 */
protected function getOrCreateTerm($name, $vid) {
  $name = trim((string) $name);
  if (empty($name)) {
    return NULL;
  }

  $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
  $terms = $term_storage->loadByProperties([
    'name' => $name,
    'vid' => $vid,
  ]);

  if ($terms) {
    return reset($terms);
  }

  $term = Term::create([
    'vid' => $vid,
    'name' => $name,
  ]);
  $term->save();

  $this->logger->info('Created new @vid term: @name', [
    '@vid' => $vid,
    '@name' => $name,
  ]);

  return $term;
}

/**
 * Adds a term to a taxonomy reference field if not already present.
 */
protected function addTermToField(Node $node, $field_name, $term) {
  if (!$term || !$node->hasField($field_name)) {
    return;
  }

  $existing_values = $node->get($field_name)->getValue();
  $existing_tids = array_column($existing_values, 'target_id');

  if (!in_array($term->id(), $existing_tids, FALSE)) {
    $existing_values[] = ['target_id' => $term->id()];
    $node->set($field_name, $existing_values);
  }
}

/**
 * Maps book-specific fields.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 * @param string $source_url
 *   The source URL (used for fixing relative URLs).
 */
protected function mapBookFields(Node $node, \SimpleXMLElement $xml, $source_url) {
  // Title.
  if (isset($xml->title)) {
    $node->setTitle((string) $xml->title);
  }

  // Cover image as media entity.
  $this->mapBookCover($node, $xml);

  // Byline options.
  if (isset($xml->boptions) && $node->hasField('field_byline_options')) {
    $node->set('field_byline_options', (string) $xml->boptions);
  }

  // Byline names (fallback to author if empty).
  if ($node->hasField('field_byline_names')) {
    $byline = '';
    if (isset($xml->bnames) && !empty((string) $xml->bnames)) {
      $byline = (string) $xml->bnames;
    }
    elseif (isset($xml->author) && !empty((string) $xml->author)) {
      $byline = (string) $xml->author;
    }

    if (!empty($byline)) {
      $node->set('field_byline_names', [
        'value' => $byline,
        'format' => 'full_html',
      ]);
    }
  }

  // Book author - try entity reference first, then fall back to text field.
  $this->mapBookAuthor($node, $xml, $source_url);

  // Links - parse HTML anchor tags into link field.
  if (isset($xml->links) && $node->hasField('field_book_links')) {
    $html = (string) $xml->links;
    if (!empty($html)) {
      if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        $link_values = [];
        foreach ($matches as $match) {
          $url = trim($match[1]);
          $title = trim(preg_replace('/\s+/', ' ', strip_tags($match[2])));

          if (!empty($url) && strpos($url, '#') !== 0) {
            $link_values[] = [
              'uri' => $url,
              'title' => $title,
            ];
          }
        }

        if (!empty($link_values)) {
          $node->set('field_book_links', $link_values);
        }
      }
    }
  }

  // Body / Description.
  if (isset($xml->description)) {
    $body = (string) $xml->description;

    // Fix relative URLs to absolute URLs.
    $host = parse_url($source_url, PHP_URL_HOST);
    if ($host) {
      $body = preg_replace(
        '/(?<=[\",\',\,,\s])\/sites\/' . preg_quote($host, '/') . '\/files\//',
        'https://' . $host . '/sites/' . $host . '/files/',
        $body
      );
    }

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => $body,
        'format' => 'full_html',
      ]);
    }

    if ($node->hasField('field_description')) {
      $node->set('field_description', [
        'value' => $body,
        'format' => 'full_html',
      ]);
    }
  }
}

/**
 * Maps the book cover image to a media entity.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 */
protected function mapBookCover(Node $node, \SimpleXMLElement $xml) {
  if (!isset($xml->cover) || !$node->hasField('field_image')) {
    return;
  }

  $image_url = $this->extractUrl((string) $xml->cover);
  if ($image_url === '') {
    return;
  }

  $url_hash = substr(md5($image_url), 0, 8);

  try {
    $current_media = $node->get('field_image')->entity;

    if ($current_media && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
      $this->logger->debug('Book cover unchanged for node @nid, skipping.', [
        '@nid' => $node->id(),
      ]);
      return;
    }

    if ($current_media) {
      $media = $this->updateMediaImage($current_media, $image_url, $url_hash, $node->getTitle(), 'cover');
      if ($media) {
        $this->logger->info('Updated book cover media @mid for node @nid from @url.', [
          '@mid' => $media->id(),
          '@nid' => $node->id(),
          '@url' => $image_url,
        ]);
      }
      return;
    }

    $media = $this->createMediaImage($image_url, $url_hash, $node->getTitle(), 'cover');
    if ($media) {
      $node->set('field_image', ['target_id' => $media->id()]);
      $this->logger->info('Created book cover media @mid for node @nid from @url.', [
        '@mid' => $media->id(),
        '@nid' => $node->id(),
        '@url' => $image_url,
      ]);
    }
  }
  catch (\Exception $e) {
    $this->logger->error('Error processing book cover for node @nid: @message', [
      '@nid' => $node->id(),
      '@message' => $e->getMessage(),
    ]);
  }
}

/**
 * Maps the book author field.
 *
 * Tries to find an existing person node by authornid first,
 * then falls back to storing author name as text.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 * @param string $source_url
 *   The source XML URL — used as a fallback for base URL extraction when
 *   the XML itself does not contain an externalURL.
 */
protected function mapBookAuthor(Node $node, \SimpleXMLElement $xml, $source_url = '') {
  // First try to link to an existing person node via authornid.
  if (isset($xml->authornid) && $node->hasField('field_book_author')) {
    $author_nid = (int) $xml->authornid;
    if ($author_nid > 0) {
      // Check if we have a local node with this shared content source.
      // The authornid is the remote node ID, so we need to find the local
      // node that was imported from that source.
      $local_author = $this->findLocalPersonByRemoteId($xml, $author_nid, $source_url);

      if ($local_author) {
        $node->set('field_book_author', ['target_id' => $local_author->id()]);
        return;
      }
    }
  }

  // Fallback: store author name as text in a separate field if available.
  // Check for facultyAndStaff first, then author.
  $author_name = '';
  if (isset($xml->facultyAndStaff) && !empty((string) $xml->facultyAndStaff)) {
    $author_name = (string) $xml->facultyAndStaff;
  }
  elseif (isset($xml->author) && !empty((string) $xml->author)) {
    $author_name = (string) $xml->author;
  }

  // If field_book_author is an entity reference, we can't store plain text.
  // Check if there's a separate text field for author name.
  if (!empty($author_name)) {
    if ($node->hasField('field_book_author_name')) {
      $node->set('field_book_author_name', $author_name);
    }
    // If field_book_author accepts text (unlikely but possible), set it.
    // Otherwise, store in a custom field or log that author couldn't be linked.
    $this->logger->debug('Book author "@name" could not be linked to a person node for node @nid.', [
      '@name' => $author_name,
      '@nid' => $node->id() ?? 'new',
    ]);
  }
}

/**
 * Finds a local person node that was imported from a remote source.
 *
 * @param \SimpleXMLElement $xml
 *   The XML element (used to extract base URL).
 * @param int $remote_nid
 *   The remote node ID.
 * @param string $source_url
 *   Fallback source URL — used for base URL extraction when the XML does
 *   not carry an externalURL. Books in particular often lack externalURL.
 *
 * @return \Drupal\node\Entity\Node|null
 *   The local person node, or NULL if not found.
 */
protected function findLocalPersonByRemoteId(\SimpleXMLElement $xml, $remote_nid, $source_url = '') {
  // Try to extract the base URL from externalURL first, then source_url.
  $base_url = '';
  if (isset($xml->externalURL)) {
    $parsed = parse_url((string) $xml->externalURL);
    if ($parsed && isset($parsed['scheme']) && isset($parsed['host'])) {
      $base_url = $parsed['scheme'] . '://' . $parsed['host'];
    }
  }

  if (empty($base_url) && !empty($source_url)) {
    $parsed = parse_url($source_url);
    if ($parsed && isset($parsed['scheme']) && isset($parsed['host'])) {
      $base_url = $parsed['scheme'] . '://' . $parsed['host'];
    }
  }

  if (empty($base_url)) {
    return NULL;
  }

  // Construct the expected shared content XML URL for the person.
  $person_shared_url = $base_url . '/xml/faculty_staff/' . $remote_nid . '/rss.xml';

  // Query for a local node with this shared content URL.
  $query = $this->entityTypeManager->getStorage('node')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'person')
    ->condition('field_shared_content_xml', $person_shared_url)
    ->range(0, 1);

  $nids = $query->execute();

  if (!empty($nids)) {
    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

  return NULL;
}

}
