<?php declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Util/Swoole/Utils.php';

use Swoole\Coroutine;
use SneakyStu\Util\Swoole;

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/***
 * Notes
 * - time_nanosleep() to yield to other coroutines is far more "balanced" than \Swoole\Coroutine\System::sleep(Swoole\Utils::TIMEOUT_MIN);
 * - Swoole docs recommend using system sleep calls when SLEEP is hooked.
 */

// https://eli.thegreenplace.net/2019/implementing-reader-writer-locks/
Interface IMutex {
  public function lock():void;
  public function unlock():void;
}

class FakeMutex implements IMutex {
  public function lock():void {}
  public function unlock():void {}
}

class Mutex implements IMutex {
  private \chan $lock;
  public function __construct() {
    $this->lock = new \chan();
  }
  public function lock():void {
    $this->lock->push(true);
  }
  public function unlock():void {
    $this->lock->pop();
  }
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
  public array $data = [];
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
    'add'  => 0,
    'wipe' => 0
  ];
  go(function() use ($sharedData, $wg, &$writeCounter) {
    $wg->add();
    $stopAt = microtime(true) + RUN_FOR_SECONDS;
    while (microtime(true) < $stopAt) {
      //print "WAITING TO ADD\n";
      $sharedData->lock->lock();
      $sharedData->data[] = rand(1,1000);
      print '+ ADD'.PHP_EOL;
      $writeCounter['add']++;
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
      $sharedData->data = [];
      print '+ WIPE'.PHP_EOL;
      $writeCounter['wipe']++;
      $sharedData->lock->unlock();
      time_nanosleep(0,1);
    }
    $wg->done();
  });

  // one function is constantly using the data and subject to the races
  $spawnReaderF = function($i, $sharedData, &$counter, $wg) {
    go(function() use ($i, $sharedData, &$counter, $wg) {
      $wg->add();
      $stopAt = microtime(true) + RUN_FOR_SECONDS;
      while (microtime(true) < $stopAt) {
        $sharedData->lock->lock();
        print "- Reader#{$i}".PHP_EOL;
        $counter[$i-1]++;
        $sharedDataLength = count($sharedData->data);
        $sharedDataLength2 = count($sharedData->data);
        if ($sharedDataLength != $sharedDataLength2) {
          print "!!!!!!!!!!!!!!!!!!!!!!!   race detected   !!!!!!!!!!!!!!!!!!!!\n";
        }
        $sharedData->lock->unlock();
        time_nanosleep(0,1);
      }
      $wg->done();
    });
  };

  $t0 = microtime(true);
  $counter = [];
  foreach (range(1,5) as $i) {
    $counter[] = 0;
    $spawnReaderF($i, $sharedData, $counter, $wg);
  }
  $wg->wait();

  $t = microtime(true) - $t0;
  print "Elapsed Time: {$t}s\n";

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
