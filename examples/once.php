<?php declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Util/Swoole/Utils.php';

use Swoole\Coroutine;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Co\run(function() {
  $dc = function($chan, $label = 'Channel Info') {
    print "==== {$label} ====\n";
    print_r($chan->stats());
    print "Empty: {$chan->isEmpty()}\nFull: {$chan->isFull()}\nLength: {$chan->length()}\n";
    print "====\n";
  };

  $once = new \chan(0);
  $once->push(true);  // load with 1 -- interestingly non-blocking in swoole

  $onceF = function(\chan $once, $i) {
    print "Got to be starting something {$i}\n";
    $once->pop(); // block until avail
    print "\n\nDoing something {$i} n=1\n";
    \Swoole\Coroutine\System::sleep(rand(1,1000)/1000);
    print "Done n=1\n";
    $once->push(true);
  };

  foreach (range(1,100) as $i) {
    go(function() use ($i, $once, $onceF) {
      print "Enqueuing $i\n";
      $onceF($once, $i);
    });
  }

});
