<?php declare(strict_types=1);

namespace Swoozle\Mutex;

// https://eli.thegreenplace.net/2019/implementing-reader-writer-locks/
Interface IMutex {
  public function lock():void;
  public function unlock():void;
}


