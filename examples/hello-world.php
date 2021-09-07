<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Co\run(function() {
    // You can call a go() routine with arguments
    $str = "Hello, World!";
    go(function($str) {
        Swoozle\RunUtils::registerCoroutine("F1");

        print "Go says: {$str}".PHP_EOL;

        sleep(2);
    }, $str);

    // Or, you can "use" variables.
    go(function() use ($str) {
        Swoozle\RunUtils::registerCoroutine("F2");

        print "Go says: {$str} (via use)".PHP_EOL;

        sleep(2);
    });

    Swoozle\RunUtils::watch();
});

