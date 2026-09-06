<?php

declare(strict_types=1);

namespace Drupal\washu_calendar_subscription\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the academic calendar's "current semester".
 *
 * The chosen semester is what the calendar defaults to on load and where the
 * front-end semester stepper starts. Editors update this each term; no code
 * change or deployment is required.
 */
class AcademicCalendarSettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['washu_calendar_subscription.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'washu_calendar_subscription_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('washu_calendar_subscription.settings');

    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Set the term the academic calendar shows by default and where the semester stepper starts. Visitors can still step to other semesters that have events.'),
    ];

    $form['current_season'] = [
      '#type' => 'select',
      '#title' => $this->t('Current season'),
      '#options' => $this->getFieldOptions('field_season'),
      '#default_value' => $config->get('current_season'),
      '#required' => TRUE,
    ];

    $form['current_year'] = [
      '#type' => 'select',
      '#title' => $this->t('Current year'),
      '#options' => $this->getFieldOptions('field_semester_year'),
      '#default_value' => $config->get('current_year'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('washu_calendar_subscription.settings')
      ->set('current_season', $form_state->getValue('current_season'))
      ->set('current_year', $form_state->getValue('current_year'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds a select options array from a list_string field's allowed values.
   *
   * @param string $field_name
   *   The field machine name on the node entity type.
   *
   * @return array
   *   An options array keyed by stored value.
   */
  protected function getFieldOptions(string $field_name): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load('node.' . $field_name);

    if (!$storage) {
      return $options;
    }

    // Drupal normalizes list_string allowed values to a flat value => label
    // map at runtime, but config on disk uses a list of value/label maps.
    // Handle both shapes.
    foreach ((array) $storage->getSetting('allowed_values') as $key => $value) {
      if (is_array($value) && isset($value['value'])) {
        $options[$value['value']] = $value['label'] ?? $value['value'];
      }
      else {
        $options[$key] = $value;
      }
    }

    return $options;
  }

}
