<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

Co\Run(function() {
  $c1 = new \chan(1);
  $c2 = new \chan(1);

  $t1 = swoole_timer_tick(1000, function() use ($c1) {
    print "Pushing c1\n";
    $c1->push('c1');
  });
  $t2 = swoole_timer_tick(333, function() use ($c2) {
    print "Pushing c2\n";
    $c2->push('c2');
  });

  while (true) {
    print PHP_EOL.PHP_EOL;
    $t = new Swoozle\TimerChan(.2);
    $c = Swoozle\Chan::select($c1, $c2, $t);
    switch (true) {
    case $c->chan === $c1:
      print "[userland.gotSelect] Got c1v: " . var_export($c->value, true) . PHP_EOL;
      break;
    case $c->chan === $c2:
      print "[userland.gotSelect] Got c2v: " . var_export($c->value, true) . PHP_EOL;
      break;
    case $c->chan === $t:
    case $c->chan === Swoozle\Chan::SELECT_NONE:
      print "[userland.gotSelect] Nothing selected\n";
      break;
    }
    $t->clear();
  }

  swoole_timer_clear($t1);
  swoole_timer_clear($t2);
});
