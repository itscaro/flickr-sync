#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Itscaro\App;

define('ROOTDIR', dirname(__DIR__));
define('BINDIR', ROOTDIR . '/bin');
define('SRCDIR', ROOTDIR . '/src');

$app = new App\Application();
$app->loadConfigFromFile(SRCDIR . '/config.yml');
$config = $app->getConfig();

$app->setConfig($config);

$app->setName($config['app']['name']);
$app->setVersion($config['app']['version']);
$app->addCommands(array(
    new App\Flickr\Upload(),
    new App\Flickr\Sync(),
    new App\Flickr\Verify(),
));
$app->run();
