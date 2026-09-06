<?php

namespace Drupal\shared_content\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\shared_content\Service\SharedContentFeedRefresherForce;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for forcing a shared content update.
 */
class ForceUpdateForm extends ConfirmFormBase {

  /**
   * The node to update.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The shared content feed refresher service.
   *
   * @var \Drupal\shared_content\Service\SharedContentFeedRefresherForce
   */
  protected $feedRefresher;

  /**
   * Constructs a ForceUpdateForm object.
   *
   * @param \Drupal\shared_content\Service\SharedContentFeedRefresherForce $feed_refresher
   *   The feed refresher service.
   */
  public function __construct(SharedContentFeedRefresherForce $feed_refresher) {
    $this->feedRefresher = $feed_refresher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('shared_content.feed_refresher_force')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shared_content_force_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $this->node = $node;

    if (!$node || !$node->hasField('field_shared_content_xml') || $node->get('field_shared_content_xml')->isEmpty()) {
      $this->messenger()->addError($this->t('This node does not have a shared content XML source.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $form = parent::buildForm($form, $form_state);

    // Add information about the node.
    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Node Information'),
      '#open' => TRUE,
    ];

    $form['info']['title'] = [
      '#type' => 'item',
      '#title' => $this->t('Title'),
      '#markup' => $node->label(),
    ];

    $form['info']['type'] = [
      '#type' => 'item',
      '#title' => $this->t('Content Type'),
      '#markup' => $node->type->entity->label(),
    ];

    $source_url = $node->get('field_shared_content_xml')->value;
    $form['info']['source'] = [
      '#type' => 'item',
      '#title' => $this->t('XML Source'),
      '#markup' => $source_url,
    ];

    if ($node->hasField('field_last_fetch') && !$node->get('field_last_fetch')->isEmpty()) {
      $last_fetch = $node->get('field_last_fetch')->value;
      $form['info']['last_fetch'] = [
        '#type' => 'item',
        '#title' => $this->t('Last Fetched'),
        '#markup' => \Drupal::service('date.formatter')->format($last_fetch, 'long'),
      ];
    }

    if ($node->hasField('field_content_hash') && !$node->get('field_content_hash')->isEmpty()) {
      $hash = $node->get('field_content_hash')->value;
      $form['info']['hash'] = [
        '#type' => 'item',
        '#title' => $this->t('Current Hash'),
        '#markup' => '<code>' . substr($hash, 0, 16) . '...</code>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to force update this node?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will fetch fresh content from the XML source and update all fields, regardless of whether the content has changed. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.node.canonical', ['node' => $this->node->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // Clear the hash to force update.
      if ($this->node->hasField('field_content_hash')) {
        $this->node->set('field_content_hash', NULL);
        $this->node->save();
      }

      // Process the node. force_remap = TRUE bypasses both the conditional
      // request short-circuit (304 from upstream) and the local hash-equality
      // short-circuit, so the field mapper actually runs against the fetched
      // XML regardless of whether the source content is byte-identical.
      $this->feedRefresher->processNode($this->node->id(), TRUE);

      $this->messenger()->addStatus($this->t('Node %title has been successfully updated from its XML source.', [
        '%title' => $this->node->label(),
      ]));

      $this->logger('shared_content')->info('Force updated node @nid via UI.', [
        '@nid' => $this->node->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error updating node: @message', [
        '@message' => $e->getMessage(),
      ]));

      $this->logger('shared_content')->error('Force update failed for node @nid: @message', [
        '@nid' => $this->node->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
