<?php


namespace Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Drush;
use Drush\Exec\ExecTrait;
use Symfony\Component\Filesystem\Filesystem;

class SyncDatabaseCommand extends DrushCommands {

  /**
   * Sync the local database to a remote Drupal multisite.
   *
   * @command sync-database
   */
  public function syncDatabase() {
    // Step 1: Ask for environment.
    $environments = ['stage', 'prod'];
    $this->output()->writeln("Available environments: stage, prod");
    $env = $this->io()->ask("Enter the environment:", 'stage');
    if (!in_array($env, $environments)) {
      $this->output()->writeln("<error>Invalid environment.</error>");
      return;
    }

    // Step 2: Ask for site alias.
    $selectedAlias = $this->io()->ask("Enter the site alias (e.g., @stage.anthropology):");
    if (empty($selectedAlias) || !preg_match('/^@' . $env . '\.\w+$/', $selectedAlias)) {
      $this->output()->writeln("<error>Invalid alias format. Expected format: @$env.sitename</error>");
      return;
    }

    // Extract site name from alias.
    $selectedSite = str_replace("@$env.", "", $selectedAlias);

    // Step 3: Define variables.
    $backupDir = "d7data/d10/backups";
    $localSqlFile = "d7data/local.sql";
    $remoteServer = ($env === 'stage') ? 'artscistage.wustl.edu' : 'artsciprod.wustl.edu';
    $remoteBackupDir = "/exports/nfsdrupal/d9main/d7data/backups";
    $remoteSqlFile = "$remoteBackupDir/local.sql";

    // Step 4: Create a remote DB backup.
    $this->output()->writeln("<info>Backing up remote database...</info>");
    $backupFile = "$backupDir/drupal_{$selectedSite}_backup.sql";
    $this->runProcess("drush $selectedAlias sql-dump > $backupFile");

    // Step 5: Export local database.
    $this->output()->writeln("<info>Exporting local database...</info>");
    $this->runProcess("drush sql-dump > $localSqlFile");

    // Step 6: Modify the local SQL dump.
    $this->output()->writeln("<info>Cleaning up SQL dump...</info>");
    $this->runProcess("sed -i '' '/\\/\\*M!999999/d' $localSqlFile");

    // Step 7: Rsync SQL file to the selected environment.
    $this->output()->writeln("<info>Uploading SQL file to remote environment...</info>");
    $this->runProcess("rsync -avz --progress $localSqlFile $remoteServer:$remoteBackupDir/");

    // Step 8: Import the SQL dump on the remote site.
    $this->output()->writeln("<info>Importing database on remote site...</info>");
    $this->runProcess("drush $selectedAlias sql-query --file=$remoteSqlFile");

    $this->output()->writeln("<info>Database sync completed successfully!</info>");
  }

  /**
   * Run a shell command.
   */
  private function runProcess($command) {
    $process = proc_open($command, [
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes);

    if (is_resource($process)) {
      $output = stream_get_contents($pipes[1]);
      $error = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);

      if (!empty($error)) {
        $this->output()->writeln("<error>$error</error>");
      } else {
        $this->output()->writeln($output);
      }
    }
  }
}
