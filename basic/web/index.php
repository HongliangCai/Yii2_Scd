<?php

// comment out the following two lines when deployed to production
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../vendor/autoload.php'; //使用composer的类自动加载功能
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php'; //使用Yii的提供的各种工具

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
