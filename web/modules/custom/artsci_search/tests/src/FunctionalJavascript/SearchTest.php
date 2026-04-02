<?php

namespace Drupal\Tests\artsci_search\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests the JavaScript functionality of the artsci_search module.
 *
 * @group artsci_search
 */
class SearchTest extends WebDriverTestBase {
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'atomic_artsci';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'artsci_search',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('artscisearch', [
      'region' => 'search',
      'id' => 'artscisearch',
      'plugin' => 'artsci_search_form',
    ]);
  }

  /**
   * Test that the search input is visible after clicking toggle button.
   */
  public function testSearchInputVisibleAfterClickingSearchButton() {
    $this->config('atomic_artsci.settings')->set('header.type', 'inline')->save();
    $this->drupalGet('<front>');
    $page = $this->getSession()->getPage();
    $button = $page->findButton('Search');
    $this->assertNotEmpty($button);
    $button->click();
    $field = $page->findField('edit-search-terms');
    $this->assertTrue($field->isVisible());
  }

}
