<?php

/**
 * @file
 * Creates the shared_content_deletion_log table.
 *
 * Usage: drush scr create-audit-table.php
 */

$schema = \Drupal::database()->schema();

if ($schema->tableExists('shared_content_deletion_log')) {
  echo "Table already exists.\n";
  return;
}

$schema->createTable('shared_content_deletion_log', [
  'fields' => [
    'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
    'nid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
    'uuid' => ['type' => 'varchar', 'length' => 128],
    'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
    'title' => ['type' => 'varchar', 'length' => 255],
    'status' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 1],
    'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
    'username' => ['type' => 'varchar', 'length' => 128],
    'trigger_type' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => 'unknown'],
    'request_uri' => ['type' => 'varchar', 'length' => 2048],
    'request_method' => ['type' => 'varchar', 'length' => 16],
    'content_hash' => ['type' => 'varchar', 'length' => 255],
    'xml_source' => ['type' => 'text', 'size' => 'medium'],
    'is_shared' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
    'trace_summary' => ['type' => 'varchar', 'length' => 2048],
    'trace_full' => ['type' => 'text', 'size' => 'medium'],
    'timestamp' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
  ],
  'primary key' => ['id'],
  'indexes' => [
    'nid' => ['nid'],
    'bundle' => ['bundle'],
    'trigger_type' => ['trigger_type'],
    'timestamp' => ['timestamp'],
    'content_hash' => ['content_hash'],
    'is_shared' => ['is_shared'],
  ],
]);

echo "Table shared_content_deletion_log created successfully.\n";
