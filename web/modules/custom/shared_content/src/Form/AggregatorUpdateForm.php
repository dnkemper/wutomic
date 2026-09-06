<?php

namespace Drupal\shared_content\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\shared_content\Service\SharedContentFeedRefresherForce;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to update all aggregator feeds.
 *
 * Uses the Force refresher so that:
 *   - All nodes are refreshed regardless of field_last_fetch age
 *   - field_content_hash is still used to skip truly unchanged content
 *   - Nodes whose source returns 404 or empty XML are auto-deleted.
 */
class AggregatorUpdateForm extends FormBase {

  /**
   * The force feed refresher service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce
   */
  protected $feedRefresherForce;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new AggregatorUpdateForm.
   */
  public function __construct(
    SharedContentFeedRefresherForce $feedRefresherForce,
    MessengerInterface $messenger,
  ) {
    $this->feedRefresherForce = $feedRefresherForce;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('shared_content.feed_refresher_force'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aggregator_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['update_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh Feeds'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // force_all = TRUE bypasses field_last_fetch so every node is checked
    // immediately regardless of when it was last fetched.
    // field_content_hash is still used to skip content that hasn't changed,
    // so unchanged nodes are updated quickly (just a hash comparison + save).
    $this->feedRefresherForce->run(TRUE);
    $this->messenger->addMessage($this->t(
      'All shared content feeds have been refreshed. Nodes with deleted or empty sources have been removed.'
    ));
  }

}
