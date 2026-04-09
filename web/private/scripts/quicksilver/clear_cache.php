<?php
/**
 * Quicksilver: clear_cache.php
 *
 * Clears all Drupal caches.
 *
 * Triggered by: sync_code, deploy workflows
 */

function run(string $cmd): void {
  passthru($cmd . ' 2>&1', $exit_code);
  if ($exit_code !== 0) {
    echo "ERROR: Command failed: $cmd\n";
    exit($exit_code);
  }
}

echo "Clearing Drupal caches (drush cache:rebuild)...\n";
run('drush cache:rebuild');
echo "Cache rebuild complete.\n";
