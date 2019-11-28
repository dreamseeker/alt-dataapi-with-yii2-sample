<?php

// comment out the following two lines when deployed to production
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

// @NOTE 環境に応じて変更
define('YII2_CORE_DIR', '/data/basic/');
define('MT7_DIR', '/data/mt/');

require YII2_CORE_DIR . 'vendor/autoload.php';
require YII2_CORE_DIR . 'vendor/yiisoft/yii2/Yii.php';

$config = require YII2_CORE_DIR . 'config/web.php';

(new yii\web\Application($config))->run();
