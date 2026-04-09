<?php
/**
 * Quicksilver: db_updates.php
 *
 * Runs database updates after a deploy or database clone.
 *
 * Triggered by: deploy, clone_database workflows
 */

function run(string $cmd): void {
  passthru($cmd . ' 2>&1', $exit_code);
  if ($exit_code !== 0) {
    echo "ERROR: Command failed: $cmd\n";
    exit($exit_code);
  }
}

echo "Running database updates (drush updatedb)...\n";
run('drush updatedb --yes');
echo "Database updates complete.\n";
