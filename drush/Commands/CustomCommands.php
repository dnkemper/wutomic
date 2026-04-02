<?php

namespace Drush\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\user\Entity\User;
use Drush\Boot\DrupalBootLevels;
use Drush\Drupal\Commands\sql\SanitizePluginInterface;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Users command class.
 */
class CustomCommands extends DrushCommands implements SiteAliasManagerAwareInterface, SanitizePluginInterface {
  use SiteAliasManagerAwareTrait;

  /**
   * Configuration that should be sanitized.
   *
   * @var array
   */
  protected $sanitizedConfig = [];

  /**
   * Add additional fields to status command output.
   *
   * @param mixed $result
   *   The command result.
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook alter core:status
   *
   * @return array
   *   The altered command result.
   */
  public function alterStatus($result, CommandData $commandData) {
    if ($app = getenv('AH_SITE_GROUP')) {
      $result['application'] = $app;
    }

    return $result;
  }

  /**
   * Add custom field labels to the status command annotation data.
   *
   * @hook init core:status
   */
  public function initStatus(InputInterface $input, AnnotationData $annotationData) {
    $fields = explode(',', $input->getOption('fields'));
    $defaults = $annotationData->getList('default-fields');

    // If no specific fields were requested, add ours to the defaults.
    if ($fields == $defaults) {
      $annotationData->append('field-labels', "\n application: Application");
      array_unshift($defaults, 'application');
      $annotationData->set('default-fields', $defaults);
      $input->setOption('fields', $defaults);
    }
  }

  /**
   * Invoke BLT update command after sql:sync for remote targets only.
   *
   * @param mixed $result
   *   The command result.
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook post-command sql:sync
   *
   * @throws \Exception
   */
  public function postSqlSync($result, CommandData $commandData) {
    $record = $this->siteAliasManager()->getAlias($commandData->input()->getArgument('target'));

    if ($record->isRemote()) {
      $process = $this->processManager()->drush($record, 'cache:rebuild');
      $process->run($process->showRealtime());

      // Run database updates.
      $process = $this->processManager()->drush($record, 'updatedb', [], ['yes' => TRUE]);
      $process->run($process->showRealtime());

      // Import config.
      $process = $this->processManager()->drush($record, 'config:import', [], ['yes' => TRUE]);
      $process->run($process->showRealtime());

      // Rebuild cache.
      $process = $this->processManager()->drush($record, 'cache:rebuild');
      $process->run($process->showRealtime());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @hook post-command sql-sanitize
   */
  public function sanitize($result, CommandData $commandData) {
    $record = $this->siteAliasManager()->getSelf();

    foreach ($this->sanitizedConfig as $config) {
      /** @var \Consolidation\SiteProcess\SiteProcess $process */
      $process = $this->processManager()->drush($record, 'config:delete', [
        $config,
      ]);

      $process->run();

      if ($process->isSuccessful()) {
        $this->logger()->success(dt('Deleted @config configuration.', [
          '@config' => $config,
        ]));
      }
      else {
        $this->logger()->warning(dt('Unable to delete @config configuration.'), [
          '@config' => $config,
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @hook on-event sql-sanitize-confirms
   */
  public function messages(&$messages, InputInterface $input) {
    $record = $this->siteAliasManager()->getSelf();

    $configs = [
      'migrate_plus.migration_group.olympian_migration',
      'sitenow_dispatch.settings',
    ];

    foreach ($configs as $config) {
      /** @var \Consolidation\SiteProcess\SiteProcess $process */
      $process = $this->processManager()->drush($record, 'config:get', [
        $config,
      ]);

      $process->run();

      if ($process->isSuccessful()) {
        $this->sanitizedConfig[] = $config;

        $messages[] = dt('Delete the @config configuration.', [
          '@config' => $config,
        ]);
      }
    }
  }

  /**
   * See:
   *
   * @hook pre-command config:import
   */
  public function setUuid() {
    // Clear cache in order to prevent errors after upgrading drupal.
    drupal_flush_all_caches();
    // Sets a hardcoded site uuid right before `drush config:import`.
    $staticUuidIsSet = \Drupal::state()->get('static_uuid_is_set');
    if (!$staticUuidIsSet) {
      $config_factory = \Drupal::configFactory();
      $config_factory->getEditable('system.site')
        ->set('uuid', '0ceced7f-d4cb-41b3-bbec-56eb5e14d767')
        ->save();
      Drush::output()
        ->writeln('Setting the correct UUID for this project: done.');
      \Drupal::state()->set('static_uuid_is_set', 1);
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $permissions = array_keys(\Drupal::service('user.permissions')
      ->getPermissions());
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $entity_type_manager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $role) {
      $role_permissions = $role->getPermissions();
      $differences = array_diff($role_permissions, $permissions);
      if ($differences) {
        foreach ($differences as $permission) {
          $role->revokePermission($permission);
        }
        $role->save();
      }
    }
  }

  /**
   * Show the database size.
   *
   * @command artsci:database:size
   *
   * @aliases ads
   *
   * @field-labels
   *   table: Table
   *   size: Size
   *
   * @return string
   *   The size of the database in megabytes.
   */
  public function databaseSize() {
    $selfRecord = $this->siteAliasManager()->getSelf();

    /** @var \Consolidation\SiteProcess\SiteProcess $process */
    $process = $this->processManager()->drush($selfRecord, 'core-status', [], [
      'fields' => 'db-name',
      'format' => 'json',
    ]);

    $process->run();
    $result = $process->getOutputAsJson();

    if (isset($result['db-name'])) {
      $db = $result['db-name'];
      $args = ["SELECT SUM(ROUND(((data_length + index_length) / 1024 / 1024), 2)) AS \"Size\" FROM information_schema.TABLES WHERE table_schema = \"$db\";"];
      $options = ['yes' => TRUE];
      $process = $this->processManager()->drush($selfRecord, 'sql:query', $args, $options);
      $process->mustRun();
      $output = trim($process->getOutput());
      return "{$output} MB";
    }
  }

  /**
   * Show tables larger than the input size.
   *
   * @param int $size
   *   The size in megabytes of table to filter on. Defaults to 1 MB.
   * @param mixed $options
   *   The command options.
   *
   * @command artsci:table:size
   *
   * @aliases ats
   *
   * @field-labels
   *   table: Table
   *   size: Size
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Tables in RowsOfFields output formatter.
   */
  public function tableSize(int $size = 1, $options = ['format' => 'table']) {
    $size = $this->input()->getArgument('size') * 1024 * 1024;
    $selfRecord = $this->siteAliasManager()->getSelf();
    $args = ["SELECT table_name AS \"Tables\", ROUND(((data_length + index_length) / 1024 / 1024), 2) \"Size in MB\" FROM information_schema.TABLES WHERE table_schema = DATABASE() AND (data_length + index_length) > $size ORDER BY (data_length + index_length) DESC;"];
    $options = ['yes' => TRUE];
    $process = $this->processManager()->drush($selfRecord, 'sql:query', $args, $options);
    $process->mustRun();
    $output = $process->getOutput();

    $rows = [];

    $output = explode(PHP_EOL, $output);
    foreach ($output as $line) {
      if (!empty($line)) {
        [$table, $table_size] = explode("\t", $line);

        $rows[] = [
          'table' => $table,
          'size' => $table_size . ' MB',
        ];
      }
    }

    $data = new RowsOfFields($rows);
    $data->addRendererFunction(function ($key, $cellData) {
      if ($key == 'first') {
        return "<comment>$cellData</>";
      }

      return $cellData;
    });

    return $data;
  }

  /**
   * Display a list of Drupal users.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @command users:list
   *
   * @option status Filter by status of the account. Can be active or blocked.
   * @option roles Filter by accounts having a role. Use a comma-separated list for more than one.
   * @option no-roles Filter by accounts not having a role. Use a comma-separated list for more than one.
   * @option last-login Filter by last login date. Can be relative.
   * @usage users:list
   *   Display all users on the site.
   * @usage users:list --status=blocked
   *   Displays a list of blocked users.
   * @usage users:list --roles=admin
   *   Displays a list of users with the admin role.
   * @usage users:list --last-login="1 year ago"
   *   Displays a list of users who have logged in within a year.
   * @aliases user-list, list-users
   * @bootstrap full
   * @field-labels
   *   uid: User ID
   *   name: Username
   *   pass: Password
   *   mail: User mail
   *   theme: User theme
   *   signature: Signature
   *   signature_format: Signature format
   *   user_created: User created
   *   created: Created
   *   user_access: User last access
   *   access: Last access
   *   user_login: User last login
   *   login: Last login
   *   user_status: User status
   *   status: Status
   *   timezone: Time zone
   *   picture: User picture
   *   init: Initial user mail
   *   roles: User roles
   *   group_audience: Group Audience
   *   langcode: Language code
   *   uuid: Uuid
   * @table-style default
   * @default-fields uid,name,mail,roles,status,login
   *
   * @throws \Exception
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The users as a RowsOfFields.
   */
  public function listAll(array $options = [
    'status' => InputOption::VALUE_REQUIRED,
    'roles' => InputOption::VALUE_REQUIRED,
    'no-roles' => InputOption::VALUE_REQUIRED,
    'last-login' => InputOption::VALUE_REQUIRED,
  ]) {
    // Use an entityQuery to dynamically set property conditions.
    $query = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('uid', 0, '!=');

    if (isset($options['status'])) {
      $query->condition('status', $options['status'], '=');
    }

    if (isset($options['roles'])) {
      $query->condition('roles', $options['roles'], 'IN');
    }

    if (isset($options['no-roles'])) {
      $query->condition('roles', $options['no-roles'], 'NOT IN');
    }

    if (isset($options['last-login'])) {
      $timestamp = strtotime($options['last-login']);
      $query->condition('login', 0, '!=');
      $query->condition('login', $timestamp, '>=');
    }

    $ids = $query->execute();

    if ($users = User::loadMultiple($ids)) {
      $rows = [];

      foreach ($users as $id => $user) {
        $rows[$id] = $this->infoArray($user);
      }

      $result = new RowsOfFields($rows);
      $result->addRendererFunction(function ($key, $cellData, FormatterOptions $options) {
        if (is_array($cellData)) {
                return implode("\n", $cellData);
        }
          return $cellData;
      });

      return $result;
    }
    else {
      throw new \Exception(dt('No users found.'));
    }
  }

  /**
   * Validate the users:list command.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The command data.
   *
   * @hook validate users:list
   *
   * @throws \Exception
   */
  public function validateList(CommandData $commandData) {
    $input = $commandData->input();

    $options = [
      'blocked',
      'active',
    ];

    if ($status = $input->getOption('status')) {
      if (!in_array($status, $options)) {
        throw new \Exception(dt('Unknown status @status. Status must be one of @options.', [
          '@status' => $status,
          '@options' => implode(', ', $options),
        ]));
      }

      // Set the status to the key of the options array.
      $input->setOption('status', array_search($status, $options));
    }

    // Set the (no-)roles options to an array but validate each one exists.
    $actual = user_roles(TRUE);

    foreach (['roles', 'no-roles'] as $option) {
      if ($roles = $input->getOption($option)) {
        $roles = explode(',', $roles);

        // Throw an exception for non-existent roles.
        foreach ($roles as $role) {
          if (!isset($actual[$role])) {
            throw new \Exception(dt('Role @role does not exist.', [
              '@role' => $role,
            ]));
          }
        }

        $input->setOption($option, $roles);
      }
    }

    // Validate the last-login option.
    if ($last = $input->getOption('last-login')) {
      if (strtotime($last) === FALSE) {
        throw new \Exception(dt('Unable to convert @last to a timestamp.', [
          '@last' => $last,
        ]));
      }
    }
  }

  /**
   * Block and unblock users while keeping track of previous state.
   *
   * @command users:toggle
   * @usage users:toggle
   *   Block/unblock all users on the site. Based on previous state.
   * @aliases utog
   * @bootstrap full
   */
  public function toggle() {
    // Get all users.
    $ids = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('uid', 0, '!=')
      ->execute();

    if ($users = User::loadMultiple($ids)) {
      // The toggle status is determined by the last command run.
      $status = \Drupal::state()->get('utog_status', 'unblocked');
      $previous = \Drupal::state()->get('utog_previous', []);

      $this->logger()->notice(dt('Toggle status: @status', [
        '@status' => $status,
      ]));

      if ($status == 'unblocked') {
        if (\Drupal::configFactory()->getEditable('user.settings')->get('notify.status_blocked')) {
          $this->logger()->warning(dt('Account blocked email notifications are currently enabled.'));
        }

        $block = [];

        foreach ($users as $user) {
          $name = $user->getAccountName();

          if ($user->isActive() == FALSE) {
            $previous[] = $name;
          }
          else {
            $block[] = $name;
          }
        }

        $block_list = implode(', ', $block);

        if (!$this->io()->confirm(dt(
          'You will block @names. Are you sure?',
          ['@names' => $block_list]
          ))) {
          throw new UserAbortException();
        }

        if (Drush::drush($this->siteAliasManager()->getSelf(), 'user:block', [$block_list])->mustRun()) {
          \Drupal::state()->set('utog_previous', $previous);
          \Drupal::state()->set('utog_status', 'blocked');
        }
      }
      else {
        if (\Drupal::configFactory()->getEditable('user.settings')->get('notify.status_activated')) {
          $this->logger()->warning(dt('Account activation email notifications are currently enabled.'));
        }

        if (empty($previous)) {
          $this->logger()->notice(dt('No previously-blocked users.'));
        }
        else {
          $this->logger()->notice(dt('Previously blocked users: @names.', ['@names' => implode(', ', $previous)]));
        }

        $unblock = [];

        foreach ($users as $user) {
          if (!in_array($user->getAccountName(), $previous)) {
            $unblock[] = $user->getAccountName();
          }
        }

        $unblock_list = implode(', ', $unblock);

        if (!$this->io()->confirm(dt('You will unblock @unblock. Are you sure?', ['@unblock' => $unblock_list]))) {
          throw new UserAbortException();
        }

        if (Drush::drush($this->siteAliasManager()->getSelf(), 'user:unblock', [$unblock_list])->mustRun()) {
          \Drupal::state()->set('utog_previous', []);
          \Drupal::state()->set('utog_status', 'unblocked');
        }
      }
    }
  }

  /**
   * A flatter and simpler array presentation of a Drupal $user object.
   *
   * @param \Drupal\user\Entity\User $account
   *   A user account object.
   *
   * @return array
   *   An array of user information.
   */
  protected function infoArray(User $account) {
    /** @var \Drupal\Core\Datetime\DateFormatter $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');

    return [
      'uid' => $account->id(),
      'name' => $account->getAccountName(),
      'pass' => $account->getPassword(),
      'mail' => $account->getEmail(),
      'user_created' => $account->getCreatedTime(),
      'created' => $date_formatter->format($account->getCreatedTime()),
      'user_access' => $account->getLastAccessedTime(),
      'access' => $date_formatter->format($account->getLastAccessedTime()),
      'user_login' => $account->getLastLoginTime(),
      'login' => $date_formatter->format($account->getLastLoginTime()),
      'user_status' => $account->get('status')->value,
      'status' => $account->isActive() ? 'active' : 'blocked',
      'timezone' => $account->getTimeZone(),
      'roles' => $account->getRoles(),
      'langcode' => $account->getPreferredLangcode(),
      'uuid' => $account->uuid->value,
    ];
  }

  /**
   * Prepare a site to run update hooks.
   *
   * @command artsci:debug:update-hook
   *
   * @aliases adu
   */
  public function setupSiteForDebuggingUpdate() {
    $selfRecord = $this->siteAliasManager()->getSelf();

    // This doesn't actually make a difference at this point, but is good to
    // have in case they eventually make it so that commands run inside another
    // command can actually respond to interaction.
    $options = [
      'yes' => TRUE,
    ];

    // Clear drush cache.
    /** @var \Consolidation\SiteProcess\SiteProcess $process */
    $process = $this->processManager()->drush($selfRecord, 'cache-clear', ['drush'], $options);
    $process->mustRun($process->showRealtime());

    // Sync from prod.
    $prod_alias = str_replace('.local', '.prod', $selfRecord->name());
    $process = $this->processManager()->drush($selfRecord, 'sql-sync', [
      $prod_alias,
      '@self',
    ], [
      ...$options,
      'create-db' => TRUE,
    ]);
    $process->mustRun($process->showRealtime());

    // Rebuild cache.
    $process = $this->processManager()->drush($selfRecord, 'cr', [], $options);
    $process->mustRun($process->showRealtime());

    // Sanitize SQL.
    $process = $this->processManager()->drush($selfRecord, 'sql-sanitize', [], $options);
    $process->mustRun($process->showRealtime());
  }

  /**
   * Query a site for information needed for compliance reporting.
   *
   * @command artsci:get:gtm-containers
   *
   * @aliases agetgtm
   *
   * @throws \Exception
   */
  public function getGtmContainerIds() {
    // Bootstrap Drupal so that we can query entities.
    if (!Drush::bootstrapManager()->doBootstrap(DrupalBootLevels::FULL)) {
      throw new \Exception(dt('Unable to bootstrap Drupal.'));
    }

    // Get a list of container ID's for GTM.
    $container_ids = [];

    $containers = \Drupal::entityTypeManager()
      ?->getStorage('google_tag_container')
      ?->loadMultiple();

    foreach ($containers as $container) {
      $container_ids[] = $container->container_id;
    }

    return implode(', ', $container_ids);
  }
 /**
 * Ensure users exist with specified roles.
 *
 * @command artsci:admins
 * @aliases aadmins
 * @option webteam Comma-separated list of webteam admin usernames
 * @option communications Comma-separated list of communications role usernames
 * @usage artsci:admins
 *   Create/update users using environment variables from Pantheon secrets
 * @usage artsci:admins --webteam="ksmith,pbrown" --communications="mjohnson"
 *   Create/update users with specified roles (overrides environment variables)
 * @bootstrap full
 */
public function admins(array $options = [
  'webteam' => InputOption::VALUE_REQUIRED,
  'communications' => InputOption::VALUE_REQUIRED,
]) {
  // Get from command options first, then fall back to environment variables
  // Use getenv() instead of $_ENV to avoid "Undefined array key" warnings
  $webteam_users = $options['webteam'] ?: (getenv('WEBTEAM_ADMIN_USERS') ?: '');
  $communication_users = $options['communications'] ?: (getenv('COMMUNICATION_USERS') ?: '');
  
  // If no users specified at all, show warning and exit
  if (empty($webteam_users) && empty($communication_users)) {
    $this->logger()->warning('No users specified. Set WEBTEAM_ADMIN_USERS or COMMUNICATION_USERS environment variables, or use --webteam or --communications options.');
    return;
  }
  
  // Log what we're about to do
  if ($webteam_users) {
    $this->logger()->info("Processing webteam admins: $webteam_users");
  }
  if ($communication_users) {
    $this->logger()->info("Processing communications users: $communication_users");
  }
  
  // Process webteam administrators
  if ($webteam_users) {
    $usernames = array_map('trim', explode(',', $webteam_users));
    $this->processUsers($usernames, 'administrator', 'Administrator');
  }
  
  // Process communications users
  if ($communication_users) {
    $usernames = array_map('trim', explode(',', $communication_users));
    $this->processUsers($usernames, 'communications', 'Communications');
  }
  
  $this->logger()->success('User processing complete.');
}

/**
 * Process a list of users and ensure they have the specified role.
 *
 * @param array $usernames
 *   Array of usernames to process.
 * @param string $role_id
 *   The role ID to assign (machine name).
 * @param string $role_label
 *   The human-readable role label for logging.
 */
protected function processUsers(array $usernames, string $role_id, string $role_label) {
  foreach ($usernames as $username) {
    // Skip empty usernames
    if (empty($username)) {
      continue;
    }
    
    // Check if user exists
    $users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);
    
    if (empty($users)) {
      // User doesn't exist - create it
      try {
        $user = User::create([
          'name' => $username,
          'mail' => $username . '@wustl.edu',
          'status' => 1,
          'roles' => [$role_id],
        ]);
        $user->save();
        $this->logger()->success("Created user: {$username} with {$role_label} role");
      }
      catch (\Exception $e) {
        $this->logger()->error("Failed to create user {$username}: " . $e->getMessage());
      }
    }
    else {
      // User exists - ensure they have the role
      $user = reset($users);
      
      if (!$user->hasRole($role_id)) {
        try {
          $user->addRole($role_id);
          $user->save();
          $this->logger()->success("Added {$role_label} role to existing user: {$username}");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to add role to {$username}: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->info("User {$username} already has {$role_label} role");
      }
    }
  }
}
 
  /**
   * Deletes all aggregator feeds and re-imports them from OPML.
   *
   * @command artsci:aggregator:reset
   *
   * @aliases aar
   *
   * @usage artsci:aggregator:reset
   *   Reset all aggregator feeds and reimport from OPML.
   *
   * @bootstrap full
   *
   * @throws \Exception
   */
  public function resetAggregatorFeeds() {
    $this->logger()->notice(dt('Starting aggregator feed reset...'));

    // Bootstrap Drupal if needed.
    if (!Drush::bootstrapManager()->doBootstrap(DrupalBootLevels::FULL)) {
      throw new \Exception(dt('Unable to bootstrap Drupal.'));
    }

    // Delete all existing feeds.
    $feed_ids = \Drupal::entityQuery('aggregator_feed')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($feed_ids)) {
      $feeds = \Drupal::entityTypeManager()
        ->getStorage('aggregator_feed')
        ->loadMultiple($feed_ids);
      
      foreach ($feeds as $feed) {
        $feed->delete();
      }
      
      $this->logger()->success(dt('Deleted @count aggregator feeds.', [
        '@count' => count($feed_ids),
      ]));
    }
    else {
      $this->logger()->notice(dt('No aggregator feeds found to delete.'));
    }

    // Reimport OPML feeds.
    $this->logger()->notice(dt('Reimporting feeds from OPML...'));
    
    $selfRecord = $this->siteAliasManager()->getSelf();
    $options = ['yes' => TRUE];

    // Run the shared content OPML import command.
    /** @var \Consolidation\SiteProcess\SiteProcess $process */
    $process = $this->processManager()->drush($selfRecord, 'sc-opml', [], $options);
    $process->run($process->showRealtime());
    
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error importing feeds from OPML.'));
      return;
    }
    
    // Refresh the feeds.
    $process = $this->processManager()->drush($selfRecord, 'sc-refresh', [], $options);
    $process->run($process->showRealtime());
    
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error refreshing feeds.'));
      return;
    }
    
    $this->logger()->success(dt('Successfully reset and reimported aggregator feeds.'));
  }

/**
   * Run standard release tasks: updb, cim, and cr.
   *
   * @command artsci:release
   *
   * @aliases amr
   *
   * @usage artsci:release
   *   Run database updates, import config, and clear caches.
   *
   * @bootstrap full
   *
   * @throws \Exception
   */
  public function release() {
    $this->logger()->notice(dt('Starting release process...'));

    $selfRecord = $this->siteAliasManager()->getSelf();
    $options = ['yes' => TRUE];

    // Run database updates.
    $this->logger()->notice(dt('Running database updates...'));
    /** @var \Consolidation\SiteProcess\SiteProcess $process */
    $process = $this->processManager()->drush($selfRecord, 'updb', [], $options);
    $process->run($process->showRealtime());
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error running database updates.'));
      return;
    }
    $this->logger()->success(dt('Database updates completed.'));

    // Clear caches after updb.
    $this->logger()->notice(dt('Clearing caches after database updates...'));
    $process = $this->processManager()->drush($selfRecord, 'cache:rebuild');
    $process->run($process->showRealtime());
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error clearing caches after database updates.'));
      return;
    }
    $this->logger()->success(dt('Caches cleared.'));

    // Import configuration.
    $this->logger()->notice(dt('Importing configuration...'));
    $process = $this->processManager()->drush($selfRecord, 'config:import', [], $options);
    $process->run($process->showRealtime());
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error importing configuration.'));
      return;
    }
    $this->logger()->success(dt('Configuration imported.'));

    // Clear caches after cim.
    $this->logger()->notice(dt('Clearing caches after configuration import...'));
    $process = $this->processManager()->drush($selfRecord, 'cache:rebuild');
    $process->run($process->showRealtime());
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error clearing caches after configuration import.'));
      return;
    }
    $this->logger()->success(dt('Caches cleared.'));

    $this->logger()->success(dt('Release process completed successfully!'));
  }

  /**
   * Install Drupal with artsci profile and default content.
   *
   * @param array $options
   *   Command options.
   *
   * @command artsci:install
   *
   * @aliases aip
   *
   * @option site-name The site name.
   * @option site-mail The site email address.
   * @option contact-url The contact us URL.
   * @option admin-pass The admin password.
   * @usage artsci:install --site-name="My Site" --site-mail="site@example.com"
   *   Install Drupal with custom site settings.
   *
   * @bootstrap full
   *
   * @throws \Exception
   */
  public function installProfile(array $options = [
    'site-name' => InputOption::VALUE_REQUIRED,
    'site-mail' => InputOption::VALUE_REQUIRED,
    'contact-url' => InputOption::VALUE_REQUIRED,
    'admin-pass' => InputOption::VALUE_REQUIRED,
  ]) {
    $this->logger()->notice(dt('Starting Drupal installation with artsci profile...'));

    $selfRecord = $this->siteAliasManager()->getSelf();
    $drush_options = ['yes' => TRUE];

    // Install Drupal.
    $this->logger()->notice(dt('Installing Drupal...'));
    /** @var \Consolidation\SiteProcess\SiteProcess $process */
    $process = $this->processManager()->drush(
      $selfRecord, 
      'site:install', 
      ['artsci'], 
      $drush_options
    );
    $process->run($process->showRealtime());
    
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error installing Drupal.'));
      return;
    }
    $this->logger()->success(dt('Drupal installed.'));

    // Import configuration (run twice as in original).
    $this->logger()->notice(dt('Importing configuration (first pass)...'));
    $process = $this->processManager()->drush($selfRecord, 'config:import', [], $drush_options);
    $process->run($process->showRealtime());

    $this->logger()->notice(dt('Importing configuration (second pass)...'));
    $process = $this->processManager()->drush($selfRecord, 'config:import', [], $drush_options);
    $process->run($process->showRealtime());
    
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error importing configuration.'));
      return;
    }
    $this->logger()->success(dt('Configuration imported.'));

    // Enable artsci_content module.
    $this->logger()->notice(dt('Enabling artsci_content module...'));
    $process = $this->processManager()->drush($selfRecord, 'pm:enable', ['artsci_content'], $drush_options);
    $process->run($process->showRealtime());
    
    if (!$process->isSuccessful()) {
      $this->logger()->error(dt('Error enabling artsci_content module.'));
      return;
    }
    $this->logger()->success(dt('artsci_content enabled.'));

    // Set site configuration if provided.
    if (!empty($options['site-name'])) {
      $this->logger()->notice(dt('Setting site name...'));
      $process = $this->processManager()->drush(
        $selfRecord, 
        'config:set', 
        ['system.site', 'name', $options['site-name']], 
        $drush_options
      );
      $process->run($process->showRealtime());
    }

    if (!empty($options['site-mail'])) {
      $this->logger()->notice(dt('Setting site email...'));
      $process = $this->processManager()->drush(
        $selfRecord, 
        'config:set', 
        ['system.site', 'mail', $options['site-mail']], 
        $drush_options
      );
      $process->run($process->showRealtime());
    }

    if (!empty($options['contact-url'])) {
      $this->logger()->notice(dt('Setting contact URL...'));
      $process = $this->processManager()->drush(
        $selfRecord, 
        'config:set', 
        ['system.site', 'contact_us_url', $options['contact-url']], 
        $drush_options
      );
      $process->run($process->showRealtime());
    }

    // Set admin password if provided.
    if (!empty($options['admin-pass'])) {
      $this->logger()->notice(dt('Setting admin password...'));
      $process = $this->processManager()->drush(
        $selfRecord, 
        'user:password', 
        ['asdrupal', $options['admin-pass']]
      );
      $process->run($process->showRealtime());
    }

    // Clear caches.
    $this->logger()->notice(dt('Clearing caches...'));
    $process = $this->processManager()->drush($selfRecord, 'cache:rebuild');
    $process->run($process->showRealtime());

    // Generate login link.
    $this->logger()->notice(dt('Generating one-time login link...'));
    $process = $this->processManager()->drush($selfRecord, 'user:login');
    $process->run($process->showRealtime());

    $this->logger()->success(dt('Installation completed successfully!'));
  }

}
