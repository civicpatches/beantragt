<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\EingangRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\Core\Database\Connection;

/**
 * Removes every identifier, record, receipt record and event of an application.
 */
class NachreichungCleanup {

  /**
   * Constructs the cleanup.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Deletes all rows of an application in the three tables.
   */
  public function forApplication(int $application_id): void {
    foreach ([
      CoverCodeRepository::TABLE,
      NachforderungRepository::TABLE,
      NachreichungEventLog::TABLE,
      EingangRepository::TABLE,
    ] as $table) {
      if ($this->database->schema()->tableExists($table)) {
        $this->database->delete($table)->condition('application_id', $application_id)->execute();
      }
    }
  }

}
