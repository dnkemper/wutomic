<?php

declare(strict_types=1);

namespace Drupal\artsci_core\Plugin\views\argument_default;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\views\Plugin\views\argument_default\ArgumentDefaultPluginBase;

/**
 * Returns the current season: spring, summer, or fall.
 *
 * @ViewsArgumentDefault(
 *   id = "current_season",
 *   title = @Translation("Current season (spring, summer, fall)"),
 * )
 */
final class CurrentSeason extends ArgumentDefaultPluginBase implements CacheableDependencyInterface {

  /**
   * {@inheritdoc}
   */
  public function getArgument() {
    $today = new \DateTime();
    $spring = new \DateTime('January 01');
    $summer = new \DateTime('May 20');
    $fall = new \DateTime('August 18');

    if ($today >= $spring && $today < $summer) {
      $argument = 'spring';
    }
    elseif ($today >= $summer && $today < $fall) {
      $argument = 'summer';
    }
    else {
      $argument = 'fall';
    }
    return $argument;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

}
