<?php

namespace Drupal\shared_content\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Delete entity action with default confirmation form.
 *
 * @Action(
 *   id = "views_bulk_operations_custom_create_entity",
 *   label = @Translation("Custom Create selected entities / translations"),
 *   type = "",
 *   confirm = FALSE,
 *   requirements = {
 *     "_permission" = "administer olympian core",
 *     "_custom_access" = "artsci_core.access_checker",
 *   },
 * )
 */
class CustomEntityCreateAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {

    $selected_items = array_filter($row->getValue('views_bulk_operations_bulk_form'));

    if (!empty($selected_items)) {
      global $user;
      // $config = parse_ini_file('config.ini');
      $counter = 0;

      foreach ($selected_items as $n) {
        $guid = $form_state->getValue('guid_' . $n);
        $title = $form_state->getValue('title_' . $n);
        $type = $form_state->getValue('type_' . $n);

        // Adjust the type for 'person' to 'faculty_staff'.

        $guidURL = explode(' at ', $guid);
        $sharedURL = $guidURL[1] . '/xml/' . $type . '/' . $guidURL[0] . '/rss.xml';

        // Check if entry already exists in the shared_content_items table.
        $existing = Database::getConnection()
          ->select('shared_content_items', 's')
          ->fields('s', ['url', 'orig_nid', 'nid'])
          ->condition('s.orig_nid', $guidURL[0])
          ->condition('s.url', $guidURL[1])
          ->execute()
          ->fetchAssoc();

        if (!$existing) {
          // Create and save new node.
          // Create and save new node.
          $node_values = [
            'type' => $type,
            'status' => 1,
          ];
          $entity = \Drupal::entityTypeManager()
            ->getStorage('node')
            ->create($node_values);
          // Grab the xml for the node.
          $xml = shared_content_get_XML($sharedURL);
          if (is_object($xml)) {
            $xml_string = $xml->asXML();
            // $shared_content->set(base64_encode($xml_string));
            $test = base64_encode($xml_string);
          }
          // $path = $sharedURL;
          // $xml = shared_content_get_XML($path);
          // $xml_string = $xml->asXML();
          // $test = base64_encode($xml_string);
          // Now you can work with the saved entity.
          $cuid = $entity->id();
          $node = Node::create([
            'title' => $title,
            'field_shared_content_xml' => $sharedURL,
            'field_shared_content' => $test,
            'type' => $type,
          ]);
          $node->save();

          // phpcs:disable
          // $node_values = [
          //             'type' => $type,
          //             'status' => 1,
          //           ];
          //           $entity = \Drupal::entityTypeManager()->getStorage('node')->create($node_values);
          //           $ewrapper = \Drupal::service('entity_type.manager')->getStorage('node')->load($entity);
          //
          //           $ewrapper->setTitle($title);
          //           $ewrapper->field_shared_content_xml->set($sharedURL);
          //           $ewrapper->save();
          //
          //           // Shared content import and processing
          //           $cuid = $ewrapper->id();
          // phpcs:enable
          // Get the node ID.
          $node_id = $node->id();

          // Create a URL and link for the new node.
          $url = Url::fromRoute('entity.node.canonical', ['node' => $node_id], ['absolute' => TRUE]);
          $link = Link::fromTextAndUrl($node->getTitle(), $url)->toString();

          // Display the linked message.
          \Drupal::messenger()
            ->addMessage($this->t('Processed content: @link', ['@link' => $link]));
          $token = shared_content_services_csrf($guidURL[1]);
          $auth = shared_content_services_login($config['username'], $config['password'], $guidURL[1]);

          // Process shared content and date fields
          // Watchdog log.
          \Drupal::logger('shared_content')
            ->info('Processed content: @title', ['@title' => $title]);
          \Drupal::messenger()
            ->addMessage($this->t('Processed content: @title', ['@title' => $title]));

          // Increment counter.
          $counter++;
        }
      }

      // Feedback message.
      if ($counter > 0) {
        \Drupal::messenger()
          ->addMessage($this->t('@count items were successfully imported.', ['@count' => $counter]));
      }
      else {
        \Drupal::messenger()->addMessage($this->t('No items were imported.'));
      }
    }
    else {
      \Drupal::messenger()
        ->addMessage($this->t('No items were selected for import.'), 'warning');
    }
    // phpcs:disable
    // $entityStorage = \Drupal::entityTypeManager()->getStorage('aggregator_item');
    //     $disallowedContentTypes = [
    //       'events',
    //       'faculty_staff',
    //       'book',
    //       'article',
    //     ];
    //     if ($entity->getEntityTypeId() == 'node' && in_array($entity->bundle(), $disallowedContentTypes)) {
    //       if ($entity instanceof TranslatableInterface && !$entity->isDefaultTranslation()) {
    //         $untranslated_entity = $entity->getUntranslated();
    //         $untranslated_entity->removeTranslation($entity->language()->getId());
    //         $untranslated_entity->save();
    //         return $this->t('Delete translations');
    //       }
    //
    //       // Check if context array has been passed to the action.
    //       if (empty($this->context)) {
    //         throw new \Exception('Context array empty in action object.');
    //       }
    //
    //       $this->messenger()->addMessage(\sprintf('Deleted (Title: %s)',
    //         $entity->label()
    //       ));
    //       $entity->delete();
    //       return $this->t('Batch process complete');
    //     }
    //     else {
    //       $this->messenger()
    //         ->addMessage(\sprintf('Access Denied. Deleting (Title: %s) is not permitted',
    //           $entity->label()
    //         ));
    //     }
    // phpcs:enable
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var Drupal\shared_content\Access\OlympianCoreAccess $check */
    $check = \Drupal::service('shared_content.access_checker');

    /** @var Drupal\Core\Access\AccessResultInterface $access */
    $access = $check->access(\Drupal::currentUser()->getAccount());

    if (!$access->isForbidden()) {
      return $object->access('delete', $account, $return_as_object);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function finished($success, array $results, array $operations): ?RedirectResponse {
    // Let's return a bit different message. We don't except faliures
    // in tests as well so no need to check for a success.
    if ($success) {
      $details = [];
      foreach ($results['operations'] as $operation) {
        if (!empty($operations['message'])) {
          $details[] = $operation['message'] . ' (' . $operation['count'] . ')';
        }
      }
      $message = static::translate(' ', [
        '@operations' => \implode(', ', $details),
      ]);
      if (empty($message)) {
        $message = 'Operation not permitted for this entity type';
      }
      static::message($message);
      return NULL;
    }
  }

}
