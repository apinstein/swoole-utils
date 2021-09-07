<?php declare(strict_types=1);

use Swoole\Coroutine;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Co\run(function() {
  // You can call a go() routine with arguments
  $str = "Hello, World!";
  go(function($str) {
    print "Go says: {$str}".PHP_EOL;
  }, $str);

  // Or, you can "use" variables.
  go(function() use ($str) {
    print "Go says: {$str} (via use)".PHP_EOL;
  });
});
