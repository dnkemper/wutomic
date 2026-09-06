<?php

namespace Drupal\shared_content\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Subscribes to aggregator feed item updates.
 */
class FeedItemUpdateSubscriber implements EventSubscriberInterface {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs the event subscriber.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('aggregator');
  }

  /**
   * React to an aggregator item update.
   */
  public function onFeedItemUpdate(GenericEvent $event) {
    $feed_item = $event->getSubject();
    $title = $feed_item->label();
    $this->logger->notice("Feed item '{$title}' has been modified and needs updating.");
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'shared_content.feed_item_updated' => 'onFeedItemUpdate',
    ];
  }

}
