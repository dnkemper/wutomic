<?php
/**
 * Quicksilver: config_splits_import.php
 *
 * Re-imports configuration (including environment-specific config splits)
 * after a database clone. This ensures the cloned DB gets the correct
 * config for the target environment.
 *
 * Triggered by: clone_database workflow
 */

function run(string $cmd): void {
  passthru($cmd . ' 2>&1', $exit_code);
  if ($exit_code !== 0) {
    echo "ERROR: Command failed: $cmd\n";
    exit($exit_code);
  }
}

echo "Importing configuration after database clone (drush config:import)...\n";
run('drush config:import --yes');
echo "Configuration import complete.\n";
