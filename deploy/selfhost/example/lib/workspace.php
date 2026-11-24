<?php

/**
 * @file
 * Shared workspace setup for the fictional Musterstadt fixtures.
 */

declare(strict_types=1);

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership as GroupMembershipEntity;
use Drupal\user\UserInterface;

/**
 * Ensures demo organisations have a richer workflow status catalogue.
 */
function beantragt_demo_seed_workflow_statuses($entity_type_manager, array $groups, array &$summary): void {
  if (!$entity_type_manager->hasDefinition('workflow_status')) {
    return;
  }

  if (\Drupal::hasService('beantragt_workflow_status.status_seeder')) {
    $seeder = \Drupal::service('beantragt_workflow_status.status_seeder');
    foreach ($groups['jur'] as $jurisdiction) {
      $seeder->seedForGroup($jurisdiction);
    }
    foreach ($groups['org'] as $organisation) {
      $seeder->seedForGroup($organisation);
    }
  }

  $storage = $entity_type_manager->getStorage('workflow_status');
  foreach ($groups['jur'] as $slug => $jurisdiction) {
    $definitions = beantragt_demo_workflow_status_catalog((string) $slug);
    beantragt_demo_ensure_workflow_status_catalog($storage, $jurisdiction, $definitions, $summary);
  }
  foreach ($groups['org'] as $slug => $organisation) {
    $definitions = beantragt_demo_workflow_status_catalog((string) $slug);
    beantragt_demo_ensure_workflow_status_catalog($storage, $organisation, $definitions, $summary);
  }
}

/**
 * Returns the demo workflow status catalogue for one organisation.
 */
function beantragt_demo_workflow_status_catalog(string $org_slug): array {
  return match ($org_slug) {
    'mayors-office' => [
      'eingegangen' => beantragt_demo_status('Eingegangen', '#2563eb', 'i-lucide-inbox', 0, [
        'weitergeleitet_zur_beantwortung',
        'rueckfrage_offen',
        'geschlossen',
      ], is_default: TRUE),
      'weitergeleitet_zur_beantwortung' => beantragt_demo_status('Weitergeleitet zur Beantwortung', '#0284c7', 'i-lucide-forward', 10, [
        'beantwortung_ob',
        'rueckfrage_offen',
        'geschlossen',
      ], citizen_label: beantragt_demo_citizen_in_progress()),
      'beantwortung_ob' => beantragt_demo_status('Beantwortung OB', '#7c3aed', 'i-lucide-pen-line', 20, [
        'antwort_abgestimmt',
        'rueckfrage_offen',
        'geschlossen',
      ], citizen_label: beantragt_demo_citizen_in_progress()),
      'antwort_abgestimmt' => beantragt_demo_status('Antwort abgestimmt', '#0f766e', 'i-lucide-user-check', 30, [
        'beantwortet',
        'rueckfrage_offen',
      ], citizen_label: beantragt_demo_citizen_in_progress()),
      'rueckfrage_offen' => beantragt_demo_status('Rückfrage offen', '#f59e0b', 'i-lucide-message-square-warning', 40, [
        'weitergeleitet_zur_beantwortung',
        'beantwortung_ob',
        'geschlossen',
      ]),
      'beantwortet' => beantragt_demo_status('Beantwortet', '#16a34a', 'i-lucide-reply', 50, ['geschlossen']),
      'geschlossen' => beantragt_demo_status('Geschlossen', '#64748b', 'i-lucide-archive', 60, [], is_terminal: TRUE),
    ],
    'personnel-office' => [
      'eingegangen' => beantragt_demo_status('Eingegangen', '#2563eb', 'i-lucide-inbox', 0, [
        'vollstaendigkeit_geprueft',
        'nachforderung_offen',
        'zurueckgezogen',
      ], is_default: TRUE),
      // The business trip steps are no part of the default catalogue: flags,
      // texts and deadlines are set here, not inherited from the core seed.
      'vollstaendigkeit_geprueft' => beantragt_demo_status('Vorprüfung abgeschlossen', '#0284c7', 'i-lucide-list-checks', 10, [
        'nachforderung_offen',
        'vorgesetztenfreigabe_offen',
        'zurueckgezogen',
      ], semantic_flags: ['documents_received'], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 7, escalation_days: 10),
      'nachforderung_offen' => beantragt_demo_status('Rückfrage offen', '#f59e0b', 'i-lucide-message-square-warning', 20, [
        'vollstaendigkeit_geprueft',
        'zurueckgezogen',
      ], citizen_label: beantragt_demo_citizen_information_requested()),
      'vorgesetztenfreigabe_offen' => beantragt_demo_status('Vorgesetztenfreigabe offen', '#0f766e', 'i-lucide-user-check', 30, [
        'reisekostenstelle_pruefung',
        'abgelehnt',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 5, escalation_days: 7),
      'reisekostenstelle_pruefung' => beantragt_demo_status('Reisekostenstelle prüft', '#7c3aed', 'i-lucide-receipt-euro', 40, [
        'genehmigt',
        'abgelehnt',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 7, escalation_days: 10),
      'genehmigt' => beantragt_demo_status('Genehmigt', '#16a34a', 'i-lucide-check-circle', 50, [], is_terminal: TRUE, citizen_label: [
        'de' => 'Bewilligt',
        'en' => 'Approved',
      ]),
      'abgelehnt' => beantragt_demo_status('Abgelehnt', '#dc2626', 'i-lucide-x-circle', 60, [], is_terminal: TRUE),
      'zurueckgezogen' => beantragt_demo_status('Zurückgezogen', '#64748b', 'i-lucide-ban', 70, [], is_terminal: TRUE),
    ],
    'procurement-office' => [
      'eingegangen' => beantragt_demo_status('Eingegangen', '#2563eb', 'i-lucide-inbox', 0, [
        'fachpruefung',
        'vergabe_vorbereitung',
        'zurueckgezogen',
      ], is_default: TRUE),
      // The procurement steps are no part of the default catalogue: flags,
      // texts and deadlines are set here, not inherited from the core seed.
      // "Bedarf geprüft" takes over no batch documents (no
      // documents_received, unlike the default "In Prüfung").
      'fachpruefung' => beantragt_demo_status('Bedarf geprüft', '#7c3aed', 'i-lucide-search', 10, [
        'vergabe_vorbereitung',
        'amtsleitung_offen',
        'abgelehnt',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress()),
      'amtsleitung_offen' => beantragt_demo_status('Leitungsfreigabe offen', '#9333ea', 'i-lucide-landmark', 20, [
        'vergabe_vorbereitung',
        'abgelehnt',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 5, escalation_days: 7),
      'vergabe_vorbereitung' => beantragt_demo_status('Beschaffung vorbereitet', '#ca8a04', 'i-lucide-shopping-cart', 30, [
        'bestellung_ausgeloest',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 10, escalation_days: 14),
      'bestellung_ausgeloest' => beantragt_demo_status('Bestellung ausgelöst', '#0891b2', 'i-lucide-package-check', 40, [
        'umgesetzt',
        'zurueckgezogen',
      ], semantic_flags: [], citizen_label: beantragt_demo_citizen_in_progress(), sla_days: 10, escalation_days: 14),
      'umgesetzt' => beantragt_demo_status('Geliefert oder umgesetzt', '#16a34a', 'i-lucide-monitor-check', 50, ['genehmigt'], semantic_flags: ['closed'], citizen_label: [
        'de' => '',
        'en' => 'Implemented',
      ], sla_days: 0, escalation_days: 0),
      'genehmigt' => beantragt_demo_status('Abgeschlossen', '#16a34a', 'i-lucide-check-circle', 60, [], is_terminal: TRUE, citizen_label: [
        'de' => '',
        'en' => 'Completed',
      ]),
      'abgelehnt' => beantragt_demo_status('Abgelehnt', '#dc2626', 'i-lucide-x-circle', 70, [], is_terminal: TRUE),
      'zurueckgezogen' => beantragt_demo_status('Zurückgezogen', '#64748b', 'i-lucide-ban', 80, [], is_terminal: TRUE),
    ],
    'public-health-office' => beantragt_demo_formal_citizen_statuses('Meldung bearbeitet', [
      'de' => '',
      'en' => 'Report processed',
    ]),
    'vehicle-registration' => beantragt_demo_formal_citizen_statuses('Vorgang abgeschlossen', [
      'de' => '',
      'en' => 'Completed',
    ]),
    'building-department', 'environment-office' => beantragt_demo_formal_citizen_statuses('Genehmigt', [
      'de' => 'Bewilligt',
      'en' => 'Approved',
    ]),
    // The Stadtkasse and generic workspaces: the dog tax goes from the
    // Stadtkasse to the assessment system over FIT-Connect. Staff set
    // "Weiterleitung an Fachverfahren" (flag hand_over) to start the
    // transfer; FIT-Connect's acceptance moves the case to "An Fachverfahren
    // übergeben" and the case system's answer closes it as approved
    // ("Festgesetzt" at the Stadtkasse), rejected or filed. Statuses carry the
    // semantic flags the automatic status changes look for; the seed adds
    // them where an older catalogue created the status without them.
    // Applicants see "In Bearbeitung" for every internal step.
    'stadtkasse' => beantragt_demo_case_system_statuses('Festgesetzt', ['de' => 'Festgesetzt', 'en' => 'Assessed']),
    default => beantragt_demo_case_system_statuses('Genehmigt', ['de' => 'Bewilligt', 'en' => 'Approved']),
  };
}

/**
 * Returns the catalogue of a workspace that hands cases to a case system.
 *
 * The positive outcome (machine name "genehmigt", flags approved and closed)
 * is named per office: "Genehmigt" by default, "Festgesetzt" at the
 * Stadtkasse, which assesses the dog tax. Follow-up statuses resolve by flag,
 * so only the label differs. Reduced on 2026-11-12 to the shape of the
 * default catalogue: receipt, one review status, the request, the two
 * case-system statuses and the ends; "Zuständigkeit geklärt" and "Antwort
 * erstellt" are gone, and with them the hand-set "An Fachverfahren
 * übergeben" from receipt and review (the system sets it on acceptance).
 *
 * Three-status standard (2026-11-12): the organisation graph wires only
 * Eingegangen, In Bearbeitung and Geschlossen (the neutral end, flag closed
 * only). The request, the case-system statuses and the outcomes keep their
 * own edges as proposals and are switched on per form (Hundesteuer,
 * Bewohnerparkausweis, Wohngeld; scripts/lib/DemoFormWorkflow.php).
 */
function beantragt_demo_case_system_statuses(string $positive_label, array $positive_citizen_label): array {
  return [
    // The standard. Forwarding straight from receipt (demo tour, batch
    // "forward") is an edge of the form workflow that switches the hand-over
    // on; the acceptance of the case system moves on from the hand-over
    // status over its own edge.
    'eingegangen' => beantragt_demo_status('Eingegangen', '#2563eb', 'i-lucide-inbox', 0, [
      'in_bearbeitung',
      'geschlossen',
    ], is_default: TRUE),
    'rueckfrage_offen' => beantragt_demo_status('Rückfrage offen', '#f59e0b', 'i-lucide-message-square-warning', 20, [
      'in_bearbeitung',
      'geschlossen',
    ], semantic_flags: ['request_info']),
    'in_bearbeitung' => beantragt_demo_status('In Bearbeitung', '#7c3aed', 'i-lucide-search', 30, [
      'geschlossen',
    ], semantic_flags: ['documents_received'], citizen_label: ['de' => '', 'en' => 'In progress']),
    'fachverfahren_weiterleitung' => beantragt_demo_status('Weiterleitung an Fachverfahren', '#0e7490', 'i-lucide-forward', 33, [
      'fachverfahren_uebergeben',
      'rueckfrage_offen',
      'in_bearbeitung',
    ], semantic_flags: ['hand_over'], citizen_label: beantragt_demo_citizen_in_progress()),
    'fachverfahren_uebergeben' => beantragt_demo_status('An Fachverfahren übergeben', '#0891b2', 'i-lucide-send', 35, [
      'bescheid_erstellt',
      'genehmigt',
      'abgelehnt',
      'im_fachverfahren_abgelegt',
      'rueckfrage_offen',
      'geschlossen',
    ], semantic_flags: ['in_case_system'], citizen_label: beantragt_demo_citizen_in_progress()),
    'bescheid_erstellt' => beantragt_demo_status('Bescheid erstellt', '#16a34a', 'i-lucide-file-check', 45, [], is_terminal: TRUE, semantic_flags: [
      'decision_issued',
      'closed',
    ], citizen_label: ['de' => '', 'en' => 'Decision issued']),
    'genehmigt' => beantragt_demo_status($positive_label, '#16a34a', 'i-lucide-check-circle', 46, [], is_terminal: TRUE, semantic_flags: [
      'approved',
      'closed',
    ], citizen_label: $positive_citizen_label),
    'abgelehnt' => beantragt_demo_status('Abgelehnt', '#dc2626', 'i-lucide-x-circle', 47, [], is_terminal: TRUE, semantic_flags: [
      'rejected',
      'closed',
    ], citizen_label: ['de' => '', 'en' => 'Rejected']),
    'im_fachverfahren_abgelegt' => beantragt_demo_status('Im Fachverfahren abgelegt', '#64748b', 'i-lucide-archive', 48, [], is_terminal: TRUE, semantic_flags: [
      'filed',
      'closed',
    ], citizen_label: ['de' => 'Abgeschlossen', 'en' => 'Completed']),
    // The neutral end without outcome: a batch "not pursued" lands here, the
    // catalogue has no "Zurückgezogen".
    'geschlossen' => beantragt_demo_status('Geschlossen', '#64748b', 'i-lucide-archive', 50, [], is_terminal: TRUE, semantic_flags: ['closed']),
  ];
}

/**
 * Returns a formal application catalogue for public service offices.
 *
 * Internal steps show applicants "In Bearbeitung"; the positive outcome gets
 * its own citizen text, so a relabelled status never keeps the core seeder's
 * "Bewilligt".
 */
function beantragt_demo_formal_citizen_statuses(string $positive_label, array $positive_citizen_label): array {
  return [
    'eingegangen' => beantragt_demo_status('Eingegangen', '#2563eb', 'i-lucide-inbox', 0, [
      'zustaendigkeit_geprueft',
      'fachpruefung',
      'nachforderung_offen',
      'zurueckgezogen',
    ], is_default: TRUE),
    'zustaendigkeit_geprueft' => beantragt_demo_status('Zuständigkeit geklärt', '#0284c7', 'i-lucide-route', 10, [
      'fachpruefung',
      'nachforderung_offen',
      'zurueckgezogen',
    ], citizen_label: beantragt_demo_citizen_in_progress()),
    // Answered requests return to the review, as in the form workflows.
    'nachforderung_offen' => beantragt_demo_status('Rückfrage oder Nachforderung offen', '#f59e0b', 'i-lucide-message-square-warning', 20, [
      'fachpruefung',
      'zurueckgezogen',
    ], citizen_label: beantragt_demo_citizen_information_requested()),
    'fachpruefung' => beantragt_demo_status('Fachliche Bearbeitung', '#7c3aed', 'i-lucide-search', 30, [
      'genehmigt',
      'abgelehnt',
      'nachforderung_offen',
      'zurueckgezogen',
    ], semantic_flags: ['documents_received'], citizen_label: beantragt_demo_citizen_in_progress()),
    'genehmigt' => beantragt_demo_status($positive_label, '#16a34a', 'i-lucide-check-circle', 40, [], is_terminal: TRUE, citizen_label: $positive_citizen_label),
    'abgelehnt' => beantragt_demo_status('Negativ abgeschlossen', '#dc2626', 'i-lucide-x-circle', 50, [], is_terminal: TRUE),
    'zurueckgezogen' => beantragt_demo_status('Zurückgezogen', '#64748b', 'i-lucide-ban', 60, [], is_terminal: TRUE),
  ];
}

/**
 * Builds one workflow status definition.
 *
 * Semantic flags are only enforced where given; NULL keeps whatever the
 * status carries (core seeder defaults or an administrator's choice). The
 * citizen text works the same way: ['de' => ..., 'en' => ...] sets both
 * languages ('' shows the label), NULL keeps the stored texts.
 */
function beantragt_demo_status(
  string $label,
  string $color,
  string $icon,
  int $weight,
  array $allowed_next,
  bool $is_default = FALSE,
  bool $is_terminal = FALSE,
  ?array $semantic_flags = NULL,
  ?array $citizen_label = NULL,
  ?int $sla_days = NULL,
  ?int $escalation_days = NULL,
): array {
  return [
    'label' => $label,
    'color' => $color,
    'icon' => $icon,
    'weight' => $weight,
    'is_default' => $is_default,
    'is_terminal' => $is_terminal,
    'allowed_next' => $allowed_next,
    'semantic_flags' => $semantic_flags,
    'citizen_label' => $citizen_label,
    'sla_days' => $sla_days,
    'escalation_days' => $escalation_days,
  ];
}

/**
 * Citizen text of internal process steps: applicants see "In Bearbeitung".
 */
function beantragt_demo_citizen_in_progress(): array {
  return ['de' => 'In Bearbeitung', 'en' => 'In progress'];
}

/**
 * Citizen text of a demo request status: the label in German.
 *
 * The demo names the request "Rückfrage offen" and applicants read that
 * label; the default catalogue's fixed German text "Nachforderung offen"
 * must not replace it.
 */
function beantragt_demo_citizen_information_requested(): array {
  return ['de' => '', 'en' => 'Information requested'];
}

/**
 * Creates or updates workflow_status entities for one workspace group.
 */
function beantragt_demo_ensure_workflow_status_catalog($storage, GroupInterface $group, array $definitions, array &$summary): void {
  $group_id = (int) $group->id();
  $existing = beantragt_demo_load_workflow_statuses_by_machine_name($storage, $group_id);

  foreach ($definitions as $machine_name => $definition) {
    $status = $existing[$machine_name] ?? $storage->create([
      'field_group' => ['target_id' => $group_id],
      'field_machine_name' => $machine_name,
    ]);
    $is_new = $status->isNew();
    $changed = FALSE;

    foreach ([
      'label' => $definition['label'],
      'field_color' => $definition['color'],
      'field_icon' => $definition['icon'],
      'field_weight' => (int) $definition['weight'],
      'field_is_default' => (bool) ($definition['is_default'] ?? FALSE),
      'field_is_terminal' => (bool) ($definition['is_terminal'] ?? FALSE),
    ] + array_filter([
      // Deadlines only where given; NULL keeps the stored values.
      'field_sla_days' => $definition['sla_days'] ?? NULL,
      'field_escalation_days' => $definition['escalation_days'] ?? NULL,
    ], static fn(?int $value): bool => $value !== NULL) as $field_name => $value) {
      if (beantragt_demo_entity_field_value($status, $field_name) !== $value) {
        $status->set($field_name, $value);
        $changed = TRUE;
      }
    }

    if (is_array($definition['citizen_label'] ?? NULL)) {
      foreach (['de', 'en'] as $langcode) {
        $field_name = 'field_citizen_label_' . $langcode;
        $value = (string) ($definition['citizen_label'][$langcode] ?? '');
        if ($status->hasField($field_name) && (string) ($status->get($field_name)->value ?? '') !== $value) {
          $status->set($field_name, $value);
          $changed = TRUE;
        }
      }
    }

    if (is_array($definition['semantic_flags'] ?? NULL)) {
      $wanted_flags = $definition['semantic_flags'];
      sort($wanted_flags);
      $current_flags = array_values(array_filter(array_map(
        'strval',
        array_column($status->get('field_semantic_flags')->getValue(), 'value'),
      )));
      sort($current_flags);
      if ($current_flags !== $wanted_flags) {
        $status->set('field_semantic_flags', array_map(
          static fn(string $flag): array => ['value' => $flag],
          $wanted_flags,
        ));
        $changed = TRUE;
      }
    }

    if ($is_new || $changed) {
      $status->save();
      $summary[$is_new ? 'workflow_statuses_created' : 'workflow_statuses_updated']++;
    }
    $existing[$machine_name] = $status;
  }

  foreach ($definitions as $machine_name => $definition) {
    $status = $existing[$machine_name] ?? NULL;
    if (!$status) {
      continue;
    }

    $target_ids = [];
    if (($definition['is_terminal'] ?? FALSE) !== TRUE) {
      foreach ($definition['allowed_next'] ?? [] as $target_machine_name) {
        if (isset($existing[$target_machine_name])) {
          $target_ids[] = (int) $existing[$target_machine_name]->id();
        }
      }
    }

    $current_ids = array_map('intval', array_column($status->get('field_allowed_next')->getValue(), 'target_id'));
    sort($current_ids);
    $compare_ids = $target_ids;
    sort($compare_ids);
    if ($current_ids !== $compare_ids) {
      $status->set('field_allowed_next', array_map(
        static fn(int $target_id): array => ['target_id' => $target_id],
        $target_ids,
      ));
      $status->save();
      $summary['workflow_statuses_updated']++;
    }
  }

  $prune_targets = [];
  foreach (beantragt_demo_prunable_workflow_status_machine_names() as $machine_name) {
    if (isset($definitions[$machine_name]) || !isset($existing[$machine_name])) {
      continue;
    }
    $status = $existing[$machine_name];
    if (!$status instanceof WorkflowStatusInterface) {
      continue;
    }
    $blockers = array_diff(
      beantragt_demo_workflow_status_delete_blockers($status),
      ['transition_reference'],
    );
    if ($blockers !== []) {
      $summary['workflow_statuses_prune_skipped']++;
      continue;
    }
    $prune_targets[(int) $status->id()] = $status;
  }

  if ($prune_targets !== []) {
    $prune_target_ids = array_keys($prune_targets);
    foreach ($existing as $status) {
      if (!$status instanceof WorkflowStatusInterface) {
        continue;
      }

      $current_ids = array_map('intval', array_column($status->get('field_allowed_next')->getValue(), 'target_id'));
      $remaining_ids = array_values(array_diff($current_ids, $prune_target_ids));
      if ($current_ids === $remaining_ids) {
        continue;
      }

      $status->set('field_allowed_next', array_map(
        static fn(int $target_id): array => ['target_id' => $target_id],
        $remaining_ids,
      ));
      $status->save();
      $summary['workflow_statuses_updated']++;
    }
  }

  foreach ($prune_targets as $status) {
    if (beantragt_demo_workflow_status_delete_blockers($status) !== []) {
      $summary['workflow_statuses_prune_skipped']++;
      continue;
    }
    $status->delete();
    $summary['workflow_statuses_deleted']++;
  }
}

/**
 * Known machine names created by older demo workflow catalogues.
 *
 * Manual status definitions with other machine names are never pruned.
 */
function beantragt_demo_prunable_workflow_status_machine_names(): array {
  return [
    'vollstaendigkeit_geprueft',
    'nachforderung_offen',
    'fachpruefung',
    'vorgesetztenfreigabe_offen',
    'amtsleitung_offen',
    'reisekostenstelle_pruefung',
    'vergabe_vorbereitung',
    'bestellung_ausgeloest',
    'umgesetzt',
    'genehmigt',
    'abgelehnt',
    'zurueckgezogen',
    // Core seeder statuses for case-system transfers; only catalogues that
    // transfer keep them.
    'fachverfahren_weiterleitung',
    'fachverfahren_uebergeben',
    'bescheid_erstellt',
    'im_fachverfahren_abgelegt',
    // Left the case-system catalogue on 2026-11-12; the formal catalogue
    // keeps "zustaendigkeit_geprueft" for the demo form workflows.
    'zustaendigkeit_geprueft',
    'antwort_erstellt',
    // Standard statuses of the core seed since the three-status standard
    // (2026-11-12): a demo catalogue with its own receipt, processing and
    // end keeps those and drops the core ones, so it has one neutral end.
    'in_bearbeitung',
    'abgeschlossen',
  ];
}

/**
 * Checks whether a workflow status is safe to prune from demo data.
 *
 * @return string[]
 *   Machine-readable blocker keys.
 */
function beantragt_demo_workflow_status_delete_blockers(WorkflowStatusInterface $status): array {
  if (\Drupal::hasService('beantragt_workflow_status.status_manager')) {
    $manager = \Drupal::service('beantragt_workflow_status.status_manager');
    return $manager->deleteBlockers($status);
  }

  return beantragt_demo_workflow_status_has_application_references((int) $status->id())
    ? ['application_reference']
    : [];
}

/**
 * Checks whether an application still references a workflow status.
 */
function beantragt_demo_workflow_status_has_application_references(int $status_id): bool {
  if ($status_id <= 0 || !\Drupal::entityTypeManager()->hasDefinition('application')) {
    return FALSE;
  }

  $ids = \Drupal::entityTypeManager()->getStorage('application')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_workflow_status', $status_id)
    ->range(0, 1)
    ->execute();

  return !empty($ids);
}

/**
 * Loads workflow statuses by machine name for one organisation.
 */
function beantragt_demo_load_workflow_statuses_by_machine_name($storage, int $group_id): array {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_group', $group_id)
    ->execute();

  $statuses = [];
  foreach ($storage->loadMultiple($ids) as $status) {
    $machine_name = (string) ($status->get('field_machine_name')->value ?? '');
    if ($machine_name !== '') {
      $statuses[$machine_name] = $status;
    }
  }
  return $statuses;
}

/**
 * Reads a scalar entity field value with stable types for comparisons.
 */
function beantragt_demo_entity_field_value(FieldableEntityInterface $entity, string $field_name): mixed {
  if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
    return NULL;
  }
  $value = $entity->get($field_name)->value;
  if (str_starts_with($field_name, 'field_is_')) {
    return (bool) $value;
  }
  if (in_array($field_name, ['field_weight', 'field_sla_days', 'field_escalation_days'], TRUE)) {
    return (int) $value;
  }
  return $value;
}

/**
 * Sets the brand colour of a demo jurisdiction to match its logo.
 *
 * Without it the product default primary colour applies, which does not fit
 * the green Musterstadt shield. Logo and other theme keys stay untouched.
 */
function beantragt_demo_ensure_brand_primary(GroupInterface $jurisdiction, string $primary): void {
  if (!$jurisdiction->hasField('field_jur_config')) {
    return;
  }
  $config_service = \Drupal::service('beantragt_group.jurisdiction_config');
  if (($config_service->getSection($jurisdiction, 'theme')['primary'] ?? NULL) === $primary) {
    return;
  }
  $config_service->mergeSection($jurisdiction, 'theme', ['primary' => $primary]);
}

/**
 * Enables applicant profile reuse for a demo jurisdiction.
 *
 * Both switches default to off; every demo jurisdiction shows the contact
 * and the bank consent checkbox.
 */
function beantragt_demo_ensure_applicant_profile(GroupInterface $jurisdiction): void {
  $wanted = ['enabled' => TRUE, 'scope' => 'organisation', 'bankConsent' => TRUE];
  $config_service = \Drupal::service('beantragt_group.jurisdiction_config');
  if ($config_service->getSection($jurisdiction, 'applicant_profile') === $wanted) {
    return;
  }
  $config_service->updateSection($jurisdiction, 'applicant_profile', $wanted);
}

/**
 * Finds a group by bundle and slug.
 */
function beantragt_demo_find_group($entity_type_manager, string $bundle, string $slug, ?GroupInterface $jurisdiction = NULL): ?GroupInterface {
  foreach ($entity_type_manager->getStorage('group')->loadByProperties(['type' => $bundle]) as $group) {
    if ($group instanceof GroupInterface
      && $group->hasField('field_slug')
      && !$group->get('field_slug')->isEmpty()
      && (string) $group->get('field_slug')->value === $slug) {
      if ($jurisdiction instanceof GroupInterface
        && $bundle === 'org'
        && (!$group->hasField('field_jurisdiction')
          || $group->get('field_jurisdiction')->isEmpty()
          || (int) $group->get('field_jurisdiction')->target_id !== (int) $jurisdiction->id())) {
        continue;
      }
      return $group;
    }
  }

  return NULL;
}

/**
 * Ensures a scoped group role for one user.
 */
function beantragt_demo_ensure_group_role(GroupInterface $group, UserInterface $user, string $role_id, bool $exact = FALSE): bool {
  $membership = GroupMembershipEntity::loadSingle($group, $user);
  if (!$membership) {
    $group->addMember($user, ['group_roles' => [$role_id]]);
    return TRUE;
  }

  $role_ids = array_keys($membership->getRoles(FALSE));
  if ($exact) {
    if ($role_ids === [$role_id]) {
      return FALSE;
    }
    $membership->set('group_roles', [$role_id])->save();
    return TRUE;
  }
  if (in_array($role_id, $role_ids, TRUE)) {
    return FALSE;
  }

  $membership->addRole($role_id);
  return TRUE;
}
