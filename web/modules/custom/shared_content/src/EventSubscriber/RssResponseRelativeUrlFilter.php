<?php

namespace Drupal\shared_content\EventSubscriber;

use Drupal\Component\Utility\Html;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to filter RSS responses, to make relative URIs absolute.
 */
class RssResponseRelativeUrlFilter implements EventSubscriberInterface {

  /**
   * Converts relative URLs to absolute URLs.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    // Only process RSS responses.
    if (stripos($event->getResponse()->headers->get('Content-Type', ''), 'application/rss+xml') === FALSE) {
      return;
    }

    $response = $event->getResponse();
    $response->setContent($this->transformRootRelativeUrlsToAbsolute($response->getContent(), $event->getRequest()));
  }

  /**
   * Converts root-relative URLs to absolute in RSS description fields.
   *
   * @param string $rss_markup
   *   The raw RSS XML string.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   Modified RSS XML with updated <description> content.
   */
  protected function transformRootRelativeUrlsToAbsolute($rss_markup, Request $request) {
    $rss_dom = new \DOMDocument();

    // Suppress parsing errors.
    $previous_value = libxml_use_internal_errors(TRUE);
    $rss_dom->loadXML($rss_markup);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_value);

    $host = $request->getSchemeAndHttpHost();

    foreach ($rss_dom->getElementsByTagName('item') as $item) {
      foreach ($item->getElementsByTagName('description') as $node) {
        $raw_markup = '';

        // Get text content (from CDATA or plain text).
        foreach ($node->childNodes as $child) {
          if ($child->nodeType === XML_CDATA_SECTION_NODE || $child->nodeType === XML_TEXT_NODE) {
            $raw_markup .= $child->nodeValue;
          }
        }

        if (!empty($raw_markup)) {
          // Decode HTML entities.
          $decoded = html_entity_decode($raw_markup, ENT_QUOTES | ENT_HTML5, 'UTF-8');

          // Convert root-relative URLs to absolute.
          $transformed = Html::transformRootRelativeUrlsToAbsolute($decoded, $host);

          // Replace old content with new CDATA-wrapped content.
          while ($node->hasChildNodes()) {
            $node->removeChild($node->firstChild);
          }

          $node->appendChild($rss_dom->createCDATASection($transformed));
        }
      }
    }

    return $rss_dom->saveXML();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after other response subscribers.
    $events[KernelEvents::RESPONSE][] = ['onResponse', -512];
    return $events;
  }

}
