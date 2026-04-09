<?php

if (!ini_get('session.save_handler')) {
    ini_set('session.save_handler', 'file');
};

// Load necessary environmental data
$host = $_SERVER['HTTP_HOST'];
$db = array(
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_NAME'],
    'username'  => $_ENV['DB_USER'],
    'password'  => $_ENV['DB_PASSWORD'],
    'port'      => $_ENV['DB_PORT'],
);


// $_SERVER['SERVER_PORT'] = 443;
// $_SERVER['HTTPS'] = 'true';
// $port = ':' . $_SERVER['SERVER_PORT'];

// With the basic variables defined, set up the base config
$config = [
     'baseurlpath' => 'https://'. $host .'/simplesaml/', // SAML should always connect via 443
     'certdir' => 'cert/',
     'logging.handler' => 'errorlog',
     'datadir' => 'data/',
     'cachedir' => $_ENV['HOME'] . '/tmp/simplesaml',

    'secretsalt' => '<spry-value:config.secretsalt>',

    /*
     * This password must be kept secret, and modified from the default value 123.
     * This password will give access to the installation page of SimpleSAMLphp with
     * metadata listing and diagnostics pages.
     * You can also put a hash here; run "bin/pwgen.php" to generate one.
     */

    'auth.adminpassword' => 'A98#-9SDpItdr-07eh56KVV1Zi)bBS&0268<660)',
    /*'auth.adminpassword' => '$argon2id$v=19$m=65536,t=4,p=1$89tg/8Lhi6I+P0Hzr2jA3g$qj37GQV+Su07WaeohLjEfyVw2K/J47M9ATxO+2DSoWE',
    /*
     * Set this options to true if you want to require administrator password to access the web interface
     * or the metadata pages, respectively.
     */
    'admin.protectindexpage' => true,
    'admin.protectmetadata' => false ,

     // Your $config array continues for a while...
     // until we get to the "store.type" value, where we put in DB config...
     'store.type' => 'sql',
     'store.sql.dsn' => 'mysql:host='. $db['host'] .';port='. $db['port'] .';dbname='. $db['database'],
     'store.sql.username' => $db['username'],
     'store.sql.password' => $db['password'],
];

/*
   * Perform any global overrides
   */

