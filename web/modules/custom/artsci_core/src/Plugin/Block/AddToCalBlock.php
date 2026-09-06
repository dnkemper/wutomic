<?php

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\addtocal_augment\Plugin\DateAugmenter\AddToCal;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Add to Calendar' block for event nodes.
 *
 * @Block(
 *   id = "addtocal_block",
 *   admin_label = @Translation("Add to Calendar"),
 *   category = @Translation("Artsci"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"), required = FALSE)
 *   }
 * )
 */
class AddToCalBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
/**
 * {@inheritdoc}
 */
public function build() {
  $build = [];

  // Try to get node from context first.
  try {
    $node = $this->getContextValue('node');
  }
  catch (\Exception $e) {
    $node = NULL;
  }

  // Fall back to route.
  if (!$node instanceof NodeInterface) {
    $node = $this->routeMatch->getParameter('node');
  }

  // Only proceed for event nodes with field_event_when.
  if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
    return $build;
  }

  if (!$node->hasField('field_event_when') || $node->get('field_event_when')->isEmpty()) {
    return $build;
  }

  // Get the smartdate field value.
  $when_field = $node->get('field_event_when')->first();
  $start_timestamp = $when_field->get('value')->getValue();
  $end_timestamp = $when_field->get('end_value')->getValue();
  $duration = $when_field->get('duration')->getValue() ?? 0;

  // Check if all day (duration = 1439 minutes = 23:59).
  $is_all_day = ($duration == 1439);

  // Create DrupalDateTime objects.
  $start = DrupalDateTime::createFromTimestamp($start_timestamp);
  $end = DrupalDateTime::createFromTimestamp($end_timestamp);

  // Format date/time for display.
  $date_formatted = $start->format('l, F j, Y'); // "Friday, September 19, 2025"
  
  if ($is_all_day) {
    $time_formatted = NULL;
  }
  else {
    $time_formatted = $start->format('g:i A') . ' – ' . $end->format('g:i A');
  }

  // Get location.
// Get location from address field.
$location = NULL;
$location_parts = [];

if ($node->hasField('field_event_location') && !$node->get('field_event_location')->isEmpty()) {
  $address = $node->get('field_event_location')->first()->getValue();
  
  // Organization/venue name (e.g., "Holmes Lounge").

  
  // Street address.
  if (!empty($address['address_line1'])) {
    $location_parts[] = $address['address_line1'];
  }
  if (!empty($address['address_line2'])) {
    $location_parts[] = $address['address_line2'];
  }
  
  // City, State ZIP.
  $city_state = [];
  if (!empty($address['locality'])) {
    $city_state[] = $address['locality'];
  }
  if (!empty($address['administrative_area'])) {
    $city_state[] = $address['administrative_area'];
  }
  if (!empty($city_state)) {
    $city_state_str = implode(', ', $city_state);
    if (!empty($address['postal_code'])) {
      $city_state_str .= ' ' . $address['postal_code'];
    }
    $location_parts[] = $city_state_str;
  }
    if (!empty($address['organization'])) {
    // $location_parts[] = $address['organization'];
        $location_party = $address['organization'];
  }
  
  $location = !empty($location_parts) ? implode(', ', $location_parts) : NULL;
  $location = $location . (!empty($location_party) ? ' | ' . $location_party . ' ' : '');
}

  // Get contact email.
  $contact_email = NULL;
  if ($node->hasField('field_event_contact') && !$node->get('field_event_contact')->isEmpty()) {
    $contact_email = $node->get('field_event_contact')->getString();
  }

  // Build the addtocal output using the augmenter.
  $output = [];
  $addtocal_plugin = AddToCal::create(
    \Drupal::getContainer(),
    [],
    'addtocal',
    ['id' => 'addtocal', 'label' => 'Add to Calendar']
  );

  $options = [
    'entity' => $node,
    'allday' => $is_all_day,
    'settings' => [
      'event_title' => '[node:title]',
      'description' => '[node:body]',
      'location' => '[node:field_event_location]',
      'label' => '',
      'past_events' => TRUE,
      'icons' => FALSE,
      'max_desc' => 200,
      'ellipsis' => TRUE,
      'target' => '',
      'retain_spacing' => FALSE,
      'ignore_timezone_if_UTC' => TRUE,
    ],
  ];

  $addtocal_plugin->augmentOutput($output, $start, $end, $options);

  $build = [
    '#theme' => 'addtocal_block',
    '#date' => $date_formatted,
    '#time' => $time_formatted,
    '#location' => $location,
    '#contact_email' => $contact_email,
    '#addtocal' => $output['addtocal'] ?? NULL,
    '#cache' => [
      'contexts' => ['route'],
      'tags' => $node->getCacheTags(),
    ],
  ];

  return $build;
}

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    try {
      $node = $this->getContextValue('node');
    }
    catch (\Exception $e) {
      $node = $this->routeMatch->getParameter('node');
    }

    if ($node instanceof NodeInterface) {
      return Cache::mergeTags(parent::getCacheTags(), $node->getCacheTags());
    }

    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
