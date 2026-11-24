<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Commands;

use Drupal\beantragt_delivery\Service\FitConnectReplyPoller;
use Drush\Commands\DrushCommands;

/**
 * Bounded FIT-Connect reply import command.
 */
final class ReplyCommands extends DrushCommands {

  public function __construct(private readonly FitConnectReplyPoller $poller) {
    parent::__construct();
  }

  /**
   * Fetches replies, verifies and stores them, then confirms technical receipt.
   *
   * @command beantragt:delivery:replies:poll
   * @option json Print secret-free JSON counters.
   */
  public function poll(array $options = ['json' => FALSE]): void {
    $this->output()->writeln(json_encode($this->poller->poll(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
  }

}
