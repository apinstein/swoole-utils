<?php declare(strict_types=1);

use Swoole\Coroutine;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Co\run(function() {
  go(function($str) {
    print "Go says: {$str}".PHP_EOL;
  }, "Hello, World!");

  $str = "use Hello, World!";
  go(function() use ($str) {
    print "Go says: {$str}".PHP_EOL;
  });
});
