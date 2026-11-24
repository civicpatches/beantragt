<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Service;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Seeds the default workflow status set for jurisdiction and org groups.
 *
 * The default set is a reusable workspace catalogue. Individual form
 * schemas can narrow it into their own fachverfahren workflow.
 */
class WorkflowStatusSeeder {

  /**
   * The default status definitions.
   *
   * Two groups (product decision 2026-11-12,
   * "Default Catalogue"):
   * - The standard: eingegangen, in_bearbeitung, abgeschlossen. Wired from
   *   the default status, so a form without own workflow sees exactly these
   *   three. No way back in the graph; the rollback is the step back.
   * - The library: eight statuses with their flags and their own outgoing
   *   transitions as proposals, but no incoming transition from the
   *   standard. A form switches them on in its form workflow; automation
   *   only targets statuses of the governing workflow
   *   (EffectiveWorkflowResolver::governingStatusIds()).
   *
   * The library proposals keep the transitions automatic changes need inside
   * a form that switches them on:
   * - fachverfahren_weiterleitung to fachverfahren_uebergeben: the case
   *   system accepts a transfer started by the status trigger.
   * - nachforderung_offen to in_bearbeitung: documents taken over in a batch.
   * - fachverfahren_weiterleitung and fachverfahren_uebergeben to
   *   nachforderung_offen: a public staff message.
   * - every open status to abgeschlossen and every open library status to
   *   zurueckgezogen: a batch "not pursued".
   * Responses of the case system close outside the graph.
   *
   * Colours and icons mirror the frontend workflow settings mockup. The
   * allowed_next key lists target machine names; it is resolved to entity
   * references in a second pass once all statuses exist.
   *
   * @var array<int, array<string, mixed>>
   */
  protected const DEFAULT_STATUSES = [
    [
      'machine_name' => 'eingegangen',
      'label' => 'Eingegangen',
      'citizen_label_de' => '',
      'citizen_label_en' => 'Received',
      'color' => '#2563eb',
      'icon' => 'i-lucide-inbox',
      'weight' => 0,
      'is_default' => TRUE,
      'is_terminal' => FALSE,
      'sla_days' => 3,
      'escalation_days' => 5,
      'semantic_flags' => ['received'],
      'allowed_next' => [
        'in_bearbeitung',
        'abgeschlossen',
      ],
    ],
    [
      // Replaces "In Prüfung" (fachpruefung). Documents taken over from a
      // batch land here (documents_received) once a form switches
      // "Unterlagen nachgefordert" on.
      'machine_name' => 'in_bearbeitung',
      'label' => 'In Bearbeitung',
      'citizen_label_de' => '',
      'citizen_label_en' => 'In progress',
      'color' => '#7c3aed',
      'icon' => 'i-lucide-pencil',
      'weight' => 30,
      'is_default' => FALSE,
      'is_terminal' => FALSE,
      'sla_days' => 14,
      'escalation_days' => 14,
      'semantic_flags' => ['documents_received'],
      'allowed_next' => [
        'abgeschlossen',
      ],
    ],
    [
      // The neutral end of the standard: "closed" and nothing else, so a
      // notice of any outcome and a batch "not pursued" close here without
      // claiming an outcome.
      'machine_name' => 'abgeschlossen',
      'label' => 'Abgeschlossen',
      'citizen_label_de' => '',
      'citizen_label_en' => 'Completed',
      'color' => '#475569',
      'icon' => 'i-lucide-check-circle',
      'weight' => 90,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['closed'],
      'allowed_next' => [],
    ],
    [
      'machine_name' => 'nachforderung_offen',
      'label' => 'Unterlagen nachgefordert',
      // Applicants keep reading the former label: the staff rename must
      // not change a citizen text or send a status mail.
      'citizen_label_de' => 'Nachforderung offen',
      'citizen_label_en' => 'Information requested',
      'color' => '#f59e0b',
      'icon' => 'i-lucide-message-square-warning',
      'weight' => 20,
      'is_default' => FALSE,
      'is_terminal' => FALSE,
      'sla_days' => 14,
      'escalation_days' => 0,
      'semantic_flags' => ['request_info'],
      'allowed_next' => [
        'in_bearbeitung',
        'abgeschlossen',
        'abgelehnt',
        'zurueckgezogen',
      ],
    ],
    [
      // Staff set it to hand the application over; with a status trigger
      // on the transfer rule it starts the transfer.
      'machine_name' => 'fachverfahren_weiterleitung',
      'label' => 'Weiterleitung an Fachverfahren',
      'citizen_label_de' => 'In Bearbeitung',
      'citizen_label_en' => 'In progress',
      'color' => '#0e7490',
      'icon' => 'i-lucide-forward',
      'weight' => 34,
      'is_default' => FALSE,
      'is_terminal' => FALSE,
      'sla_days' => 1,
      'escalation_days' => 2,
      'semantic_flags' => ['hand_over'],
      'allowed_next' => [
        'fachverfahren_uebergeben',
        'in_bearbeitung',
        'nachforderung_offen',
        'abgeschlossen',
        'zurueckgezogen',
      ],
    ],
    [
      // Set automatically when FIT-Connect accepts the transfer.
      'machine_name' => 'fachverfahren_uebergeben',
      'label' => 'Im Fachverfahren',
      'citizen_label_de' => 'In Bearbeitung',
      'citizen_label_en' => 'In progress',
      'color' => '#0891b2',
      'icon' => 'i-lucide-send',
      'weight' => 35,
      'is_default' => FALSE,
      'is_terminal' => FALSE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['in_case_system'],
      'allowed_next' => [
        'genehmigt',
        'abgelehnt',
        'bescheid_erstellt',
        'im_fachverfahren_abgelegt',
        'nachforderung_offen',
        'abgeschlossen',
        'zurueckgezogen',
      ],
    ],
    [
      // Set automatically when the case system sends the decision notice;
      // the notice may approve or reject, so no outcome flag. Kept apart
      // from the filing: a connection names a notice without outcome in its
      // own words only on a status without "filed" (TimelineLabels).
      'machine_name' => 'bescheid_erstellt',
      'label' => 'Bescheid erstellt',
      'citizen_label_de' => '',
      'citizen_label_en' => 'Decision issued',
      'color' => '#16a34a',
      'icon' => 'i-lucide-file-check',
      'weight' => 95,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['decision_issued', 'closed'],
      'allowed_next' => [],
    ],
    [
      // Set automatically when the case system files the application
      // without a decision notice (filing receipt, or closure on receipt).
      'machine_name' => 'im_fachverfahren_abgelegt',
      'label' => 'Im Fachverfahren abgelegt',
      'citizen_label_de' => 'Abgeschlossen',
      'citizen_label_en' => 'Completed',
      'color' => '#64748b',
      'icon' => 'i-lucide-archive',
      'weight' => 97,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['filed', 'closed'],
      'allowed_next' => [],
    ],
    [
      'machine_name' => 'genehmigt',
      'label' => 'Genehmigt',
      'citizen_label_de' => 'Bewilligt',
      'citizen_label_en' => 'Approved',
      'color' => '#16a34a',
      'icon' => 'i-lucide-check-circle',
      'weight' => 100,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['approved', 'closed'],
      'allowed_next' => [],
    ],
    [
      'machine_name' => 'abgelehnt',
      'label' => 'Abgelehnt',
      'citizen_label_de' => '',
      'citizen_label_en' => 'Rejected',
      'color' => '#dc2626',
      'icon' => 'i-lucide-x-circle',
      'weight' => 110,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['rejected', 'closed'],
      'allowed_next' => [],
    ],
    [
      'machine_name' => 'zurueckgezogen',
      'label' => 'Zurückgezogen',
      'citizen_label_de' => '',
      'citizen_label_en' => 'Withdrawn',
      'color' => '#64748b',
      'icon' => 'i-lucide-ban',
      'weight' => 120,
      'is_default' => FALSE,
      'is_terminal' => TRUE,
      'sla_days' => 0,
      'escalation_days' => 0,
      'semantic_flags' => ['withdrawn', 'closed'],
      'allowed_next' => [],
    ],
  ];

  /**
   * Constructs the seeder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the default status definitions.
   *
   * @return array<int, array<string, mixed>>
   *   The default status definitions.
   */
  public static function getDefaultStatusDefinitions(): array {
    return static::DEFAULT_STATUSES;
  }

  /**
   * Seeds the default status set for a group, if it has none yet.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The owning jurisdiction or organisation group.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface[]
   *   The created (or pre-existing) statuses keyed by machine name. Empty
   *   array if the group is not a supported workspace group.
   */
  public function seedForGroup(GroupInterface $group): array {
    if (!in_array($group->bundle(), ['jur', 'org'], TRUE)) {
      return [];
    }

    $group_id = (int) $group->id();
    if ($group_id <= 0) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('workflow_status');

    // Idempotency: keep existing custom statuses, but fill missing catalogue
    // entries so partial/manual catalogues still get a usable default set.
    $existing = $this->loadStatusesByGroup($storage, $group_id);
    $statuses = $existing;
    $has_default = array_any($existing, static fn(WorkflowStatusInterface $status): bool => $status->isDefault());
    if ($existing !== [] && $has_default) {
      return $existing;
    }

    // First pass: create every status so each has an entity id.
    $created = [];
    foreach (static::DEFAULT_STATUSES as $definition) {
      if (isset($statuses[$definition['machine_name']])) {
        continue;
      }

      $is_default = (bool) $definition['is_default'] && !$has_default;
      /** @var \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status */
      $status = $storage->create([
        'label' => $definition['label'],
        'field_group' => ['target_id' => $group_id],
        'field_machine_name' => $definition['machine_name'],
        'field_color' => $definition['color'],
        'field_icon' => $definition['icon'],
        'field_weight' => $definition['weight'],
        'field_is_default' => $is_default,
        'field_is_terminal' => $definition['is_terminal'],
        'field_sla_days' => $definition['sla_days'] ?? 0,
        'field_escalation_days' => $definition['escalation_days'] ?? 0,
        'field_semantic_flags' => array_map(
          static fn(string $flag): array => ['value' => $flag],
          $definition['semantic_flags'] ?? [],
        ),
        'field_citizen_label_de' => $definition['citizen_label_de'] ?? '',
        'field_citizen_label_en' => $definition['citizen_label_en'] ?? '',
      ]);
      $status->save();
      $created[$definition['machine_name']] = $status;
      $statuses[$definition['machine_name']] = $status;
      if ($is_default) {
        $has_default = TRUE;
      }
    }

    if (!$has_default && isset($statuses['eingegangen'])) {
      $statuses['eingegangen']->set('field_is_default', TRUE);
      $statuses['eingegangen']->save();
      $has_default = TRUE;
    }

    // References can be wired only after every status has an ID.
    foreach (static::DEFAULT_STATUSES as $definition) {
      if (!isset($created[$definition['machine_name']])) {
        continue;
      }
      $targets = [];
      foreach ($definition['allowed_next'] as $target_machine_name) {
        if (isset($statuses[$target_machine_name])) {
          $targets[] = ['target_id' => $statuses[$target_machine_name]->id()];
        }
      }
      if (!empty($targets)) {
        $status = $created[$definition['machine_name']];
        $status->set('field_allowed_next', $targets);
        $status->save();
      }
    }

    return $statuses;
  }

  /**
   * Statuses the system sets on its own, keyed by their semantic flag.
   *
   * Each is added to an existing catalogue that has no status with the flag
   * yet (see addMissingSystemStatuses()).
   */
  public const SYSTEM_STATUS_FLAGS = [
    'hand_over' => 'fachverfahren_weiterleitung',
    'in_case_system' => 'fachverfahren_uebergeben',
    'decision_issued' => 'bescheid_erstellt',
    'filed' => 'im_fachverfahren_abgelegt',
  ];

  /**
   * Adds the case-system statuses to an existing catalogue.
   *
   * They are library statuses of the three-status standard: the transitions
   * into a created status come only from definitions that name it, and no
   * standard status names a library status, so the standard stays three
   * statuses long.
   *
   * The seedForGroup() method leaves a catalogue with a default status alone,
   * so catalogues seeded before the status automation never got
   * "Weiterleitung an Fachverfahren" (hand_over), "Im Fachverfahren"
   * (in_case_system), "Bescheid erstellt" (decision_issued) and
   * "Im Fachverfahren abgelegt" (filed). For each flag no status of the
   * group carries, the default status is created and wired like a fresh
   * seed: its own
   * transitions, and the transitions into it from the seeded statuses that
   * still exist. A catalogue that already has a status with the flag, or a
   * custom status under the same machine name, is left untouched, and the
   * method is idempotent.
   *
   * @return list<string>
   *   Machine names of the created statuses.
   */
  public function addMissingSystemStatuses(int $group_id): array {
    if ($group_id <= 0) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $statuses = $this->loadStatusesByGroup($storage, $group_id);
    $has_default = array_any($statuses, static fn(WorkflowStatusInterface $status): bool => $status->isDefault());
    if (!$has_default) {
      // An incomplete catalogue is repaired by seedForGroup().
      return [];
    }

    $definitions = [];
    foreach (static::DEFAULT_STATUSES as $definition) {
      $definitions[$definition['machine_name']] = $definition;
    }

    $created = [];
    foreach (static::SYSTEM_STATUS_FLAGS as $flag => $machine_name) {
      $flagged = array_any($statuses, static fn(WorkflowStatusInterface $status): bool => $status->hasSemanticFlag($flag));
      if ($flagged || isset($statuses[$machine_name]) || !isset($definitions[$machine_name])) {
        continue;
      }
      $definition = $definitions[$machine_name];
      /** @var \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status */
      $status = $storage->create([
        'label' => $definition['label'],
        'field_group' => ['target_id' => $group_id],
        'field_machine_name' => $machine_name,
        'field_color' => $definition['color'],
        'field_icon' => $definition['icon'],
        'field_weight' => $definition['weight'],
        'field_is_default' => FALSE,
        'field_is_terminal' => $definition['is_terminal'],
        'field_sla_days' => $definition['sla_days'] ?? 0,
        'field_escalation_days' => $definition['escalation_days'] ?? 0,
        'field_semantic_flags' => array_map(
          static fn(string $value): array => ['value' => $value],
          $definition['semantic_flags'] ?? [],
        ),
        'field_citizen_label_de' => $definition['citizen_label_de'] ?? '',
        'field_citizen_label_en' => $definition['citizen_label_en'] ?? '',
      ]);
      $status->save();
      $statuses[$machine_name] = $status;
      $created[] = $machine_name;
    }

    foreach ($created as $machine_name) {
      $status = $statuses[$machine_name];
      // Outgoing transitions of the new status.
      $targets = [];
      foreach ($definitions[$machine_name]['allowed_next'] as $target_machine_name) {
        if (isset($statuses[$target_machine_name])) {
          $targets[] = ['target_id' => $statuses[$target_machine_name]->id()];
        }
      }
      if ($targets !== []) {
        $status->set('field_allowed_next', $targets);
        $status->save();
      }
      // Incoming transitions from the seeded statuses that still exist.
      foreach ($definitions as $source_machine_name => $definition) {
        if ($source_machine_name === $machine_name
          || !in_array($machine_name, $definition['allowed_next'], TRUE)
          || !isset($statuses[$source_machine_name])) {
          continue;
        }
        $source = $statuses[$source_machine_name];
        $next_ids = $source->getAllowedNextIds();
        $target_id = (int) $status->id();
        if (in_array($target_id, $next_ids, TRUE)) {
          continue;
        }
        $source->set('field_allowed_next', array_map(
          static fn(int $id): array => ['target_id' => $id],
          [...$next_ids, $target_id],
        ));
        $source->save();
      }
    }

    return $created;
  }

  /**
   * Loads the statuses owned by a group, keyed by machine name.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The workflow_status storage.
   * @param int $group_id
   *   The group id.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface[]
   *   Statuses keyed by machine name.
   */
  protected function loadStatusesByGroup(EntityStorageInterface $storage, int $group_id): array {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->execute();

    $statuses = [];
    foreach ($storage->loadMultiple($ids) as $status) {
      if ($status instanceof WorkflowStatusInterface) {
        $statuses[$status->getMachineName()] = $status;
      }
    }
    return $statuses;
  }

}
