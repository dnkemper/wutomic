<?php

namespace Drupal\shared_content\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually trigger shared content feed updates.
 */
class SharedContentUpdateForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a SharedContentUpdateForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shared_content_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'item',
      '#markup' => '<p>Click Update Feeds to refresh each of the shared content feeds.</p>',
    ];

    $form['update_feeds'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update All Feeds'),
      '#submit' => ['::updateAllFeeds'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $feed_query = $this->database->select('aggregator_feed', 'af')
      ->fields('af', ['fid'])
      ->execute();

    $feed_ids = $feed_query->fetchAll();

    if (!empty($feed_ids)) {
      $feeds = $this->entityTypeManager->getStorage('aggregator_feed')->loadMultiple(array_column($feed_ids, 'fid'));

      foreach ($feeds as $feed) {
        if ($feed) {
          // Trigger the feed refresh.
          $feed->update();
        }
      }

      $this->messenger()->addMessage($this->t('Feeds updated successfully.'));
    }
    else {
      $this->messenger()->addMessage($this->t('No feeds found for Shared Content category.'), 'warning');
    }

  }

  /**
   * Submit handler for updating all aggregator feeds.
   */
  public function updateAllFeeds(array &$form, FormStateInterface $form_state) {
    // Load all aggregator feeds and update them.
    $feeds = $this->entityTypeManager->getStorage('aggregator_feed')->loadMultiple();
    foreach ($feeds as $feed) {
      $feed->refreshItems();
    }

    // Notify the user.
    $this->messenger()->addMessage($this->t('All aggregator feeds have been updated.'));
  }

}
