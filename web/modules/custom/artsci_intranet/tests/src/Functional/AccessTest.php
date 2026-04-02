<?php

namespace Drupal\Tests\artsci_intranet\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\filter\Entity\FilterFormat;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Tests for intranet module.
 *
 * @group artsci_intranet
 */
class AccessTest extends BrowserTestBase {
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'atomic_artsci';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'config_split',
    'filter',
    'menu_block',
    'menu_link_content',
    'node',
    'robotstxt',
    'samlauth',
    'artsci_intranet',
    'simple_sitemap',
    'artsci_search',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set atomic_artsci header type to avoid Twig error. There is some additional
    // setup happening in artsci_intranet.install.
    $this->config('atomic_artsci.settings')->set('header.type', 'inline')->save();

    $this->drupalCreateContentType(['type' => 'page']);
  }

  /**
   * Test 401 response in artsci_intranet.
   */
  public function testAccessDeniedResponseCode() {
    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Test an authenticated user gets a 403.
   */
  public function testUnauthorizedResponseCode() {
    $user = $this->createUser();
    $this->drupalLogin($user);
    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Test sitemap.xml returns access denied.
   */
  public function testSitemapReturnsAccessDenied() {
    $this->drupalGet('sitemap.xml');
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Test search returns access denied.
   */
  public function testSearchReturnsAccessDenied() {
    $this->drupalGet('search');
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Test robots.txt returns 200.
   */
  public function testRobotsReturnsOk() {
    $this->drupalGet('robots.txt');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test robots.txt denies all.
   */
  public function testRobotsDeniesAll() {
    $this->drupalGet('robots.txt');
    $content = $this->getSession()->getPage()->getContent();
    $this->assertEquals("User-agent: *\r\nDisallow: /", $content);
  }

  /**
   * Test the top links do not render on the access denied page.
   */
  public function testNoTopLinks() {
    Menu::create([
      'id' => 'top-links',
      'label' => 'Top links',
    ])->save();

    MenuLinkContent::create([
      'title' => 'Super secret menu item',
      'provider' => 'menu_link_content',
      'menu_name' => 'top-links',
      'link' => ['uri' => 'internal:/user/login'],
    ])->save();

    $this->drupalPlaceBlock('atomic_artsci_toplinks', [
      'region' => 'action_menu',
      'id' => 'atomic_artsci_toplinks',
      'plugin' => 'menu_block:top-links',
      'label_display' => 0,
    ]);

    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->pageTextNotContains('Super secret menu item');
  }

  /**
   * Test the footer block does not render on the access denied page.
   */
  public function testNoFooterBlock() {
    $this->drupalPlaceBlock('atomic_artsci_footercontactinfo', [
      'region' => 'footer_first',
      'id' => 'atomic_artsci_footercontactinfo',
      'label' => 'Super secret footer block',
    ]);

    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->pageTextNotContains('Super secret footer block');
  }

  /**
   * Test the footer login link is not present.
   */
  public function testNoFooterLoginLink() {
    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->elementNotExists('css', '.artsci-footer--login-link');
  }

  /**
   * Test the title and message functionality for a 403 response.
   */
  public function testAccessDeniedTitleMessage() {
    $this->config('artsci_intranet.settings')
      ->set('access_denied.title', 'Access Denied')
      ->set('access_denied.message', '<p>This is some markup.</p>')
      ->save();

    $this->setUpFilter();
    $user = $this->createUser();
    $this->drupalLogin($user);
    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->pageTextContains('Access Denied');
    $this->assertSession()->responseContains('<p>This is some markup.</p>');
  }

  /**
   * Test the title and message functionality for a 401 response.
   */
  public function testUnauthorizedTitleMessage() {
    $this->config('artsci_intranet.settings')
      ->set('unauthorized.title', 'Unauthorized')
      ->set('unauthorized.message', '<p>This is <strong>some</strong> markup.</p>')
      ->save();

    $this->setUpFilter();
    $node = $this->drupalCreateNode();
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->pageTextContains('Unauthorized');
    $this->assertSession()->responseContains('<p>This is <strong>some</strong> markup.</p>');
  }

  /**
   * Set up the filter format used by our code.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUpFilter(): void {
    $minimal = FilterFormat::create([
      'format' => 'minimal',
      'name' => 'Minimal',
      'filters' => [
        'filter_html' => [
          'status' => 1,
          'settings' => [
            'allowed_html' => '<p> <br> <strong> <a> <em>',
          ],
        ],
      ],
    ]);

    $minimal->save();
  }

}
