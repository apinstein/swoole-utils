<?php declare(strict_types=1);

namespace Swoozle;

/**
 * A timer channel, which will push the current time at timer expiration.
 * TimerChan doesn't close after the timer fires.
 */
class TimerChan extends \chan {
  protected ?int $timer_id = NULL;
  public function __construct(float $timerS) {
    parent::__construct();

    $this->timer_id = \Swoole\Timer::after((int) (1000 * $timerS), function() {
      $this->push(true);
    });
    RunUtils::registerTimer($this->timer_id, "TimerChan ({$timerS}s)");
  }

  public function __destruct() {
    $this->clear();
  }

  public function clear() {
    if ($this->timer_id) {
      \Swoole\Timer::clear($this->timer_id);
      $this->timer_id = NULL;
    }
  }
}
