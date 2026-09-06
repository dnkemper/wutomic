<?php

namespace Drupal\shared_content\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\paragraphs\Entity\Paragraph;


/**
 * Action to import selected Aggregator Feed items as nodes.
 *
 * @Action(
 *   id = "import_aggregator_feed_item_action",
 *   label = @Translation("Import selected feed items as content"),
 *   type = "aggregator_item"
 * )
 */
class ImportAggregatorFeedItemAction extends ActionBase {

    /**
     * {@inheritdoc}
     */
    public function execute($entity = NULL) {

        if ($entity) {
            // Get the GUID and Feed Title.
            $guid = $entity->get('guid')->value;
            $fid_title = $entity->get('fid')->entity->label();
            $entity_title = $entity->get('title')->value;

            // Split the GUID to extract parts, with validation to avoid undefined array keys.
            $guid_parts = explode(' at ', $guid);
            $node_id = $guid_parts[0] ?? NULL;
            $base_url = $guid_parts[1] ?? NULL;

            // Only proceed if $node_id and $base_url are available.
            if ($node_id && $base_url) {
                // Determine content type based on the Feed Title.
                $fid_parts = explode(' ', $fid_title);
                $fid_test = isset($fid_parts[1]) ? $this->getContentType($fid_parts[1]) : '';

                // Construct the shared URL.
                $sharedURL = $base_url . '/xml/' . strtolower($fid_test) . '/' . $node_id . '/rss.xml';

                // Check if a node with the same shared URL already exists.
                if (!$this->checkExistingNode($sharedURL)) {
                    // Fetch XML content.
                    $xml = $this->sharedContentGetXml($sharedURL);
                    if ($xml) {
                        $xmlElement = $xml->node;

                        // // Determine the XML element based on the source domain.
                        $node = $this->createNodeFromXml($entity->getTitle(), $xml, strtolower($fid_test), $sharedURL);
                        $node_id = $node->id();

                        // Event-specific fields (dates, location, contact,
                        // teaser, image, virtual link, RSVP) are populated in
                        // createNodeFromXml() via mapEventFields().

                        // Save node after setting fields.
                        $node->save();

                        // Create a URL and link for the new node.
                        $url = Url::fromRoute('entity.node.canonical', ['node' => $node_id], ['absolute' => TRUE]);
                        $link = Link::fromTextAndUrl($node->getTitle(), $url)->toString();

                        // Display the linked message.
                        \Drupal::messenger()->addMessage($this->t('Processed content: @link', ['@link' => $link]));

                        // Log processed content.
                        \Drupal::logger('shared_content')->info('Processed content: @link', ['@link' => $link]);

                        // Update shared subscribers.
                        // $this->updateSharedSubscribers($node_id);
                    }
                }
                else {
                    \Drupal::messenger()->addMessage($this->t('Content already exists for URL: @url', ['@url' => $entity_title]));
                }
            }
            else {
                \Drupal::messenger()->addWarning($this->t('GUID or base URL could not be determined from the feed item.'));
            }
        }
    }

    /**
     * Helper function to determine content type based on fid title.
     */
    protected function getContentType($fid_part) {
        switch ($fid_part) {
            case 'Articles':
                return 'article';

            case 'Events':
                return 'events';

            case 'Person':
                return 'faculty_staff';

            case 'Books':
                return 'book';

            default:
                return '';
        }
    }

    /**
     * Checks if a node with the given shared URL already exists.
     */
    protected function checkExistingNode($sharedURL) {
        $query = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('field_shared_content_xml', $sharedURL)
            ->range(0, 1);
        $nids = $query->execute();
        return !empty($nids);
    }

    /**
     * Fetches XML content from the provided URL using curl.
     */
    protected function sharedContentGetXml($path) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        $returned = curl_exec($ch);
        curl_close($ch);
        return simplexml_load_string($returned);
    }

    /**
     * Creates a node from XML data.
     *
     * @param string $title
     *   Fallback title from the aggregator item.
     * @param \SimpleXMLElement $xml
     *   The full parsed XML document.
     * @param string $type
     *   The Drupal content type machine name.
     * @param string $sharedURL
     *   The source XML URL stored in field_shared_content_xml.
     *
     * @return \Drupal\node\Entity\Node
     *   The created or updated node entity.
     */
    protected function createNodeFromXml($title, $xml, $type, $sharedURL) {
        if ($type == 'faculty_staff') {
            $type = 'person';
        }
        if ($type == 'events') {
            $type = 'event';
        }
        $xml_content = base64_encode($xml->asXML());

        $xmlElement = $xml->node;
        $node = Node::create([
            'type' => $type,
            'title' => $title,
            'field_shared_content_xml' => $sharedURL,
            'field_shared_content' => $xml_content,
            'status' => 1,
            'uid' => \Drupal::currentUser()->id(),
        ]);

        // Set tracking fields at import time so processDeletedNodes() knows
        // this node was successfully created, and hash comparisons work from day 1.
        if ($node->hasField('field_last_fetch')) {
            $node->set('field_last_fetch', \Drupal::time()->getRequestTime());
        }
        if ($node->hasField('field_content_hash')) {
            $node->set('field_content_hash', hash('sha256', $xml_content));
        }

        // Handle book-specific fields.
        if ($type === 'book') {
            $this->mapBookFields($node, $xmlElement, $sharedURL);
        }

        // Handle events-specific fields.
        if ($type === 'event') {
            $this->mapEventFields($node, $xmlElement, $sharedURL);
        }

        // Handle articles-specific fields.
        //
        // Field mapping (each is a no-op when the field isn't on the bundle):
        //   description → body (with relative-file URL rewriting like book/person)
        //   introText   → field_article_subhead
        //   summary     → field_link_excerpt
        //   author      → field_article_source_org
        //   externalURL → field_article_source_link
        //   headerImage → field_header_image (image media bundle)
        //   video       → field_header_image (remote_video media bundle, wins over headerImage)
        //   thumbnail   → field_image (image media bundle)
        //   authornid   → field_contact_reference (person node reference)
        //   postDate    → node created time
        //
        // title is already on the node from createNodeFromXml's title param.
        // SharedContentFieldMapper::mapArticleFields() mirrors this mapping
        // so refresh and import agree.
        if ($type == 'article') {
            // description → body, with the same relative-file URL fix the
            // book and person mappers apply. Without this the action would
            // import articles with empty bodies until the first refresh.
            if (isset($xmlElement->description) && $node->hasField('body')) {
                $body = (string) $xmlElement->description;
                $host = parse_url($sharedURL, PHP_URL_HOST);
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
                // introText → body summary (tags stripped, matching the
                // person body/summary handling).
                if (isset($xmlElement->introText)) {
                    $summary = trim(strip_tags((string) $xmlElement->introText));
                    if ($summary !== '') {
                        $body_value['summary'] = $summary;
                    }
                }
                $node->set('body', $body_value);
            }

            if (isset($xmlElement->introText) && $node->hasField('field_article_subhead')) {
                $node->set('field_article_subhead', (string) $xmlElement->introText);
            }

            if (isset($xmlElement->summary) && $node->hasField('field_link_excerpt')) {
                $node->set('field_link_excerpt', (string) $xmlElement->summary);
            }

            if (isset($xmlElement->author) && $node->hasField('field_article_source_org')) {
                $node->set('field_article_source_org', (string) $xmlElement->author);
            }

            if (isset($xmlElement->externalURL) && $node->hasField('field_article_source_link')) {
                $external_url = (string) $xmlElement->externalURL;
                if (!empty($external_url)) {
                    // Link text ("Name of Publication"): prefer the source
                    // org/author the feed carries, fall back to the article
                    // title so the link always has text.
                    $link_title = isset($xmlElement->author) ? trim((string) $xmlElement->author) : '';
                    if ($link_title === '') {
                        $link_title = isset($xmlElement->title) ? trim((string) $xmlElement->title) : '';
                    }
                    $node->set('field_article_source_link', [
                        'uri' => $external_url,
                        'title' => $link_title,
                    ]);
                }
            }

            // Header media → field_header_image. video wins over headerImage.
            $this->mapArticleHeaderMedia($node, $xmlElement);

            // thumbnail → field_image.
            $this->mapArticleThumbnail($node, $xmlElement);

            // soundCloud → field_soundcloud_media.
            $this->mapArticleSoundcloud($node, $xmlElement);

            // authornid → field_contact_reference (Associated person).
            $this->mapArticleContactReference($node, $xmlElement, $sharedURL);
            $this->mapArticleAuthor($node, $xmlElement, $sharedURL);

            // postDate → created time.
            if (isset($xmlElement->postDate)) {
                $postDate = (string) $xmlElement->postDate;
                $dateTime = \DateTime::createFromFormat('n.j.y', $postDate);
                if ($dateTime !== FALSE) {
                    $node->setCreatedTime($dateTime->getTimestamp());
                }
                else {
                    $node->setCreatedTime(\Drupal::time()->getRequestTime());
                }
            }
        }

        if ($type === 'person' || $type === 'faculty_staff') {
            if ($node->hasField('field_external_link')) {
                if (isset($xmlElement->externalURL) && !empty((string) $xmlElement->externalURL)) {
                    $external_url = (string) $xmlElement->externalURL;
                    $node->set('field_external_link', [
                        'uri' => $external_url,
                    ]);
                }
            }
            $firstName = isset($xmlElement->firstName) ? (string) $xmlElement->firstName : '';
            $lastName = isset($xmlElement->lastName) ? (string) $xmlElement->lastName : '';

            if (!empty($firstName) || !empty($lastName)) {
                $node->set('field_person_first_name', $firstName);
                $node->set('field_person_last_name', $lastName);
                $node->setTitle(trim("$firstName $lastName"));
            }
            else {
                $node->setTitle((string) $xmlElement->title);
            }

            // Combine position and additional titles.
            $position = trim((string) $xmlElement->position);
            $additional_titles = trim((string) $xmlElement->additionalTitles);

            $all_positions = [];

            if (!empty($position)) {
                $all_positions[] = $position;
            }

            if (!empty($additional_titles)) {
                // Split by <br> if present.
                $additional_items = array_filter(
                    array_map('trim', preg_split('/\s*<br\s*\/?>\s*/i', $additional_titles))
                );
                $all_positions = array_merge($all_positions, $additional_items);
            }

            if (!empty($all_positions)) {
                // If field_person_position is multivalue:
                $node->set('field_person_position', array_map(
                    fn($item) => ['value' => $item],
                    $all_positions
                ));

                // OR if it's a single value field, join with a separator:
                // $node->set('field_person_position', implode('; ', $all_positions));
            }
            $node->set('field_person_email', (string) $xmlElement->emailAddress);
            $node->set('field_person_phone', (string) $xmlElement->phoneNumber);
            $node->set('field_person_fax', (string) $xmlElement->faxNumber);
            $node->set('field_external_url', (string) $xmlElement->officeDirectionsURL);
            $node->set('field_building_name_and_room_num', [
                'value' => (string) $xmlElement->buildingNameRoomNumber,
                'format' => 'full_html', // or 'basic_html', check your available formats
            ]);
            $node->set('field_office_hours', [
                'value' => (string) $xmlElement->officeHours,
                'format' => 'full_html', // or 'basic_html', check your available formats
            ]);
            // Body, with introText routed into the body summary.
            // Previously introText was stripped of tags and stored in
            // field_teaser; we now treat it as the body's summary so the
            // teaser-style excerpt lives alongside the long-form bio in a
            // single text_with_summary field.
            $body_value = [
                'value' => (string) $xmlElement->biography,
                'format' => 'full_html', // or 'basic_html', check your available formats
            ];
            $intro_text = trim(strip_tags((string) $xmlElement->introText));
            if ($intro_text !== '') {
                $body_value['summary'] = $intro_text;
            }
            $node->set('body', $body_value);
            $node->set('field_person_description', [
                'value' => (string) $xmlElement->description,
                'format' => 'full_html', // or 'basic_html', check your available formats
            ]);
            $node->set('field_person_credential', (string) $xmlElement->pronouns);
            if (isset($xmlElement->interests) && $node->hasField('field_person_interests')) {
            $interests = (string) $xmlElement->interests;
            if (!empty($interests)) {
                $node->set('field_person_interests', [
                    'value' => $interests,
                    'format' => 'full_html',
                ]);
            }
            }

            $node->set('field_content_hash', (string) $xmlElement->hash);
            $node->set('field_last_fetch', (string) $xmlElement->lastFetch);

            // Handle education as multivalue field.
            // Education - rendered as HTML list.
            $education = (string) $xmlElement->education;
            if (!empty($education)) {
                $education_items = array_filter(
                    array_map('trim', preg_split('/\s*<br\s*\/?>\s*/i', $education))
                );
                $node->set('field_person_education', array_map(
                    fn($item) => ['value' => $item],
                    $education_items
                ));
            }

            // Add department to research areas taxonomy.
            // $term = $this->getOrCreateTerm($xmlElement->department, 'research_areas');
            // $this->addTermToField($node, 'field_person_research_areas', $term);

            // Also reference department as its own taxonomy term on
            // field_person_department. Same getOrCreateTerm pattern so a
            // matching term is reused, and a new one is created on miss.
            $department_term = $this->getOrCreateTerm($xmlElement->department, 'department');
            $this->addTermToField($node, 'field_person_department', $department_term);

            $links_raw = (string) $xmlElement->links;
            if (!empty($links_raw)) {
                // Extract all anchor tags with href and link text.
                if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $links_raw, $matches, PREG_SET_ORDER)) {
                    $link_values = [];
                    foreach ($matches as $match) {
                        $href = trim($match[1]);
                        // Strip HTML tags from link text (removes SVG icons, etc.).
                        $title = trim(strip_tags($match[2]));

                        if (!empty($href)) {
                            // Skip internal anchor links like #link-out.
                            if (strpos($href, '#') === 0) {
                                continue;
                            }

                            $link_values[] = [
                                'uri' => $href,
                                'title' => $title,
                            ];
                        }
                    }

                    if (!empty($link_values)) {
                        $node->set('field_person_website', $link_values);
                    }
                }
            }
            // Contact paragraph.
            $address_raw = trim((string) $xmlElement->mailingAddress);

            if (!empty($address_raw)) {
                $paragraph = Paragraph::create(['type' => 'artsci_contact']);

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
                        // Couldn't parse, put it back.
                        $address_lines[] = $last_line;
                    }

                    // Remaining lines go into address_line1 and address_line2.
                    if (count($address_lines) >= 1) {
                        $address_data['address_line1'] = array_shift($address_lines);
                    }
                    if (count($address_lines) >= 1) {
                        $address_data['address_line2'] = implode(', ', $address_lines);
                    }

                    $paragraph->set('field_artsci_contact_address', $address_data);
                }

                $paragraph->save();

                $node->set('field_person_contact_information', [
                    'target_id' => $paragraph->id(),
                    'target_revision_id' => $paragraph->getRevisionId(),
                ]);

            }

            // Import headshot image.
            $this->mapHeadshot($node, $xmlElement);
            $this->mapCv($node, $xmlElement);
        }

        $node->save();
        return $node;
    }

    /**
     * Maps book-specific fields from XML onto the node.
     *
     * Mirrors the book section of the artsci_d10_book migration:
     *  - <title>      -> node title
     *  - <cover>      -> field_image (media:image, mirrors person headshot path)
     *  - <boptions>   -> field_byline_options (list_string)
     *  - <bnames>     -> field_byline_names (string), with <author> as fallback
     *  - <author>/<authornid> -> field_book_author (entity reference to person
     *                            with person_type=author), via shared content
     *                            cross-site lookup
     *  - <links>      -> field_book_links (link, multi-value); D10 paragraph
     *                    references are flattened to anchor parsing here
     *  - <description> -> body and field_description (relative file URLs are
     *                     rewritten to absolute, identical to the field
     *                     mapper service so refresh and import agree)
     *
     * @param \Drupal\node\Entity\Node $node
     *   The book node being created.
     * @param \SimpleXMLElement $xmlElement
     *   The <node> XML element from the source feed.
     * @param string $sharedURL
     *   The source XML URL, used as fallback host for relative URL fixes.
     */
    protected function mapBookFields(Node $node, \SimpleXMLElement $xmlElement, $sharedURL) {
        // Title.
        if (isset($xmlElement->title)) {
            $node->setTitle((string) $xmlElement->title);
        }

        // Cover image as media entity (parallel to person headshot).
        $this->mapBookCover($node, $xmlElement);

        // Byline options (list_string, single value).
        if (isset($xmlElement->boptions) && $node->hasField('field_byline_options')) {
            $node->set('field_byline_options', (string) $xmlElement->boptions);
        }

        // Byline names (string, single value). Falls back to <author> when
        // <bnames> is empty so books without an explicit byline still credit
        // the author. field_byline_names is a plain string field, so we set
        // it as a scalar — not as a value/format array.
        if ($node->hasField('field_byline_names')) {
            $byline = '';
            if (isset($xmlElement->bnames) && !empty((string) $xmlElement->bnames)) {
                $byline = (string) $xmlElement->bnames;
            }
            elseif (isset($xmlElement->author) && !empty((string) $xmlElement->author)) {
                $byline = (string) $xmlElement->author;
            }
            if (!empty($byline)) {
                $node->set('field_byline_names', $byline);
            }
        }

        // Book author: try entity reference to person/author first, fall back
        // to text storage when the cross-site person can't be resolved.
        $this->mapBookAuthor($node, $xmlElement, $sharedURL);

        // Links: D10 stored these as paragraph references; the source XML
        // emits them as an HTML list of anchors. Parse anchors into D11's
        // field_book_links link field. Anchor parsing pattern is identical
        // to the field mapper's mapBookFields() so refresh and import agree.
        if (isset($xmlElement->links) && $node->hasField('field_book_links')) {
            $html = (string) $xmlElement->links;
            if (!empty($html)) {
                if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
                    $link_values = [];
                    foreach ($matches as $match) {
                        $url = trim($match[1]);
                        $link_title = trim(preg_replace('/\s+/', ' ', strip_tags($match[2])));

                        if (!empty($url) && strpos($url, '#') !== 0) {
                            $link_values[] = [
                                'uri' => $url,
                                'title' => $link_title,
                            ];
                        }
                    }
                    if (!empty($link_values)) {
                        $node->set('field_book_links', $link_values);
                    }
                }
            }
        }

        // Description routed to both body (text_with_summary) and
        // field_description (text_long). Relative file URLs are rewritten
        // to absolute on the source host so images survive cross-site
        // rendering. Same regex as person/article body mapping.
        if (isset($xmlElement->description)) {
            $body = (string) $xmlElement->description;

            $host = parse_url($sharedURL, PHP_URL_HOST);
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
     * Maps event-specific fields from XML onto the node (initial import).
     *
     * Mirrors SharedContentFieldMapper::mapEventFields() so import and
     * refresh agree. Source element to D11 field mapping (each guarded so a
     * missing element or absent field is a no-op):
     *   description            -> body (relative file URLs rewritten absolute)
     *   externalURL            -> field_event_series_link (link, when non-empty)
     *   eventDateStart/End
     *     (fallback eventDate)  -> field_event_when (smartdate; duration computed)
     *   introText              -> field_teaser (string_long)
     *   location +
     *   additionalLocationInformation
     *                          -> field_event_location (address)
     *   eventContact           -> field_event_contact (email; first valid token)
     *   thumbnail              -> field_image (media:image)
     *   buttonText + buttonURL -> field_event_virtual (link)
     *   rsvpLink               -> field_rsvp_form (webform ref; only set when
     *                             the referenced webform exists locally)
     *
     * Not mapped, matching the artsci_d10_event migration: eventTBD
     * (field_event_date_tbd dropped on D11), eventGeolocation (D11 geofield,
     * not carried), field_event_category (no element in the feed).
     *
     * @param \Drupal\node\Entity\Node $node
     *   The event node being created.
     * @param \SimpleXMLElement $xmlElement
     *   The <node> XML element from the source feed.
     * @param string $sharedURL
     *   The source XML URL, used as the host for relative URL fixes.
     */
    protected function mapEventFields(Node $node, \SimpleXMLElement $xmlElement, $sharedURL) {
        // Body: description -> body, rewriting relative /sites/{host}/files/
        // URLs to absolute (same fix the article and book mappers apply).
            if (isset($xmlElement->description) && $node->hasField('body')) {
                $body = (string) $xmlElement->description;
                $host = parse_url($sharedURL, PHP_URL_HOST);
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
                // introText → body summary (tags stripped, matching the
                // person body/summary handling).
                if (isset($xmlElement->introText)) {
                    $summary = trim(strip_tags((string) $xmlElement->introText));
                    if ($summary !== '') {
                        $body_value['summary'] = $summary;
                    }
                }
                $node->set('body', $body_value);
            }

        // Series link: externalURL -> field_event_series_link, when non-empty.
        if ($node->hasField('field_event_series_link')
            && isset($xmlElement->externalURL)
            && !empty((string) $xmlElement->externalURL)) {
            $node->set('field_event_series_link', [
                'uri' => (string) $xmlElement->externalURL,
            ]);
        }
        if ($node->hasField('field_event_geolocation')
            && isset($xmlElement->eventGeolocation)
            && !empty((string) $xmlElement->eventGeolocation)) {
            $node->set('field_event_geolocation', [
                'uri' => (string) $xmlElement->eventGeolocation,
            ]);
        }
        if (isset($xmlElement->rsvpFormLink) && $node->hasField('field_rsvp_form_link')) {
            $rsvp = trim((string) $xmlElement->rsvpFormLink);
            if ($rsvp !== '') {
                $node->set('field_rsvp_form_link', [
                    'uri' => $rsvp,
                    'title' => 'RSVP Link',
                ]);
            }
        }
        // When (smartdate). Prefer discrete start/end, fall back to splitting
        // eventDate on ' to '. Duration (minutes) is computed so the smartdate
        // field renders the end time correctly.
        $start = isset($xmlElement->eventDateStart) ? trim((string) $xmlElement->eventDateStart) : '';
        $end = isset($xmlElement->eventDateEnd) ? trim((string) $xmlElement->eventDateEnd) : '';
        if ($start === '' && isset($xmlElement->eventDate)) {
            $parts = explode(' to ', (string) $xmlElement->eventDate);
            if (count($parts) === 2) {
                $start = trim($parts[0]);
                $end = trim($parts[1]);
            }
        }
        if ($start !== '' && $node->hasField('field_event_when')) {
            $start_ts = strtotime($start) ?: NULL;
            if ($start_ts) {
                $end_ts = $end !== '' ? (strtotime($end) ?: NULL) : NULL;
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
        }

        // Teaser: the source introduction excerpt arrives as introText.
        if (isset($xmlElement->introText) && $node->hasField('field_teaser')) {
            $teaser = trim((string) $xmlElement->introText);
            if ($teaser !== '') {
                $node->set('field_teaser', $teaser);
            }
        }

        // Location (address): venue -> address_line1, additional info ->
        // organization, country US. Mirrors the migration's
        // parse_mailing_address(location, additionalLocationInformation).
        if ($node->hasField('field_event_location')) {
            $location = isset($xmlElement->location) ? trim((string) $xmlElement->location) : '';
            $additional = isset($xmlElement->additionalLocationInformation)
                ? trim((string) $xmlElement->additionalLocationInformation)
                : '';
            if ($location !== '' || $additional !== '') {
                $address = ['country_code' => 'US'];
                if ($location !== '') {
                    $address['address_line1'] = $location;
                }
                if ($additional !== '') {
                    $address['organization'] = $additional;
                }
                $node->set('field_event_location', $address);
            }
        }

        // Contact (email): first valid email token only.
        if (isset($xmlElement->eventContact) && $node->hasField('field_event_contact')) {
            $email = $this->extractEmail((string) $xmlElement->eventContact);
            if ($email !== '') {
                $node->set('field_event_contact', $email);
            }
        }

        // Thumbnail -> field_image (media:image).
        if (isset($xmlElement->thumbnail) && $node->hasField('field_image')) {
            $image_url = $this->extractUrl((string) $xmlElement->thumbnail);
            if ($image_url !== '') {
                $this->setImageMedia($node, $image_url, 'field_image', 'thumbnail');
            }
        }

        // Virtual / CTA link: buttonText + buttonURL -> field_event_virtual.
        if (isset($xmlElement->buttonURL) && $node->hasField('field_event_virtual')) {
            $button_url = trim((string) $xmlElement->buttonURL);
            if ($button_url !== '') {
                $node->set('field_event_virtual', [
                    'uri' => $button_url,
                    'title' => isset($xmlElement->buttonText) ? trim((string) $xmlElement->buttonText) : '',
                ]);
            }
        }

        // RSVP webform reference: parse the machine name from the /form/{name}
        // path in rsvpLink and set it only when that webform exists locally (a
        // reference to a missing webform fails save validation).
        if (isset($xmlElement->rsvpLink) && $node->hasField('field_rsvp_form')) {
            $rsvp_url = $this->extractUrl((string) $xmlElement->rsvpLink);
            if ($rsvp_url !== '') {
                $path = (string) parse_url($rsvp_url, PHP_URL_PATH);
                if (preg_match('#/form/([a-z0-9_]+)#i', $path, $m)) {
                    $machine_name = $m[1];
                    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load($machine_name);
                    if ($webform) {
                        $node->set('field_rsvp_form', ['target_id' => $machine_name]);
                    }
                    else {
                        \Drupal::logger('shared_content')->debug('RSVP webform "@name" not found locally for node @title; skipping field_rsvp_form.', [
                            '@name' => $machine_name,
                            '@title' => $node->getTitle(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Extracts the first valid email address from a free-text string.
     *
     * Mirrors SharedContentFieldMapper::extractEmail(): pulls the first
     * valid email token and returns an empty string when none is present,
     * so an invalid value never lands in the email field.
     *
     * @param string $raw
     *   The raw element contents.
     *
     * @return string
     *   The extracted email, or an empty string.
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
     * Maps the article header media onto field_header_image.
     *
     * field_header_image is a media reference that accepts both image
     * and remote_video bundles. video wins over headerImage when both
     * are present — the moving asset is the more meaningful headline.
     * Mirrors SharedContentFieldMapper::mapArticleHeaderMedia() so
     * refresh and import agree on which bundle ends up referenced.
     *
     * @param \Drupal\node\Entity\Node $node
     * @param \SimpleXMLElement $xml
     */
    protected function mapArticleHeaderMedia(Node $node, \SimpleXMLElement $xml) {
        if (!$node->hasField('field_header_image')) {
            return;
        }

        // <Video> (capital V) carries a rendered <iframe> embed, so it needs
        // the iframe-aware extractor, not the anchor-based extractUrl().
        if (isset($xml->Video)) {
            $video_url = $this->extractVideoUrl((string) $xml->Video);
            if ($video_url !== '') {
                // The <Video> blob also carries the editorial poster <img>;
                // use it as the remote_video thumbnail instead of the
                // auto-fetched provider one.
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
     * @param \SimpleXMLElement $xml
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
     * Maps the article author (authornid) onto field_contact_reference.
     *
     * field_contact_reference ("Associated person") is a multi-value entity
     * reference to person nodes. The feed's <authornid> is the author's node
     * ID on the source site that served this feed; we resolve the local
     * person by matching field_shared_content_xml against the source site's
     * /xml/faculty_staff/{authornid}/rss.xml URL, mirroring mapBookAuthor().
     *
     * The base host is derived from $sharedURL (the feed URL), NOT the
     * article's externalURL — for articles externalURL is the external
     * publication link, which would resolve the person against the wrong host.
     *
     * Set-only: a no-op when the element or field is absent, the id is
     * non-positive, the base can't be derived, or no local person matches.
     *
     * @param \Drupal\node\Entity\Node $node
     * @param \SimpleXMLElement $xml
     * @param string $sharedURL
     *   The source feed URL stored in field_shared_content_xml.
     */
    protected function mapArticleContactReference(Node $node, \SimpleXMLElement $xml, $sharedURL) {
        if (!isset($xml->authornid) || !$node->hasField('field_contact_reference')) {
            return;
        }
        $author_nid = (int) $xml->authornid;
        if ($author_nid <= 0) {
            return;
        }

        // Derive the base host from the feed URL (the source site), not the
        // article's externalURL, which points at the external publication.
        $parsed = parse_url($sharedURL);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return;
        }
        $base_url = $parsed['scheme'] . '://' . $parsed['host'];

        $person_shared_url = $base_url . '/xml/faculty_staff/' . $author_nid . '/rss.xml';
        $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', 'person')
            ->condition('field_shared_content_xml', $person_shared_url)
            ->range(0, 1)
            ->execute();

        if (empty($nids)) {
            \Drupal::logger('shared_content')->debug('Article author (remote nid @rnid) not found locally for node @title; skipping field_contact_reference.', [
                '@rnid' => $author_nid,
                '@title' => $node->getTitle(),
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
     * ID on the source site that served this feed; we resolve the local
     * person by matching field_shared_content_xml against the source site's
     * /xml/faculty_staff/{authornid}/rss.xml URL, mirroring mapBookAuthor().
     *
     * The base host is derived from $sharedURL (the feed URL), NOT the
     * article's externalURL — for articles externalURL is the external
     * publication link, which would resolve the person against the wrong host.
     *
     * Set-only: a no-op when the element or field is absent, the id is
     * non-positive, the base can't be derived, or no local person matches.
     *
     * @param \Drupal\node\Entity\Node $node
     * @param \SimpleXMLElement $xml
     * @param string $sharedURL
     *   The source feed URL stored in field_shared_content_xml.
     */
    protected function mapArticleAuthor(Node $node, \SimpleXMLElement $xml, $sharedURL) {
        if (!isset($xml->authornid) || !$node->hasField('field_article_author')) {
            return;
        }
        $author_nid = (int) $xml->authornid;
        if ($author_nid <= 0) {
            return;
        }

        // Derive the base host from the feed URL (the source site), not the
        // article's externalURL, which points at the external publication.
        $parsed = parse_url($sharedURL);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return;
        }
        $base_url = $parsed['scheme'] . '://' . $parsed['host'];

        $person_shared_url = $base_url . '/xml/faculty_staff/' . $author_nid . '/rss.xml';
        $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', 'person')
            ->condition('field_shared_content_xml', $person_shared_url)
            ->range(0, 1)
            ->execute();

        if (empty($nids)) {
            \Drupal::logger('shared_content')->debug('Article author (remote nid @rnid) not found locally for node @title; skipping field_contact_reference.', [
                '@rnid' => $author_nid,
                '@title' => $node->getTitle(),
            ]);
            return;
        }

        $node->set('field_article_author', [['target_id' => reset($nids)]]);
    }

    /**
     * Creates or updates an image media entity on a node field.
     *
     * Generic counterpart to mapHeadshot() and mapBookCover() — caller
     * supplies the destination field, image type label (used for media
     * name and on-disk subdirectory), and the source URL. Dedup uses the
     * same url_hash suffix scheme so all image fields share the same
     * caching semantics.
     *
     * If the field currently holds a non-image media (e.g. a remote_video
     * from a previous import that had a video URL), the reference is
     * replaced rather than mutated in place — we don't try to convert
     * media bundles.
     *
     * @param \Drupal\node\Entity\Node $node
     * @param string $image_url
     * @param string $field_name
     * @param string $type
     */
    protected function setImageMedia(Node $node, $image_url, $field_name, $type) {
        $url_hash = substr(md5($image_url), 0, 8);

        try {
            $current_media = $node->get($field_name)->entity;

            if ($current_media && $current_media->bundle() === 'image'
                && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
                \Drupal::logger('shared_content')->debug('@type unchanged for node @title, skipping.', [
                    '@type' => $type,
                    '@title' => $node->getTitle(),
                ]);
                return;
            }

            if ($current_media && $current_media->bundle() === 'image') {
                $media = $this->updateMediaImageWithType($current_media, $image_url, $url_hash, $node->getTitle(), $type);
                if ($media) {
                    \Drupal::logger('shared_content')->info('Updated @type media @mid for node @title from @url.', [
                        '@type' => $type,
                        '@mid' => $media->id(),
                        '@title' => $node->getTitle(),
                        '@url' => $image_url,
                    ]);
                }
                return;
            }

            $media = $this->createMediaImageWithType($image_url, $url_hash, $node->getTitle(), $type);
            if ($media) {
                $node->set($field_name, ['target_id' => $media->id()]);
                \Drupal::logger('shared_content')->info('Created @type media @mid for node @title from @url.', [
                    '@type' => $type,
                    '@mid' => $media->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $image_url,
                ]);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error processing @type media for node @title: @message', [
                '@type' => $type,
                '@title' => $node->getTitle(),
                '@message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Creates or reuses a remote_video media entity on a node field.
     *
     * remote_video is a core Media bundle whose canonical field is
     * field_media_oembed_video — that's where the YouTube/Vimeo URL
     * lives. We dedup across the site rather than per node: if a
     * remote_video media for this URL already exists anywhere, reuse it.
     * Keeps the media library clean instead of accumulating duplicates
     * each time the same video appears on multiple articles.
     *
     * @param \Drupal\node\Entity\Node $node
     * @param string $video_url
     * @param string $field_name
     * @param string $poster_url
     *   Optional editorial poster image URL from the feed. Applied as the
     *   media thumbnail when the media is newly created.
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
                $existing = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
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
                \Drupal::logger('shared_content')->info('Set remote_video media @mid for node @title from @url.', [
                    '@mid' => $media->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $video_url,
                ]);
            }

            // Apply the editorial poster to field_video_thumbnail on every
            // path, including when the video media already existed (force
            // refresh) or was reused from the migration or another article.
            // applyVideoPoster() is idempotent: it no-ops when
            // field_video_thumbnail already references this poster.
            if ($poster_url !== '') {
                $this->applyVideoPoster($media, $poster_url);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error setting remote_video for node @title: @message', [
                '@title' => $node->getTitle(),
                '@message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extracts the editorial poster image URL from the source <Video> blob.
     *
     * The <Video> section embeds an <img> with a srcset of absolute styled
     * derivatives and a root-relative src fallback. Prefer the highest
     * resolution srcset candidate; failing that, reconstruct an absolute URL
     * from the src's /sites/{host}/files/ segment. Returns '' when absent.
     *
     * @param string $html
     *
     * @return string
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

        if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $img_tag, $sm)) {
            return $this->absolutizePosterUrl(trim($sm[1]));
        }

        return '';
    }

    /**
     * Resolves a poster image URL to an absolute form.
     *
     * Absolute and protocol-relative URLs pass through. Root-relative Drupal
     * file paths carry the originating host in their /sites/{host}/files/
     * segment, so an absolute URL is reconstructed from that. Anything else
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
     * remote_video media auto-fetch the provider thumbnail into the base
     * thumbnail field, and the oembed_thumbnail formatter only uses that as a
     * YouTube onerror fallback. The editorial poster must instead go into
     * field_video_thumbnail — a reference to an image media — which the
     * formatter shows as the primary thumbnail. This mirrors what the
     * artsci_d10_remote_video_thumbnail migration populates, so feed-sourced
     * and migrated videos display the same way. The poster is wrapped in an
     * image media and referenced; the base thumbnail is left as the provider
     * image. Dedup is by the url_hash in the referenced image media's name.
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
            $storage = \Drupal::entityTypeManager()->getStorage('media');

            // If field_video_thumbnail already references an image media for
            // this exact poster, nothing to do. Resolve through target_id +
            // load() rather than ->entity, which can return NULL for a freshly
            // referenced media in some load contexts and would defeat dedup.
            $current_id = $media->get('field_video_thumbnail')->target_id;
            if ($current_id) {
                $current = $storage->load($current_id);
                if ($current && $this->mediaMatchesUrlHash($current, $url_hash)) {
                    return;
                }
            }

            // Reuse an existing poster image media for this hash site-wide
            // rather than creating a duplicate. Dedup on the name's
            // '#{url_hash}' suffix, matching createMediaImageWithType().
            $found = $storage->getQuery()
                ->accessCheck(FALSE)
                ->condition('bundle', 'image')
                ->condition('name', '%#' . $url_hash, 'LIKE')
                ->sort('mid')
                ->range(0, 1)
                ->execute();
            $image_media = !empty($found)
                ? $storage->load(reset($found))
                : $this->createMediaImageWithType($poster_url, $url_hash, $media->label(), 'video_poster');
            if (!$image_media) {
                return;
            }

            // Only write + save when the reference actually changes.
            if ((string) $current_id !== (string) $image_media->id()) {
                $media->set('field_video_thumbnail', ['target_id' => $image_media->id()]);
                $media->save();
                \Drupal::logger('shared_content')->info('Applied feed poster thumbnail media @img to remote_video media @mid.', [
                    '@img' => $image_media->id(),
                    '@mid' => $media->id(),
                ]);
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error applying poster to remote_video media @mid: @message', [
                '@mid' => $media->id() ?? 'new',
                '@message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Maps the article SoundCloud embed onto field_soundcloud_media.
     *
     * <soundCloud> (capital C) carries a rendered SoundCloud player
     * <iframe>; the real track URL lives in the player's url= query
     * parameter. Mirrors SharedContentFieldMapper::mapArticleSoundcloud()
     * so import and refresh agree.
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
     * Creates or reuses a soundcloud media entity on a node field.
     *
     * Mirrors setRemoteVideoMedia(): soundcloud is a Media bundle whose
     * canonical field is field_media_soundcloud. Dedup is site-wide so the
     * same track shared across articles reuses one media row.
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

            if ($current_media
                && $current_media->bundle() === 'soundcloud'
                && $current_media->hasField('field_media_soundcloud')
                && (string) $current_media->get('field_media_soundcloud')->value === $sc_url) {
                return;
            }

            $existing = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
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
            \Drupal::logger('shared_content')->info('Set soundcloud media @mid for node @title from @url.', [
                '@mid' => $media->id(),
                '@title' => $node->getTitle(),
                '@url' => $sc_url,
            ]);
        }
        catch (\Exception $e) {
            \Drupal::logger('shared_content')->error('Error setting soundcloud for node @title: @message', [
                '@title' => $node->getTitle(),
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
     * player URLs to the canonical vimeo.com form. Returns '' when none is
     * present so the caller falls back to the still header image.
     *
     * @param string $html
     *
     * @return string
     */
    protected function extractVideoUrl($html) {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        $src = $html;
        if (preg_match('/<iframe[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) {
            $src = $m[1];
        }

        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:embed|v)/|youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_\-]{6,})#i', $src, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $src, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }

        return '';
    }

    /**
     * Extracts a SoundCloud track/playlist URL from the <soundCloud> blob.
     *
     * The feed stores the player as an <iframe> whose real track URL lives
     * in the player's url= query parameter (e.g.
     * https://api.soundcloud.com/tracks/316458048). Falls back to a bare
     * soundcloud URL, and returns '' when nothing usable is present.
     *
     * @param string $value
     *
     * @return string
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
 * Maps the CV file to field_person_file_upload (media:file reference).
 *
 * Parallel to mapHeadshot() but for documents rather than images.
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
    // not as a bare URL. Extract the href out of the first anchor.
    $raw = trim((string) $xml->cv);
    if ($raw === '') {
        return;
    }
    $cv_url = $this->extractUrl($raw);
    if ($cv_url === '') {
        \Drupal::logger('shared_content')->debug('CV value on node @title had no extractable URL; skipping.', [
            '@title' => $node->getTitle(),
        ]);
        return;
    }

    $url_hash = substr(md5($cv_url), 0, 8);

    try {
        $current_media = $node->get('field_person_file_upload')->entity;

        // Dedup on media name suffix — filename comparison is too fragile
        // because saveData() sanitization can mangle the hash token.
        if ($current_media && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
            \Drupal::logger('shared_content')->debug('CV unchanged for node @title, skipping.', [
                '@title' => $node->getTitle(),
            ]);
            return;
        }

        if ($current_media) {
            $updated = $this->updateMediaFile($current_media, $cv_url, $url_hash, $node->getTitle());
            if ($updated) {
                \Drupal::logger('shared_content')->info('Updated CV media @mid for node @title from @url.', [
                    '@mid' => $updated->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $cv_url,
                ]);
            }
            return;
        }

        $media = $this->createMediaFile($cv_url, $url_hash, $node->getTitle());
        if ($media) {
            $node->set('field_person_file_upload', ['target_id' => $media->id()]);
            \Drupal::logger('shared_content')->info('Created CV media @mid for node @title from @url.', [
                '@mid' => $media->id(),
                '@title' => $node->getTitle(),
                '@url' => $cv_url,
            ]);
        }
    }
    catch (\Exception $e) {
        \Drupal::logger('shared_content')->error('Error processing CV for node @title: @message', [
            '@title' => $node->getTitle(),
            '@message' => $e->getMessage(),
        ]);
    }
}

/**
 * Returns TRUE if the media entity's name already carries this url_hash.
 *
 * Media name format for downloaded source media is
 * "{title} {type} #{url_hash}".
 */
protected function mediaMatchesUrlHash(Media $media, $url_hash) {
    $name = (string) $media->get('name')->value;
    return $name !== '' && str_ends_with($name, '#' . $url_hash);
}

/**
 * Extracts a usable URL from raw XML text that may be HTML-wrapped.
 *
 * Source feeds often wrap links in HTML fragments — e.g. <div><a href="…">
 * Download…</a></div> — instead of emitting bare URLs. We want just the
 * href. Returns an empty string if no URL is extractable.
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

    // HTML-encoded ampersands are valid in the feed but break parse_url /
    // Guzzle downstream. Decode first.
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
 * Downloads a CV URL and saves it as a File entity.
 *
 * Parallel to createFileEntity() but for documents — no image/* content
 * type check, separate subdirectory, same filename hashing for dedup.
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
    try {
        /** @var \GuzzleHttp\ClientInterface $client */
        $client = \Drupal::httpClient();
        $response = $client->get($cv_url, [
            'timeout' => 30,
            'http_errors' => FALSE,
        ]);

        if ($response->getStatusCode() !== 200) {
            \Drupal::logger('shared_content')->warning('HTTP @status fetching CV @url.', [
                '@status' => $response->getStatusCode(),
                '@url' => $cv_url,
            ]);
            return NULL;
        }

        $data = $response->getBody()->getContents();
        if ($data === '') {
            return NULL;
        }
    }
    catch (\Exception $e) {
        \Drupal::logger('shared_content')->error('Error downloading CV from @url: @message', [
            '@url' => $cv_url,
            '@message' => $e->getMessage(),
        ]);
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
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    try {
        $uri = $file_system->saveData(
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
        \Drupal::logger('shared_content')->error('Failed to save CV file @filename: @message', [
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
 * Maps the book cover image to a media entity.
 *
 * Mirrors the person headshot path: download the cover, wrap it in a
 * media:image, and reference it from field_image. Dedup uses the media
 * entity's name suffix ("#{url_hash}") rather than the on-disk filename,
 * matching mapCv() and the field mapper's mapBookCover() — that way a
 * book imported here and then refreshed by SharedContentFieldMapper agree
 * on whether the cover has changed.
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

    // <cover> in the sample feed is a bare URL, but extractUrl() also
    // tolerates HTML-wrapped values in case the source feed format shifts
    // the way <cv> already has.
    $image_url = $this->extractUrl((string) $xml->cover);
    if ($image_url === '') {
        return;
    }

    $url_hash = substr(md5($image_url), 0, 8);

    try {
        $current_media = $node->get('field_image')->entity;

        if ($current_media && $this->mediaMatchesUrlHash($current_media, $url_hash)) {
            \Drupal::logger('shared_content')->debug('Book cover unchanged for node @title, skipping.', [
                '@title' => $node->getTitle(),
            ]);
            return;
        }

        if ($current_media) {
            $media = $this->updateMediaImageWithType($current_media, $image_url, $url_hash, $node->getTitle(), 'cover');
            if ($media) {
                \Drupal::logger('shared_content')->info('Updated book cover media @mid for node @title from @url.', [
                    '@mid' => $media->id(),
                    '@title' => $node->getTitle(),
                    '@url' => $image_url,
                ]);
            }
            return;
        }

        $media = $this->createMediaImageWithType($image_url, $url_hash, $node->getTitle(), 'cover');
        if ($media) {
            $node->set('field_image', ['target_id' => $media->id()]);
            \Drupal::logger('shared_content')->info('Created book cover media @mid for node @title from @url.', [
                '@mid' => $media->id(),
                '@title' => $node->getTitle(),
                '@url' => $image_url,
            ]);
        }
    }
    catch (\Exception $e) {
        \Drupal::logger('shared_content')->error('Error processing book cover for node @title: @message', [
            '@title' => $node->getTitle(),
            '@message' => $e->getMessage(),
        ]);
    }
}

/**
 * Maps the book author field.
 *
 * In D11 field_book_author is an entity reference to person nodes filtered
 * to person_type=author. In the migration, this is resolved through the
 * artsci_d10_author migration map. For ongoing shared content sync we
 * don't have that map, so we look up the local person by reconstructing
 * what the source's faculty_staff feed URL would be for the remote
 * authornid — see findLocalPersonByRemoteId().
 *
 * If the lookup fails (no local person, no reachable remote), we fall back
 * to plain text storage on field_book_author_name when that field exists.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node to update.
 * @param \SimpleXMLElement $xml
 *   The XML element containing the data.
 * @param string $shared_url
 *   The shared content URL (used to extract base URL).
 */
protected function mapBookAuthor(Node $node, \SimpleXMLElement $xml, $shared_url) {
    // First try to link to an existing person node via authornid.
    if (isset($xml->authornid) && $node->hasField('field_book_author')) {
        $author_nid = (int) $xml->authornid;
        if ($author_nid > 0) {
            $local_author = $this->findLocalPersonByRemoteId($xml, $author_nid, $shared_url);

            if ($local_author) {
                $node->set('field_book_author', ['target_id' => $local_author->id()]);
                return;
            }
        }
    }

    // Fallback: store author name as text in a separate field if available.
    $author_name = '';
    if (isset($xml->facultyAndStaff) && !empty((string) $xml->facultyAndStaff)) {
        $author_name = (string) $xml->facultyAndStaff;
    }
    elseif (isset($xml->author) && !empty((string) $xml->author)) {
        $author_name = (string) $xml->author;
    }

    if (!empty($author_name)) {
        if ($node->hasField('field_book_author_name')) {
            $node->set('field_book_author_name', $author_name);
        }
        \Drupal::logger('shared_content')->debug('Book author "@name" could not be linked to a person node for book @title.', [
            '@name' => $author_name,
            '@title' => $node->getTitle(),
        ]);
    }
}

/**
 * Finds a local person node that was imported from a remote source.
 *
 * On D11 source sites, what was the D10 "author" content type is now
 * person+person_type=author, served at /xml/faculty_staff/{nid}/rss.xml
 * (per getContentType()'s Person → faculty_staff mapping). So a local
 * person whose field_shared_content_xml matches that constructed URL was
 * imported from the same remote author and is the right book author target.
 *
 * @param \SimpleXMLElement $xml
 *   The XML element (used to extract base URL).
 * @param int $remote_nid
 *   The remote node ID.
 * @param string $shared_url
 *   The shared content URL (fallback for base URL extraction).
 *
 * @return \Drupal\node\Entity\Node|null
 *   The local person node, or NULL if not found.
 */
protected function findLocalPersonByRemoteId(\SimpleXMLElement $xml, $remote_nid, $shared_url) {
    // Try to extract the base URL from externalURL or shared_url.
    $base_url = '';
    if (isset($xml->externalURL)) {
        $parsed = parse_url((string) $xml->externalURL);
        if ($parsed && isset($parsed['scheme']) && isset($parsed['host'])) {
            $base_url = $parsed['scheme'] . '://' . $parsed['host'];
        }
    }

    if (empty($base_url)) {
        $parsed = parse_url($shared_url);
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
    $query = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'person')
        ->condition('field_shared_content_xml', $person_shared_url)
        ->range(0, 1);

    $nids = $query->execute();

    if (!empty($nids)) {
        return \Drupal::entityTypeManager()->getStorage('node')->load(reset($nids));
    }

    return NULL;
}

/**
 * Creates a new media image entity from a URL with type support.
 *
 * Used by mapBookCover (type='cover'). Embeds the url_hash in the media
 * entity name so mediaMatchesUrlHash() can dedup on subsequent runs even
 * if file system sanitization mangled the on-disk filename.
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

    // Name ends in '#{url_hash}' so mediaMatchesUrlHash() can dedup later.
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

    // Update media entity. Embed url_hash in the name so future runs dedup
    // via mediaMatchesUrlHash() instead of fragile filename comparison.
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
     * Gets or creates a taxonomy term by name.
     *
     * @param string $name
     *   The term name.
     * @param string $vid
     *   The vocabulary machine name.
     *
     * @return Term|null
     *   The term entity, or NULL if name was empty.
     */
    protected function getOrCreateTerm($name, $vid) {
        $name = trim((string) $name);
        if (empty($name)) {
            return NULL;
        }

        $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
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

        \Drupal::logger('shared_content')->info('Created new @vid term: @name', [
            '@vid' => $vid,
            '@name' => $name,
        ]);

        return $term;
    }

    /**
     * Adds a term to a taxonomy reference field if not already present.
     *
     * @param \Drupal\node\Entity\Node $node
     *   The node entity.
     * @param string $field_name
     *   The field machine name.
     * @param Term|null $term
     *   The term to add.
     */
    protected function addTermToField(Node $node, $field_name, $term) {
        if (!$term || !$node->hasField($field_name)) {
            return;
        }

        $existing_values = $node->get($field_name)->getValue();
        $existing_tids = array_column($existing_values, 'target_id');

        if (!in_array($term->id(), $existing_tids, false)) {
            $existing_values[] = ['target_id' => $term->id()];
            $node->set($field_name, $existing_values);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface|null $account = NULL, $return_as_object = FALSE) {
        // Check if the user has the 'administer aggregator' permission.
        $access_result = $account->hasPermission('access content');
        return $return_as_object ? AccessResult::allowedIf($access_result) : $access_result;
    }

}
