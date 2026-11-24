<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Commands;

use Drupal\beantragt_application\Service\RetentionCleanupService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for application retention operations.
 */
final class RetentionCommands extends DrushCommands {

  /**
   * Constructs the command handler.
   */
  public function __construct(
    private readonly RetentionCleanupService $retentionCleanup,
  ) {
    parent::__construct();
  }

  /**
   * Runs application retention cleanup or reports candidates.
   *
   * @command beantragt:retention
   * @aliases beantragt-retention
   * @option dry-run Report candidates without deleting entities or files.
   * @option limit Maximum application entities processed across policies.
   * @option json Print the structured report as JSON.
   * @usage drush beantragt:retention --dry-run --json
   *   Print retention candidates without deleting data.
   */
  public function retention(
    array $options = [
      'dry-run' => FALSE,
      'limit' => RetentionCleanupService::DEFAULT_LIMIT,
      'json' => FALSE,
    ],
  ): void {
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);
    $limit = (int) ($options['limit'] ?? RetentionCleanupService::DEFAULT_LIMIT);
    $report = $this->retentionCleanup->run($dryRun, $limit);

    if ((bool) ($options['json'] ?? FALSE)) {
      $this->io()->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return;
    }

    $this->io()->title($dryRun ? 'Application retention dry-run' : 'Application retention cleanup');
    $this->io()->table(
      ['Resource', 'Matched', 'Deleted'],
      array_map(
        static fn(string $resource, array $count): array => [
          $resource,
          (string) ($count['matched'] ?? 0),
          (string) ($count['deleted'] ?? 0),
        ],
        array_keys($report['counts']),
        $report['counts'],
      ),
    );
  }

}
