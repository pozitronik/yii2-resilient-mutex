<?php
declare(strict_types=1);

// Composer autoloader
use yii\console\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Yii2 application mock для совместимости с Component
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Создаем минимальное приложение для тестов
new Application([
    'id' => 'resilient-mutex-test',
    'basePath' => dirname(__DIR__),
]);
