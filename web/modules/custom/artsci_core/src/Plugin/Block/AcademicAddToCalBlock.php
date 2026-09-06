<?php

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\addtocal_augment\Plugin\DateAugmenter\AddToCal;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Add to Calendar' block for event nodes.
 *
 * @Block(
 *   id = "academic_addtocal_block",
 *   admin_label = @Translation("Academic Add to Calendar"),
 *   category = @Translation("Artsci"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"), required = FALSE)
 *   }
 * )
 */
class AcademicAddToCalBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  if (!$node instanceof NodeInterface || $node->bundle() !== 'academic_calendar_event') {
    return $build;
  }

  if (!$node->hasField('field_start_date') || $node->get('field_start_date')->isEmpty()) {
    return $build;
  }

  // Get the date value. field_start_date is a plain date-only datetime
  // field (datetime_type: date), NOT a smart_date field — it has only a
  // 'value' column stored as a 'Y-m-d' string, with no time, end_value, or
  // duration. Academic calendar events are therefore always single all-day
  // events.
  $date_value = $node->get('field_start_date')->value;
  $start = DrupalDateTime::createFromFormat(DateTimeItemInterface::DATE_STORAGE_FORMAT, $date_value);
  if (!$start instanceof DrupalDateTime) {
    return $build;
  }
  // createFromFormat seeds unspecified time parts from "now"; pin to midnight.
  $start->setTime(0, 0, 0);

  $is_all_day = TRUE;

  // Mirror the all-day shape the event block feeds the augmenter: a single
  // calendar day spanning 1439 minutes (00:00–23:59).
  $end = clone $start;
  $end->modify('+1439 minutes');

  // Format date for display.
  $date_formatted = $start->format('l, F j, Y'); // "Friday, September 19, 2025"

  // All-day events have no time to display.
  $time_formatted = NULL;

  // Build the addtocal output using the augmenter.
  $output = [];
  $addtocal_plugin = AddToCal::create(
    \Drupal::getContainer(),
    [],
    'addtocal',
    ['id' => 'academic_addtocal', 'label' => 'Academic Add to Calendar']
  );

  $options = [
    'entity' => $node,
    'allday' => $is_all_day,
    'settings' => [
      'event_title' => '[node:title]',
      'description' => '',
      'location' => '',
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
    '#theme' => 'academic_addtocal_block',
    '#date' => $date_formatted,
    '#time' => $time_formatted,
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
