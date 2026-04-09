<?php
/**
 * Quicksilver: config_import.php
 *
 * Imports configuration after a deploy.
 *
 * Triggered by: deploy workflow
 */

function run(string $cmd): void {
  passthru($cmd . ' 2>&1', $exit_code);
  if ($exit_code !== 0) {
    echo "ERROR: Command failed: $cmd\n";
    exit($exit_code);
  }
}

echo "Importing configuration (drush config:import)...\n";
run('drush config:import --yes');
echo "Configuration import complete.\n";
