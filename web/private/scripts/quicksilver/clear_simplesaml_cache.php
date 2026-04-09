<?php
/**
 * Quicksilver: clear_simplesaml_cache.php
 *
 * Clears SimpleSAMLphp temp/cache directories in the Pantheon app container.
 * This helps avoid stale compiled container artifacts after code or dependency
 * changes.
 *
 * Triggered by: sync_code, clone_database, deploy, deploy_product workflows
 */

/**
 * Recursively deletes a directory tree.
 */
function delete_tree(string $path): void {
  if (!file_exists($path) && !is_link($path)) {
    return;
  }

  if (is_file($path) || is_link($path)) {
    @unlink($path);
    return;
  }

  $items = scandir($path);
  if ($items === false) {
    return;
  }

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }

    delete_tree($path . DIRECTORY_SEPARATOR . $item);
  }

  @rmdir($path);
}

$paths = array_filter([
  '/tmp/simplesaml',
  getenv('HOME') ? rtrim(getenv('HOME'), '/') . '/tmp/simplesaml' : NULL,
]);

$paths = array_values(array_unique($paths));

echo "Clearing SimpleSAMLphp temp/cache directories...\n";

foreach ($paths as $path) {
  if (file_exists($path) || is_link($path)) {
    echo "Removing {$path}\n";
    delete_tree($path);
  }
  else {
    echo "Skipping {$path} (not present)\n";
  }
}

echo "SimpleSAMLphp cache cleanup complete.\n";
