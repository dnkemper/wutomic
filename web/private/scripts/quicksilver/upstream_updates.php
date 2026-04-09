<?php
/**
 * Quicksilver: upstream_updates.php
 *
 * Runs after upstream updates are applied (deploy_product workflow).
 * Executes: drush updatedb, drush config:import, drush cache:rebuild
 *
 * Triggered by:
 *   - terminus upstream:updates:apply
 *   - Dashboard "Apply Updates" button
 */

function run(string $cmd): void {
  passthru($cmd . ' 2>&1', $exit_code);
  if ($exit_code !== 0) {
    echo "ERROR: Command failed: $cmd\n";
    exit($exit_code);
  }
}

echo "=== Post-Upstream Update Tasks ===\n";

echo "\n[1/3] Running database updates (drush updatedb)...\n";
run('drush updatedb --yes');
echo "Database updates complete.\n";

echo "\n[2/3] Importing configuration (drush config:import)...\n";
run('drush config:import --yes');
echo "Configuration import complete.\n";

echo "\n[3/3] Clearing caches (drush cache:rebuild)...\n";
run('drush cache:rebuild');
echo "Cache rebuild complete.\n";

echo "\n=== Upstream update tasks complete. ===\n";
