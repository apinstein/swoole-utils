<?php declare(strict_types=1);

namespace Swoozle;

use PHPUnit\Framework\TestCase;

use Swoozle\Mutex\Mutex;
use Swoozle\Mutex\IMutex;

define("RUN_FOR_SECONDS", 0.5);
class FakeMutex implements IMutex {
  public function lock():void {}
  public function unlock():void {}
}

class SharedData {
  public int $value = 0;
  public int $rcount = 0;
  public IMutex $lock;

  public function __construct(IMutex $mutex) {
    $this->lock = $mutex;
  }
}

final class MutexTest extends TestCase
{
  public function mutexProvider():array {
    return [
      'Fake Mutex should have races' => [new FakeMutex, true],
      'Real Mutex should not have races' => [new Mutex, false],
    ];
  }

  /**
   * @dataProvider mutexProvider
   */
  public function testMutexPreventsRaceConditionsAccessingSharedData($mutex, $expectRaces) {
    $raceDetectedCount = 0;
    \Co\run(function() use (&$raceDetectedCount, $mutex) {
      $sharedData = new SharedData($mutex);
      $wg = new \Swoole\Coroutine\WaitGroup;

      // one function is constantly adding data
      go(function() use ($sharedData, $wg) {
        $wg->add();
        $stopAt = microtime(true) + RUN_FOR_SECONDS;
        while (microtime(true) < $stopAt) {
          //print "WAITING TO ADD\n";
          $sharedData->lock->lock();
          $sharedData->value = rand(1,1000);
          //print '+ SET 1'.PHP_EOL;
          $sharedData->lock->unlock();
          //print "UNLOCKED\n";
          time_nanosleep(0,1);
        }
        $wg->done();
      });

      // one function is constantly wiping all data
      go(function() use ($sharedData, $wg) {
        $wg->add();
        $stopAt = microtime(true) + RUN_FOR_SECONDS;
        while (microtime(true) < $stopAt) {
          $sharedData->lock->lock();
          $sharedData->value = rand(1,1000);
          //print '+ SET 2'.PHP_EOL;
          $sharedData->lock->unlock();
          time_nanosleep(0,1);
        }
        $wg->done();
      });

      // one function is constantly using the data and subject to the races
      $spawnReaderF = function($i, $sharedData, $wg, &$raceDetectedCount) {
        go(function() use ($i, $sharedData, $wg, &$raceDetectedCount) {
          $wg->add();
          $stopAt = microtime(true) + RUN_FOR_SECONDS;
          while (microtime(true) < $stopAt) {
            $sharedData->lock->lock();
            //print "- Reader#{$i}".PHP_EOL;
            $preSleepValue = $sharedData->value;
            time_nanosleep(0,1);
            $postSleepValue = $sharedData->value;
            //print ">> {$preSleepValue} {$postSleepValue}\n";
            if ($preSleepValue !== $postSleepValue) {
              //print "!!!!!!!!!!!!!!!!!!!!!!!   race detected   !!!!!!!!!!!!!!!!!!!!\n";
              $raceDetectedCount++; // ironically has race, but it's ok for this test
            }
            $sharedData->lock->unlock();
          }
          $wg->done();
        });
      };

      $raceDetectedCount = 0;
      foreach (range(1,10) as $i) {
        $spawnReaderF($i, $sharedData, $wg, $raceDetectedCount);
      }
      $wg->wait();
    });

    if ($expectRaces) {
      $this->assertNotEquals(0, $raceDetectedCount);
    } else {
      $this->assertEquals(0, $raceDetectedCount);
    }
  }
}
