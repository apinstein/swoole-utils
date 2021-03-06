<?php declare(strict_types=1);

namespace Swoozle;

class SelectReturn {
  public function __construct(
    public ?\chan $chan,
    public mixed $value
  ) {}
}

class Chan {
  public const TIMEOUT_MIN = 0.001;
  public const TIMEOUT_NONE = -1;
  public const SELECT_NONE = NULL;
  /**
   * Usage:
   * 1) Blocking style: blocks until one of the channels has a value or closes;
   *
   *  select($c1, $c2) => SelectReturn
   *
   *  while (true) {
   *    $c = Swoozle\Chan::select($c1, $c2);
   *    switch (true) {
   *    case $c->chan === $c1:
   *      print "[userland.gotSelect] Got c1v: " . var_export($c->value, true) . PHP_EOL;
   *      break;
   *    case $c->chan === $c2:
   *      print "[userland.gotSelect] Got c2v: " . var_export($c->value, true) . PHP_EOL;
   *      break;
   *    case $c->chan === Swoozle\Chan::SELECT_NONE:
   *      die("NEVER HAPPENS");
   *      break;
   *    }
   *  }
   *
   * 2) Non-blocking style: blocks until one of the channels has a value or closes; or the passed timeout is hit
   *  select($c1, $c2, $timeoutS) => SelectReturn
   *
   *  while (true) {
   *    $c = Swoozle\Chan::select($c1, $c2, 1);
   *    switch (true) {
   *    case $c->chan === $c1:
   *      print "[userland.gotSelect] Got c1v: " . var_export($c->value, true) . PHP_EOL;
   *      break;
   *    case $c->chan === $c2:
   *      print "[userland.gotSelect] Got c2v: " . var_export($c->value, true) . PHP_EOL;
   *      break;
   *    case $c->chan === Swoozle\Chan::SELECT_NONE:
   *      print "[userland.gotSelect] Nothing selected within 1 second\n";
   *      break;
   *    }
   *  }
   *
   */
  public static function select(...$chans) {
    // determine timeout: blocking / non-blocking
    $timeoutS = self::TIMEOUT_NONE;
    // is last arg a timeout val?
    if (func_num_args() > 1) {
      $lastArg = func_get_arg(func_num_args()-1);
      if (is_float($lastArg) || is_int($lastArg)) {
        $timeoutS = (float) array_pop($chans);
      }
    }

    // make sure there are channels!
    if (count($chans) === 0) throw new \Exception("Must pass 1 or more channels");

    // filter out NULL channels
    $chans = array_filter($chans);
    $chanCount = count($chans);
    $maxChanI = $chanCount - 1;
    $chanI = 0;
    $timePastThisIsTimedout = microtime(true) + $timeoutS;
    while (true) {
      // shuffle the chans so that select acts as a random sampler of available data
      shuffle($chans);
      $chan = $chans[$chanI];
      $val = $chan->pop(self::TIMEOUT_MIN);
      // We use very short timeouts to implement select behavior in pure php;
      // SWOOLE_CHANNEL_TIMEOUT is expected frequently; we implement select's timeout functionality here...
      if ($chan->errCode === SWOOLE_CHANNEL_TIMEOUT) {
        if ($timeoutS !== self::TIMEOUT_NONE && microtime(true) > $timePastThisIsTimedout) {
          return new SelectReturn(NULL, NULL);
        } // else, continue looking at other channels
      } else {
        return new SelectReturn($chan, $val);
      }
      $chanI++;
      if ($chanI > $maxChanI) {
        $chanI = 0;
      }
    }
  }
}

