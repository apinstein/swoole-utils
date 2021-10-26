<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;

use Swoozle\Mutex\IMutex;
use Swoozle\Mutex\Mutex;

Swoole\Coroutine::set([
  'log_level'  => SWOOLE_LOG_NONE,
  'hook_flags' => SWOOLE_HOOK_ALL,
]);

/***
 * Notes
 * - time_nanosleep() to yield to other coroutines is far more "balanced" than \Swoole\Coroutine\System::sleep(Swoole\Utils::TIMEOUT_MIN);
 * - Swoole docs recommend using system sleep calls when SLEEP is hooked.
 */

class FakeMutex implements IMutex {
  public function lock():void {}
  public function unlock():void {}
}

class ReaderCountRWLock {
  private int $readerCount = 0;
  private Mutex $m;
  public function __construct() {
    $this->m = new Mutex();
  }
  public function rlock() {
    $this->m->lock();
    $this->readerCount++;
    $this->m->unlock();
  }
  public function runlock() {
    $this->m->lock();
    $this->readerCount--;
    $this->m->unlock();
  }
}

class RWMutex implements IMutex {
  private Mutex $rwlock;
  private ReaderCountRWLock $rlock;
  private ?\chan $rwlockAvailable;

  public function __construct() {
    $this->rwlockAvailable = NULL;
    $this->rwlock = new Mutex();
  }
  // rwlock
  public function lock():void {
    $this->rwlock->lock();
    if ($this->rlockCount === 0) {
      return;
    } else {
      $this->rwlockAvailable = new \chan();
      $this->rwlock->unlock();
      $this->rwlockAvailable->pop();
      $this->rwlock->lock();
      $this->rwlockAvailable = NULL;
      return;
    }
  }
  // rwlock
  public function unlock():void {
    $this->rwlock->unlock();
  }

  // rlock
  public function rlock():void {
    $this->rwlock->lock();
    $this->rlockCount++;
    $this->rwlock->unlock();
  }
  // rlock
  public function runlock():void {
    $this->rwlock->lock();
    $this->rlockCount--;
    if ($this->rwlockAvailable) {
      $this->rwlockAvailable->push(true);
    }
    $this->rwlock->unlock();
  }
}

class SharedData {
  public int $value = 0;
  public int $rcount = 0;
  public IMutex $lock;

  public function __construct() {
    $this->lock = new Mutex;
  }
}

define("RUN_FOR_SECONDS", 2);
Co\run(function() {
  $sharedData = new SharedData;
  $wg = new \Swoole\Coroutine\WaitGroup;

  // one function is constantly adding data
  $writeCounter = [
    'set1' => 0,
    'set2' => 0
  ];
  go(function() use ($sharedData, $wg, &$writeCounter) {
    $wg->add();
    $stopAt = microtime(true) + RUN_FOR_SECONDS;
    while (microtime(true) < $stopAt) {
      //print "WAITING TO ADD\n";
      $sharedData->lock->lock();
      $sharedData->value = rand(1,1000);
      //print '+ SET 1'.PHP_EOL;
      $writeCounter['set1']++;
      $sharedData->lock->unlock();
      //print "UNLOCKED\n";
      time_nanosleep(0,1);
    }
    $wg->done();
  });

  // one function is constantly wiping all data
  go(function() use ($sharedData, $wg, &$writeCounter) {
    $wg->add();
    $stopAt = microtime(true) + RUN_FOR_SECONDS;
    while (microtime(true) < $stopAt) {
      $sharedData->lock->lock();
      $sharedData->value = rand(1,1000);
      //print '+ SET 2'.PHP_EOL;
      $writeCounter['set2']++;
      $sharedData->lock->unlock();
      time_nanosleep(0,1);
      password_hash("foo", PASSWORD_BCRYPT, ['cost'=>4]);
    }
    $wg->done();
  });

  // one function is constantly using the data and subject to the races
  $spawnReaderF = function($i, $sharedData, &$counter, $wg, &$raceDetectedCount) {
    go(function() use ($i, $sharedData, &$counter, $wg, &$raceDetectedCount) {
      $wg->add();
      $stopAt = microtime(true) + RUN_FOR_SECONDS;
      while (microtime(true) < $stopAt) {
        $sharedData->lock->lock();
        //print "- Reader#{$i}".PHP_EOL;
        $counter[$i-1]++;
        $preSleepValue = $sharedData->value;
        time_nanosleep(0,1);
        $postSleepValue = $sharedData->value;
        //print ">> {$preSleepValue} {$postSleepValue}\n";
        if ($preSleepValue !== $postSleepValue) {
          print "!!!!!!!!!!!!!!!!!!!!!!!   race detected   !!!!!!!!!!!!!!!!!!!!\n";
          $raceDetectedCount++; // ironically has race, but it's ok for this test
        }
        $sharedData->lock->unlock();
      }
      $wg->done();
    });
  };

  $t0 = microtime(true);
  $counter = [];
  $raceDetectedCount = 0;
  foreach (range(1,10) as $i) {
    $counter[] = 0;
    $spawnReaderF($i, $sharedData, $counter, $wg, $raceDetectedCount);
  }
  $wg->wait();

  $t = microtime(true) - $t0;
  print "Elapsed Time: {$t}s\n";

  print "Races detected: {$raceDetectedCount}\n";

  $totalReadCount = array_sum($counter);
  print "Total Reads: {$totalReadCount}\n";

  print "Reads per reader:\n";
  print_r($counter);

  $totalWriteCount = array_sum($writeCounter);
  print "Total Writes: {$totalWriteCount}\n";

  print "Writes per writer:\n";
  print_r($writeCounter);

  $tps = (int) (($totalReadCount+$totalWriteCount)/$t);
  $rtps = (int) ($totalReadCount/$t);
  $wtps = (int) ($totalWriteCount/$t);
  print "\n";
  print "Reads: {$rtps}/s\n";
  print "Writes: {$wtps}/s\n";
  print "TPS: {$tps}/s\n";

});
