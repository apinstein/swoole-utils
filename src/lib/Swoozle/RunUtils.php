<?php declare(strict_types=1);

namespace Swoozle;

use Swoole\Coroutine;

class RunUtils {
  protected static array $coroutines = [];
  protected static array $timers = [];

  public static function registerCoroutine(string $desc):void {
    $callerCID = Coroutine::getCid();
    self::$coroutines[$callerCID] = [
      'registeredAt'    => microtime(true),
      'description'     => $desc,
      'location'        => debug_backtrace()[0]['file'] .':'. debug_backtrace()[0]['line'],
      'parent'          => Coroutine::getPcid(),
    ];
    // this can use a lot of memory, so prune our records periodically
    if ($callerCID % 500 === 0) {
      $pruneThese = array_diff(array_keys(self::$coroutines), (array) Coroutine::list());
      foreach ($pruneThese as $retiredCID) {
        unset(self::$coroutines[$retiredCID]);
      }
    }
  }

  public static function registerTimer(int $timerId, string $desc):void {
    self::$timers[$timerId] = [
      'description' => $desc,
      'location'    => debug_backtrace()[0]['file'] .':'. debug_backtrace()[0]['line'],
    ];
    // this can use a lot of memory, so prune our records periodically
    if ($timerId % 500 === 0) {
      $pruneThese = array_diff(array_keys(self::$timers), (array) \Swoole\Timer::list());
      foreach ($pruneThese as $retiredCID) {
        unset(self::$timers[$retiredCID]);
      }
    }
  }

  public static function watch():void {
    $coroutineDebugTimerId = NULL;
    $coroutineDebugTimerId = swoole_timer_tick(1000, function(&$coroutineDebugTimerId) {
      $list = Coroutine::list();
      $thisCID = Coroutine::getCid();
      $list = array_diff((array) $list, [$thisCID]);
      sort($list);
      if (count($list) === 0) {
        print "[Coroutine debugger] No coroutines running.\n";
        swoole_timer_clear($coroutineDebugTimerId);
      } else {
        print "[Coroutine debugger] Currently " . count($list) . " coroutines (besides this one):\n";
        foreach ($list as $cid) {
          $coInfo = self::$coroutines[$cid] ?? [
            'registeredAt'  => '(unknown)',
            'description'   => '(no desc)',
            'location'      => '(unknown)',
            'parent'        => '(unknown parent)',
          ];
          print "[{$cid}] [parent={$coInfo['parent']}] {$coInfo['description']} @ {$coInfo['location']}, registered at {$coInfo['registeredAt']}" . PHP_EOL;
        }
      }
    });
  
    $timerDebugTimerId = NULL;
    $timerDebugTimerId = swoole_timer_tick(1000, function(&$timerDebugTimerId) {
      $list = \Swoole\Timer::list();
      $list = array_diff((array) $list, [$timerDebugTimerId]);
      sort($list);
      if (count($list) === 0) {
        print "[Timer debugger] No timers running.\n";
        swoole_timer_clear($timerDebugTimerId);
      } else {
        print "[Timer debugger] Currently " . count($list) . " timers (besides this one):\n";
        foreach ($list as $tid) {
          $timerInfo = self::$timers[$tid] ?? [
            'description' => '(no desc)',
            'location'    => '(unknown)',
          ];
          print "[{$tid}] {$timerInfo['description']} @ {$timerInfo['location']}\n";
        }
      }
    });
  }
}
