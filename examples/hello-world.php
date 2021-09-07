<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Swoozle\RunUtils;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Co\run(function() {
    // You can call a go() routine with arguments
    $str = "Hello, World!";
    go(function($str) {
        RunUtils::registerCoroutine("F1");

        print "Go says: {$str}".PHP_EOL;
    }, $str);

    // Or, you can "use" variables.
    go(function() use ($str) {
        print "Go says: {$str} (via use)".PHP_EOL;
    });

    RunUtils::watch();
});

