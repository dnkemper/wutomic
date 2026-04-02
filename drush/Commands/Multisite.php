<?php

namespace Drush\Commands;

use Symfony\Component\Finder\Finder;

/**
 * Static class with various helper methods related multisite management.
 *
 * Migrated from blt/src/Multisite.php to work without BLT.
 */
class Multisite {

  /**
   * Static class.
   */
  private function __construct() {}

  /**
   * Given a site directory name, return the standardized database name.
   *
   * @param string $dir
   *   The multisite directory, i.e. the URI without the scheme.
   *
   * @return string
   *   The database name.
   *
   * @throws \Exception
   */
  public static function getDatabaseName($dir) {
    if ($dir == 'default') {
      return 'db';
    }
    else {
      $db = str_replace('.', '_', $dir);
      $db = str_replace('-', '_', $db);
    }

    return $db;
  }

  /**
   * Given a URI, create and return a unique identifier.
   *
   * Used for internal subdomain and Drush alias group name.
   *
   * @param string $uri
   *   The multisite URI including the scheme.
   *
   * @return string
   *   The ID.
   *
   * @throws \Exception
   */
  public static function getIdentifier($uri) {
    if ($parsed = parse_url($uri)) {

      if ($parsed['host'] == 'default') {
        $id = 'default';
      }
      elseif ($parsed['host'] === 'wustl.edu') {
        $id = 'home';
      }
      elseif (substr($parsed['host'], -9) === 'wustl.edu') {
        $id = substr($parsed['host'], 0, -10);
        $parts = array_reverse(explode('.', $id));
        $key = array_search('www', $parts);
        if ($key !== FALSE) {
          unset($parts[$key]);
        }
        $id = implode('', $parts);
      }
      else {
        $parts = explode('.', $parsed['host']);
        $key = array_search('www', $parts);
        if ($key !== FALSE) {
          unset($parts[$key]);
        }
        $extension = array_pop($parts);
        $parts = array_reverse($parts);
        $id = $extension . '-' . implode('', $parts);
      }

      return $id;
    }
    else {
      throw new \Exception("Unable to parse URL {$uri}.");
    }
  }

  /**
   * Given a multisite ID, return an array of internal domains.
   *
   * @param string $id
   *   The multisite identifier.
   *
   * @return array
   *   Internal domains keyed by environment.
   */
  public static function getInternalDomains($id) {
    return [
      'local' => "{$id}.ddev.site",
      'dev' => "{$id}.artscidev.wustl.edu",
      'test' => "{$id}.artscistage.wustl.edu",
      'prod' => "{$id}.wustl.edu",
    ];
  }

  /**
   * Find all multisites in the application root, excluding default.
   *
   * @param string $root
   *   The root of the application to find multisites in.
   *
   * @return array
   *   An array of sites.
   */
  public static function getAllSites($root) {
    $finder = new Finder();

    $dirs = $finder
      ->in("{$root}/web/sites/")
      ->directories()
      ->depth('< 1')
      ->exclude(['g', 'settings', 'simpletest'])
      ->sortByName();

    $sites = [];
    foreach ($dirs->getIterator() as $dir) {
      $sites[] = $dir->getRelativePathname();
    }

    return $sites;
  }

}
