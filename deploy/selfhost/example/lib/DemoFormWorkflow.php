<?php

/**
 * @file
 * Builds the own workflow of a demo form from the catalogue it lands in.
 */

declare(strict_types=1);

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Form workflows of the demo forms that need more than the standard.
 *
 * Since the three-status standard a form without own workflow sees only the statuses reachable
 * from the default status. The demo forms with a case system, a notice or a
 * request for documents switch library statuses on in their own workflow.
 * The catalogue an application lands in decides the machine names (the core
 * seed and the demo seed name statuses differently), so the workflow is built
 * by semantic flag from the catalogue that is actually there, and a missing
 * flag fails loudly instead of being skipped by the resolver.
 *
 * Statuses: the standard (reachable from the default status) plus the first
 * status by weight for each requested flag. Transitions: the catalogue's own
 * edges among those statuses, plus the edges of the form page's function
 * table (the UI workflow helper,
 * WORKFLOW_FEATURES with enableWorkflowFeature()): into each entry status
 * of a switched-on function from every open status of the form that the
 * function does not bring. The functions and their entries by flag:
 * - "Unterlagen nachfordern": request_info;
 * - "Übergabe an ein Fachverfahren": hand_over and in_case_system (the
 *   acceptance of a transfer started on submission or by staff moves the
 *   case to in_case_system from wherever it stands);
 * - "Ergebnis ausweisen": approved and rejected;
 * - "Rücknahme": withdrawn.
 * Flags of no function (decision_issued, filed) are reached over the
 * catalogue's edges or outside the graph (a case system's answer, a
 * released notice).
 */
final class DemoFormWorkflow {

  /**
   * The functions of the form page: flags they bring and their entry flags.
   *
   * Mirrors WORKFLOW_FEATURES of the frontend; keep both equal.
   *
   * @var array<string, array{flags: list<string>, entry: list<string>}>
   */
  public const FEATURES = [
    'requestInfo' => ['flags' => ['request_info'], 'entry' => ['request_info']],
    'handOver' => ['flags' => ['hand_over', 'in_case_system'], 'entry' => ['hand_over', 'in_case_system']],
    'outcome' => ['flags' => ['approved', 'rejected'], 'entry' => ['approved', 'rejected']],
    'withdrawal' => ['flags' => ['withdrawn'], 'entry' => ['withdrawn']],
  ];

  /**
   * Builds the workflow definition for a form in one catalogue.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $manager
   *   The entity type manager.
   * @param int $group_id
   *   The group whose catalogue the form's applications use.
   * @param string[] $flags
   *   Semantic flags whose statuses the form switches on.
   *
   * @return array<string, mixed>
   *   The definition for field_workflow_definition (mode catalog).
   *
   * @throws \RuntimeException
   *   When the catalogue has no default status or no status for a flag.
   */
  public static function definition(EntityTypeManagerInterface $manager, int $group_id, array $flags): array {
    $statuses = self::catalogue($manager, $group_id);
    $default = NULL;
    foreach ($statuses as $status) {
      if ($status->isDefault()) {
        $default = $status;
        break;
      }
    }
    if (!$default instanceof WorkflowStatusInterface) {
      throw new \RuntimeException(sprintf('Group %d has no default workflow status.', $group_id));
    }

    $standard = self::reachable($statuses, (int) $default->id());
    $chosen = $standard;
    foreach ($flags as $flag) {
      $match = NULL;
      foreach ($statuses as $id => $status) {
        if ($status->hasSemanticFlag($flag)) {
          $match = $id;
          break;
        }
      }
      if ($match === NULL) {
        throw new \RuntimeException(sprintf('Group %d has no workflow status with flag "%s".', $group_id, $flag));
      }
      $chosen[$match] = TRUE;
    }

    $transitions = [];
    $add = static function (WorkflowStatusInterface $from, WorkflowStatusInterface $to) use (&$transitions): void {
      if ((int) $from->id() === (int) $to->id() || $from->isTerminal()) {
        return;
      }
      $transitions[$from->getMachineName() . '>' . $to->getMachineName()] = [
        'from' => $from->getMachineName(),
        'to' => $to->getMachineName(),
      ];
    };
    foreach (array_keys($chosen) as $id) {
      foreach ($statuses[$id]->getAllowedNextIds() as $next) {
        if (isset($chosen[(int) $next], $statuses[(int) $next])) {
          $add($statuses[$id], $statuses[(int) $next]);
        }
      }
    }
    // The function table: into each entry status of a function from every
    // open status of the form that the function does not bring.
    foreach (self::FEATURES as $feature) {
      $own = array_filter(
        array_keys($chosen),
        static fn(int $id): bool => array_intersect($feature['flags'], $statuses[$id]->getSemanticFlags()) !== [],
      );
      if ($own === [] || array_intersect($feature['flags'], $flags) === []) {
        continue;
      }
      foreach ($own as $entry) {
        if (array_intersect($feature['entry'], $statuses[$entry]->getSemanticFlags()) === []) {
          continue;
        }
        foreach (array_keys($chosen) as $from) {
          if (!in_array($from, $own, TRUE)) {
            $add($statuses[$from], $statuses[$entry]);
          }
        }
      }
    }

    // Catalogue order (weight, then id) for a stable definition.
    $ordered = array_values(array_filter(
      array_keys($statuses),
      static fn(int $id): bool => isset($chosen[$id]),
    ));
    return [
      'mode' => 'catalog',
      'defaultStatus' => $default->getMachineName(),
      'statuses' => array_map(
        static fn(int $id): array => ['machineName' => $statuses[$id]->getMachineName()],
        $ordered,
      ),
      'transitions' => array_values($transitions),
    ];
  }

  /**
   * Encodes a definition the way the demo seed stores it.
   *
   * @param array<string, mixed> $definition
   *   The definition.
   */
  public static function encode(array $definition): string {
    return json_encode($definition, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Whether two stored definitions mean the same workflow.
   */
  public static function same(?string $stored, array $definition): bool {
    $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, TRUE) : NULL;
    return $decoded === $definition;
  }

  /**
   * Loads the catalogue of a group in weight order, keyed by id.
   *
   * @return array<int, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   *   Statuses.
   */
  private static function catalogue(EntityTypeManagerInterface $manager, int $group_id): array {
    $storage = $manager->getStorage('workflow_status');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->sort('field_weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $statuses = [];
    foreach ($storage->loadMultiple($ids) as $status) {
      if ($status instanceof WorkflowStatusInterface) {
        $statuses[(int) $status->id()] = $status;
      }
    }
    return $statuses;
  }

  /**
   * Ids reachable from a status over allowed_next, the status included.
   *
   * @param array<int, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface> $statuses
   *   The catalogue.
   * @param int $start
   *   The default status id.
   *
   * @return array<int, true>
   *   Reached ids as keys.
   */
  private static function reachable(array $statuses, int $start): array {
    $reached = [];
    $queue = [$start];
    while ($queue !== []) {
      $id = array_shift($queue);
      if (isset($reached[$id]) || !isset($statuses[$id])) {
        continue;
      }
      $reached[$id] = TRUE;
      if ($statuses[$id]->isTerminal()) {
        continue;
      }
      foreach ($statuses[$id]->getAllowedNextIds() as $next) {
        $queue[] = (int) $next;
      }
    }
    return $reached;
  }

}
