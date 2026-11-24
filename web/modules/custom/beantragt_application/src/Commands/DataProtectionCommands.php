<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Commands;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush command for resuming the application data protection update.
 */
final class DataProtectionCommands extends DrushCommands {

  /**
   * Constructs the command handler.
   */
  public function __construct(private readonly ModuleHandlerInterface $moduleHandler) {
    parent::__construct();
  }

  /**
   * Retries application rows with unavailable form definitions in update 10042.
   *
   * @command beantragt:retry-data-protection
   */
  public function retryDataProtection(): int {
    $this->moduleHandler->loadInclude('beantragt_application', 'install');
    $result = beantragt_application_retry_data_protection();
    $this->io()->writeln(sprintf(
      'Encrypted %d application storage rows. %d applications still await encryption.',
      $result['updated'],
      $result['pending'],
    ));
    return $result['pending'] > 0 ? 1 : 0;
  }

}
