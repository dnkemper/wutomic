<?php

namespace Drupal\artsci_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

/**
 * Configure Artsci Core settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'artsci_core_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['artsci_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('artsci_core.settings');
    $node_types = $this->replicateDisableNodeTypes();

    $form['markup'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>These settings allow you to configure certain aspects of this website.</p>'),
    ];

    $form['gtag'] = [
      '#type' => 'fieldset',
      '#title' => 'Google Tag Manager',
      '#collapsible' => FALSE,
    ];

    $form['gtag']['artsci_core_gtag'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Google Tag Manager Functionality'),
      '#default_value' => $config->get('artsci_core.gtag'),
      '#description' => $this->t('If checked, and Google Tag Manager containers are configured, container snippets will be inserted and loaded on the website.'),
      '#size' => 60,
    ];

    $form['gtag']['artsci_core_campus_gtm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include Campus-Wide GTM Container'),
      '#default_value' => $config->get('artsci_core.campus_gtm'),
      '#description' => $this->t('If checked, the campus-wide UI GTM container snippet will be inserted and loaded on the website.'),
      '#size' => 60,
    ];

    $form['ga'] = [
      '#type' => 'fieldset',
      '#title' => 'Google Analytics',
      '#collapsible' => FALSE,
    ];

    $form['ga']['artsci_core_ga'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include Google Analytics Functionality'),
      '#default_value' => $config->get('artsci_core.ga'),
      '#description' => $this->t('If checked, and if Google Analytics is configured with a tracking ID, Google Analytics tracking code will be inserted and loaded on the website.'),
      '#size' => 60,
    ];
    $form['replication'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Replication'),
      '#open' => TRUE,
    ];

    $form['replication']['replicate_allowed'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#size' => count($node_types),
      '#title' => $this->t('Allowed Content Types for Cloning'),
      '#default_value' => $config->get('replicate_allowed'),
      '#options' => $node_types,
      '#description' => $this->t('Select the available content types that can be "cloned".'),
    ];
    return $form;
  }
  /**
   * Simple function to return a FAPI select options array.
   */
  public function replicateDisableNodeTypes() {
    $node_types = NodeType::loadMultiple();
    $options = [];
    foreach ($node_types as $node_type) {
      $options[$node_type->id()] = $node_type->label();
    }
    asort($options);
    return $options;
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('artsci_core.settings')
      ->set('artsci_core.gtag', $form_state->getValue('artsci_core_gtag'))
      ->set('artsci_core.campus_gtm', $form_state->getValue('artsci_core_campus_gtm'))
      ->set('artsci_core.ga', $form_state->getValue('artsci_core_ga'))
      ->set('replicate_allowed', $form_state->getValue('replicate_allowed'))
      ->save();
    parent::submitForm($form, $form_state);

    // Clear cache.
    drupal_flush_all_caches();
  }

}
