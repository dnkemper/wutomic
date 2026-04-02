<?php

namespace Drupal\artsci\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Drupal\Core\Form\FormStateInterface;

/**
 * Overrides the default email handler to restrict access to attachments.
 *
 * @WebformHandler(
 *   id = "email",
 *   label = @Translation("Email"),
 *   category = @Translation("Notification"),
 *   description = @Translation("Sends a webform submission via an email."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class EmailOverrideWebformHandler extends EmailWebformHandler {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var Drupal\artsci_core\Access\ArtsciCoreAccess $check */
    $check = \Drupal::service('artsci_core.access_checker');

    /** @var Drupal\Core\Access\AccessResultInterface $access */
    $access = $check->access(\Drupal::currentUser()->getAccount());

    // Restrict access to attachments as they can cause runaway resource issues.
    if (isset($form['attachments']) && $access->isForbidden()) {
      $form['attachments']['#access'] = FALSE;
    }
    return $form;
  }

}
