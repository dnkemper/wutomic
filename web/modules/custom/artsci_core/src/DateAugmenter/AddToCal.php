<?php

namespace Drupal\artsci_core\Plugin\DateAugmenter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Utility\Token;
use Drupal\date_augmenter\DateAugmenter\DateAugmenterPluginBase;
use Drupal\date_augmenter\Plugin\PluginFormTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Date Augmenter plugin to inject Add to Calendar links.
 *
 * @DateAugmenter(
 *   id = "addtocal",
 *   label = @Translation("Add To Calendar Links"),
 *   description = @Translation("Adds links to add an events dates to a user's preferred calendar."),
 *   weight = 0
 * )
 */
class AddToCal extends DateAugmenterPluginBase implements PluginFormInterface, ContainerFactoryPluginInterface {

  use PluginFormTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token|null
   */
  protected ?Token $token;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    ConfigFactoryInterface $config_factory,
    ?Token $token = NULL,
  ) {
    $configuration += $this->defaultConfiguration();
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $plugin = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->has('token') ? $container->get('token') : NULL
    );
    $translation = $container->get('string_translation');
    $plugin->setStringTranslation($translation);
    return $plugin;
  }

  /**
   * Augments the output with Add to Calendar links.
   *
   * @param array $output
   *   The existing render array, to be augmented, passed by reference.
   * @param \Drupal\Core\Datetime\DrupalDateTime $start
   *   The object which contains the start time.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $end
   *   The optional object which contains the end time.
   * @param array $options
   *   An array of options to further guide output.
   */
  public function augmentOutput(array &$output, DrupalDateTime $start, ?DrupalDateTime $end = NULL, array $options = []): void {
    $data = $this->buildLinks($output, $start, $end, $options);
    if (!$data) {
      return;
    }

    $config = $options['settings'] ?? $this->getConfiguration();
    $ical_render = implode('%0D%0A', $data['ical']);
    $google_base = 'https://www.google.com/calendar/r/eventedit?';
    $google_render = $google_base . http_build_query($data['google']);
    $id = Html::getUniqueId($data['google']['text']);

    $output['addtocal'] = [
      '#theme' => 'addtocal_links',
      '#label' => $config['label'] ?? $this->t('Add to Calendar'),
      '#google' => $google_render,
      '#ical' => $ical_render,
      '#id' => $id,
    ];

    if (isset($config['target']) && $config['target'] === 'modal') {
      $output['addtocal']['#theme'] = 'addtocal_links__modal';
      $output['addtocal']['#attached']['library'][] = 'core/drupal.dialog.ajax';
      $output['addtocal']['#attached']['library'][] = 'artsci_core/modal';
    }
  }

  /**
   * Gets the current date.
   *
   * Isolated for overriding in unit testing.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime
   *   The current date.
   *
   * @see \Drupal\Tests\artsci_core\Unit\TestAddToCal
   */
  protected function getCurrentDate(): DrupalDateTime {
    return new DrupalDateTime();
  }

  /**
   * Builds a prepared array of data for output.
   *
   * @param array $output
   *   The existing render array, to be augmented.
   * @param \Drupal\Core\Datetime\DrupalDateTime $start
   *   The object which contains the start time.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $end
   *   The optional object which contains the end time.
   * @param array $options
   *   An array of options to further guide output.
   *
   * @return array|null
   *   The prepared data array or NULL if unable to build links.
   */
  public function buildLinks(array $output, DrupalDateTime $start, ?DrupalDateTime $end = NULL, array $options = []): ?array {
    $config = $options['settings'] ?? $this->getConfiguration();

    if (empty($config['title']) && !isset($options['entity'])) {
      return NULL;
    }

    $end_fallback = $end ?? $start;
    $now = $this->getCurrentDate();

    // For a recurring date, determine if the last instance is in the past.
    $upcoming_instance = FALSE;
    if (!empty($options['repeats']) && (empty($options['ends']) || $options['ends'] > $now)) {
      $upcoming_instance = TRUE;
    }

    if (!$upcoming_instance && $end_fallback < $now && !$config['past_events']) {
      return NULL;
    }

    $entity = $options['entity'] ?? NULL;
    if (!$end) {
      $end = $start;
    }

    // Determine timezone.
    if ($start instanceof DrupalDateTime && $tz = $start->getTimezone()) {
      $timezone = $tz->getName();
    }
    else {
      $tz = $this->configFactory->get('system.date')->get('timezone');
      $timezone = $tz['default'];
    }

    // Format dates.
    if (isset($options['allday']) && $options['allday']) {
      $start_formatted = $start->format("Ymd", $timezone);
      $end->add(new \DateInterval('P1D'));
      $end_formatted = $end->format("Ymd", $timezone);
    }
    else {
      $date_format = "Ymd\\THi00";
      if ($timezone) {
        $date_format = "Ymd\\THi00";
      }
      else {
        $date_format .= "\\Z";
      }
      $start_formatted = $start->format($date_format, $timezone);
      $end_formatted = $end->format($date_format, $timezone);
    }

    // Build label.
    if (!empty($config['title'])) {
      $label = $this->parseField($config['title'], $entity);
    }
    else {
      $label = $this->parseField($entity->label(), NULL);
    }

    // Build description.
    $description = NULL;
    if (!empty($config['description'])) {
      $description = $this->parseField($config['description'], $entity, TRUE);
      $max_length = $config['max_desc'] ?? 60;
      if ($max_length) {
        $description = trim(substr($description, 0, $max_length)) . '...';
      }
    }

    // Build location.
    $location = NULL;
    if (!empty($config['location'])) {
      $location = $this->parseField($config['location'], $entity, TRUE);
    }

    $uuid = $entity?->uuid() ?? Html::getUniqueId($label);

    // Build iCal link.
    $ical_link = ['data:text/calendar;charset=utf8,BEGIN:VCALENDAR'];
    $ical_link[] = 'PRODID:' . $this->configFactory->get('system.site')->get('name');

    $google_link = [];
    if ($timezone) {
      $google_link['ctz'] = $timezone;
    }

    $ical_link[] = 'VERSION:2.0';
    $ical_link[] = 'BEGIN:VEVENT';
    $ical_link[] = 'UID:' . $uuid;

    // Title.
    $ical_link[] = 'SUMMARY:' . $label;
    $google_link['text'] = $label;

    // Dates - as per RFC 2445 4.8.7.2 the DTSTAMP property must be in UTC.
    $now->setTimezone(new \DateTimeZone('UTC'));
    $start->setTimezone(new \DateTimeZone('UTC'));
    $end->setTimezone(new \DateTimeZone('UTC'));

    $ical_link[] = 'DTSTAMP:' . $now->format('Ymd\\THi00\\Z');
    $ical_link[] = 'DTSTART:' . $start->format('Ymd\\THi00\\Z');
    $ical_link[] = 'DTEND:' . $end->format('Ymd\\THi00\\Z');
    $google_link['dates'] = $start_formatted . '/' . $end_formatted;

    // Recurrence.
    if (!empty($options['repeats'])) {
      $ical_link[] = '' . $options['repeats'];
      $google_link['recur'] = $options['repeats'];
    }

    // Description.
    if ($description) {
      $ical_link[] = 'DESCRIPTION:' . $description;
      $google_link['details'] = $description;
    }

    // Location.
    if ($location) {
      $ical_link[] = 'LOCATION:' . $location;
      $google_link['location'] = $location;
    }

    $ical_link[] = 'END:VEVENT';
    $ical_link[] = 'END:VCALENDAR';

    return [
      'ical' => $ical_link,
      'google' => $google_link,
    ];
  }

  /**
   * Manipulates the provided value, checking for tokens and cleaning up.
   *
   * @param string $field_value
   *   The value to manipulate.
   * @param mixed $entity
   *   The entity whose values can be used to replace tokens.
   * @param bool $strip_markup
   *   Whether or not to clean up the output.
   *
   * @return string
   *   The manipulated value, prepared for use in a link href.
   */
  public function parseField(string $field_value, mixed $entity, bool $strip_markup = FALSE): string {
    if ($this->token && $entity) {
      $token_data = [
        $entity->getEntityTypeId() => $entity,
      ];
      $field_value = $this->token->replace($field_value, $token_data);
    }

    if ($strip_markup) {
      // Strip tags. Requires decoding entities, which will be re-encoded later.
      $field_value = strip_tags(html_entity_decode($field_value));

      // Strip out line breaks.
      $field_value = preg_replace('/\n|\r|\t/m', ' ', $field_value);

      // Strip out non-breaking spaces.
      $field_value = str_replace('&nbsp;', ' ', $field_value);
      $field_value = str_replace("\xc2\xa0", ' ', $field_value);

      // Strip out extra spaces.
      $field_value = trim(preg_replace('/\s\s+/', ' ', $field_value));
    }

    return $field_value;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'title' => '',
      'location' => '',
      'description' => '',
      'max_desc' => 60,
      'past_events' => FALSE,
      'label' => $this->t('Add to Calendar'),
      'target' => '',
    ];
  }

  /**
   * Creates configuration fields for the plugin form, or injected directly.
   *
   * @param array $form
   *   The form array.
   * @param array|null $settings
   *   The setting to use as defaults.
   * @param mixed $field_definition
   *   A parameter to define the field being modified. Likely FieldConfig.
   *
   * @return array
   *   The updated form array.
   */
  public function configurationFields(array $form, ?array $settings = NULL, mixed $field_definition = NULL): array {
    if (empty($settings)) {
      $settings = $this->defaultConfiguration();
    }

    $form['label'] = [
      '#title' => $this->t('Links label'),
      '#type' => 'textfield',
      '#default_value' => $settings['label'],
      '#description' => $this->t('Text to prefix the actual add links.'),
    ];

    $form['title'] = [
      '#title' => $this->t('Event title'),
      '#type' => 'textfield',
      '#default_value' => $settings['title'],
      '#description' => $this->t('Optional - if left empty, the entity label will be used. You can use static text or tokens.'),
    ];

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#description' => $this->t('Optional. You can use static text or tokens.'),
      '#default_value' => $settings['location'],
    ];

    $form['description'] = [
      '#title' => $this->t('Event description'),
      '#type' => 'textarea',
      '#default_value' => $settings['description'],
      '#description' => $this->t('Optional. You can use static text or tokens.'),
    ];

    $form['max_desc'] = [
      '#title' => $this->t('Maximum description length'),
      '#type' => 'number',
      '#default_value' => $settings['max_desc'] ?? 60,
      '#description' => $this->t('Trim the description to a specified length. Leave empty or use zero to not trim the value.'),
    ];

    $form['past_events'] = [
      '#title' => $this->t('Show Add to Cal widget for past events?'),
      '#type' => 'checkbox',
      '#default_value' => $settings['past_events'],
    ];

    $form['target'] = [
      '#title' => $this->t('Links display'),
      '#description' => $this->t('Display as a list of links or as a single link that triggers a modal (pop-up).'),
      '#type' => 'select',
      '#default_value' => $settings['target'] ?? '',
      '#options' => [
        '' => $this->t('List of links'),
        'modal' => $this->t('Modal dialog'),
      ],
    ];

    if (function_exists('token_theme') && $field_definition) {
      $type = NULL;
      if (method_exists($field_definition, 'getTargetEntityTypeId')) {
        $type = $field_definition->getTargetEntityTypeId();
      }
      $form['token_tree_link'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => [$type],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $this->configurationFields($form, $this->configuration);
  }

}
