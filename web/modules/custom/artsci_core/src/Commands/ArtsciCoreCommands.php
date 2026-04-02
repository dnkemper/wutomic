<?php

namespace Drupal\artsci_core\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
class ArtsciCoreCommands extends DrushCommands {
  use LoggerChannelTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Command constructor.
   */
  public function __construct(ConfigFactoryInterface $configFactory, ModuleHandler $moduleHandler) {
    parent::__construct();
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Toggles Site-Specific Google Tag inserts.
   *
   * @command artsci_core:toggle-gtag
   * @aliases uicore-gtag
   */
  public function toggleGtag() {
    $config = $this->configFactory->getEditable('artsci_core.settings');
    $artsci_core_gtag = $config->get('artsci_core.gtag');

    if ((int) $artsci_core_gtag === 1) {
      $this->getLogger('artsci_core')->notice('Site-specific Google Tag Manager Disabled');
      $config
        ->set('artsci_core.gtag', '0')
        ->save();
    }
    else {
      $this->getLogger('artsci_core')->notice('Site-specific Google Tag Manager Enabled');
      $config
        ->set('artsci_core.gtag', '1')
        ->save();
    }
    // Flush site cache.
    drupal_flush_all_caches();

    // If available (not Local), try to clear the varnish cache for the files
  }

  /**
   * Displays custom role mappings if any.
   *
   * @command artsci_core:custom_role_mappings
   * @aliases uicore-crm
   *
   * @usage artsci_core:custom_role_mappings
   */
  public function customRoleMappings() {
    if ($this->moduleHandler->moduleExists('artsci_auth')) {
      // Get site's role_mapping values from artsci_auth module.
      $config = $this->configFactory->getEditable('artsci_auth.settings');
      $role_mappings = $config->get('role_mappings');

      // Get site default role_mappings from config directory.
      $config_path = DRUPAL_ROOT . '/../config/default';
      $source = new FileStorage($config_path);
      $artsci_auth = $source->read('artsci_auth.settings');
      $default_mappings = $artsci_auth['role_mappings'];

      if (!empty($role_mappings) && !empty($default_mappings)) {
        // Compare site to default mappings.
        $diff = array_diff($role_mappings, $default_mappings);

        if ($diff) {
          // Prettify and dump result.
          $result = Yaml::dump($diff);
          $this->getLogger('artsci_core')->notice($result);
        }
      }
      else {
        $this->getLogger('artsci_core')->notice('Configuration to compare does not exist.');
      }

    }
    else {
      $this->getLogger('artsci_core')->notice('artsci_auth is not enabled.');
    }
  }

}
