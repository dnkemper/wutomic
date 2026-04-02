<?php

namespace Drupal\Tests\artsci\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\artsci\ConfigOverride\RobotstxtOverride;

/**
 * Robotstxt config override test.
 *
 * @group unit
 */
class RobotstxtOverrideTest extends UnitTestCase {
  /**
   * RequestStack mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Request mock.
   *
   * @var \Symfony\Component\HttpFoundation\Request|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $request;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requestStack = $this->createMock('\Symfony\Component\HttpFoundation\RequestStack');
    $this->request = $this->createMock('\Symfony\Component\HttpFoundation\Request');

    $this->requestStack->expects($this->any())
      ->method('getCurrentRequest')
      ->will($this->returnValue($this->request));
  }

  /**
   * Test config overrides for different internal production domains.
   *
   * @dataProvider providerConfigByInternalDomain
   */
  public function testConfigByInternalDomain($host) {
    $this->request->expects($this->any())
      ->method('getHost')
      ->will($this->returnValue($host));

    $sut = new RobotstxtOverride($this->requestStack);

    $overrides = $sut->loadOverrides(['robotstxt.settings']);
    $this->assertEquals("User-agent: *\r\nDisallow: /", $overrides['robotstxt.settings']['content']);
  }

  /**
   * DataProvider for testConfigByInternalDomain().
   */
  public function providerConfigByInternalDomain() {
    return [
      [
        'foo.artsci.ddev.site',
      ],
      [
        'foo.dev.drupal.artsci.edu',
      ],
      [
        'foo.stage.drupal.artsci.edu',
      ],
      [
        'foo.prod.drupal.artsci.edu',
      ],
    ];
  }

  /**
   * Test config overrides is not set for production domains.
   *
   * @dataProvider providerConfigByProductionDomain
   */
  public function testConfigByProductionDomain($host) {
    $this->request->expects($this->any())
      ->method('getHost')
      ->will($this->returnValue($host));

    $sut = new RobotstxtOverride($this->requestStack);

    $overrides = $sut->loadOverrides(['robotstxt.settings']);
    $this->assertArrayNotHasKey('robotstxt.settings', $overrides);
  }

  /**
   * DataProvider for testConfigByEnv().
   */
  public function providerConfigByProductionDomain() {
    return [
      [
        'foo.artsci.edu',
      ],
      [
        'foo.com',
      ],
      [
        'foo.org.artsci.edu',
      ],
      [
        'www.foo.com',
      ],
    ];
  }

}
