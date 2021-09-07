<?php declare(strict_types=1);

namespace Swoole\Util;

use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase
{
  public function testReturnsNonEmptyChannel() {
    $expectedValue = "FOO";
    $poppedValue = NULL;

    \Co\run(function() use (&$poppedValue) {
      $c1 = new \chan(0);
      $c1->push("FOO");

      $c = Utils::chan_select($c1);
      $poppedValue = $c->value;
    });
    $this->assertEquals($expectedValue, $poppedValue);
  }

  public function testReturnsNullChannelAfterTimeoutInNonBlockingMode() {
    $expectedValue = NULL;
    $result = NULL;

    \Co\run(function() use (&$result) {
      $c1 = new \chan(0);

      $result = Utils::chan_select($c1, 0.01);
    });
    $this->assertNull($result->chan);
    $this->assertNull($result->value);
  }

  public function testReturnsNonEmptyChannelWithMultipleChannelsButOnlyOneNotEmpty() {
    $expectedValue = "FOO";
    $poppedValue = NULL;

    \Co\run(function() use (&$poppedValue) {
      $c1 = new \chan(0);
      $c2 = new \chan(0);
      $c1->push("FOO");

      $c = Utils::chan_select($c1, $c2);
      $poppedValue = $c->value;
    });
    $this->assertEquals($expectedValue, $poppedValue);
  }

  public function testReturnsRandomNonEmptyChannelWithMultipleNonEmptyChannels() {
    $expectedValue1 = "FOO";
    $expectedValue2 = "BAR";
    $poppedValue1 = NULL;
    $poppedValue2 = NULL;

    \Co\run(function() use (&$poppedValue1, &$poppedValue2) {
      $c1 = new \chan(0);
      $c2 = new \chan(0);
      $c1->push("FOO");
      $c2->push("BAR");

      $c = Utils::chan_select($c1, $c2);
      $poppedValue1 = $c->value;
      $c = Utils::chan_select($c1, $c2);
      $poppedValue2 = $c->value;
    });

    $returns = [$poppedValue1, $poppedValue2];
    sort($returns);

    $this->assertEquals(["BAR", "FOO"], $returns);
  }

  public function testPollsIfEmptyChans() {
    $expectedValue = range(1,100);
    $resultValue = NULL;

    \Co\run(function() use (&$resultValue, $expectedValue) {
      $c1 = new \chan(0);
      $c2 = new \chan(0);

      // https://stackoverflow.com/questions/13666253/breaking-out-of-a-select-statement-when-all-channels-are-closed
      go(function() use ($c1, $c2, &$resultValue) {
        while (true) {
          $c = Utils::chan_select($c1, $c2);
          switch (true) {
          case $c->chan === $c1:
            if ($c->chan->errCode === SWOOLE_CHANNEL_CLOSED) {
              $c1 = NULL;
            }
            break;
          case $c->chan === $c2:
            if ($c->chan->errCode === SWOOLE_CHANNEL_CLOSED) {
              $c2 = NULL;
            }
            break;
          }
          if ($c->chan->errCode === SWOOLE_CHANNEL_OK) {
            $resultValue[] = $c->value;
          }
          if ($c1 === NULL && $c2 === NULL) {
            break;
          }
        }
      });

      go(function() use ($c1, $c2, $expectedValue) {
        foreach ($expectedValue as $n) {
          $cI = rand(1,2);
          $c = match($cI) {
            1 => $c1,
            2 => $c2
          };
          $c->push($n);
        }
        $c1->close();
        $c2->close();
      });

    });

    sort($resultValue);
    $this->assertEquals($expectedValue, $resultValue);
  }

  /**
   * Make sure that if the first channel passed closes it doesn't infinitely block the 2nd
   */
  public function testSearchesChannelsForValueInRandomOrder() {
    $expectedVal = "select channel 2";
    $returnedVal = "wrong answer";

    \Co\run(function() use (&$expectedVal, &$returnedVal) {
      $c1 = new \chan(0);
      $c1->close();
      $c2 = new \chan(0);
      $c2->push($expectedVal);
      while ($returnedVal !== $expectedVal) {
        $selectedChan = Utils::chan_select($c1, $c2);
        $returnedVal = $selectedChan->value;
      }
    });
    $this->assertEquals($expectedVal, $returnedVal);
  }

  public function testHandlesTimeout() {
    $timeoutChan = new \chan(0);
    $returnedChan = NULL;
    \Co\run(function() use (&$returnedChan, $timeoutChan) {
      $c1 = new \chan(0);
      $c2 = new \chan(0);

      go(function() use ($timeoutChan) {
        \Swoole\Coroutine\System::sleep(0.2);
        $timeoutChan->close();
      });

      $c = Utils::chan_select($c1, $c2, $timeoutChan);
      $returnedChan = $c->chan;
    });

    $this->assertEquals($timeoutChan, $returnedChan);
  }
}
