<?php declare(strict_types=1);

namespace Swoozle\Mutex;

use Swoozle\Mutex\IMutex;

/**
 * High-performance lock using channels.
 * Can achieve 50k TPS.
 */
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

