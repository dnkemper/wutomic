<?php
/**
 * Quicksilver: sanitize_db.php
 *
 * Runs after a database clone to get the database in a usable state.
 * Runs updb first to handle any pending schema updates, then rebuilds caches.
 *
 * Triggered by: clone_database workflow (runs before config_splits_import.php)
 */

echo "Running database updates after clone (drush updb)...\n";
passthru('drush updb --yes 2>&1');
echo "Database updates complete.\n";
