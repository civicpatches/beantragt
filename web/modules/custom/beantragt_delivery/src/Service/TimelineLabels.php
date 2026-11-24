<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Names automatic case-system statuses the way the sending connection does.
 *
 * A connection to an e-file system may call the handover "In der E-Akte
 * abgelegt" instead of the status label "An Fachverfahren übergeben". The
 * texts come from the connection that accepted the transfer, never from the
 * revision log.
 */
final class TimelineLabels {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Replaces labels of handover and decision entries in place.
   *
   * @param array<int, array<string, mixed>> $timeline
   *   Timeline entries with at least a "status" machine name.
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application the timeline belongs to.
   * @param string|null $langcode
   *   The language the reader asked for; NULL uses the current language.
   */
  public function apply(array &$timeline, ApplicationInterface $application, ?string $langcode = NULL): void {
    if ($timeline === [] || $application->id() === NULL) {
      return;
    }
    $presentation = $this->presentation((int) $application->id());
    if ($presentation === NULL) {
      return;
    }
    $flags = $this->flagsByMachineName($application);
    $langcode ??= $this->languageManager->getCurrentLanguage()->getId();
    foreach ($timeline as &$entry) {
      $entry_flags = $flags[(string) ($entry['status'] ?? '')] ?? [];
      // An outcome status shows its own citizen text ("Bewilligt",
      // "Abgelehnt", "Abgeschlossen"); the response text names only a
      // notice without outcome.
      $key = match (TRUE) {
        in_array('decision_issued', $entry_flags, TRUE)
          && array_intersect(['approved', 'rejected', 'filed'], $entry_flags) === [] => 'response_label',
        in_array('in_case_system', $entry_flags, TRUE) => 'handover_label',
        default => NULL,
      };
      if ($key === NULL) {
        continue;
      }
      // German is the source language of every connection text.
      $label = $presentation[$key][$langcode] ?? '';
      $label = $label !== '' ? $label : $presentation[$key]['de'];
      if ($label !== '') {
        $entry['label'] = $label;
      }
    }
    unset($entry);
  }

  /**
   * Returns the connection's words for the outcomes of its answers.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application whose transfer the case system answered.
   * @param string|null $langcode
   *   The language the reader asked for; NULL uses the current language.
   *
   * @return array<string, string>
   *   Words keyed by outcome (approved, rejected, filed); '' keeps the
   *   product's word.
   */
  public function outcomeLabels(ApplicationInterface $application, ?string $langcode = NULL): array {
    $labels = ['approved' => '', 'rejected' => '', 'filed' => ''];
    if ($application->id() === NULL) {
      return $labels;
    }
    $presentation = $this->presentation((int) $application->id());
    if ($presentation === NULL) {
      return $labels;
    }
    $langcode ??= $this->languageManager->getCurrentLanguage()->getId();
    foreach (array_keys($labels) as $outcome) {
      $pair = $presentation[$outcome . '_label'];
      // German is the source language of every connection text.
      $labels[$outcome] = ($pair[$langcode] ?? '') !== '' ? $pair[$langcode] : $pair['de'];
    }
    return $labels;
  }

  /**
   * Loads the presentation of the connection that accepted the transfer.
   */
  private function presentation(int $application_id): ?array {
    $query = $this->database->select('beantragt_delivery_log', 'd');
    // Same scope on both sides, even though planning already guarantees it.
    $query->innerJoin('beantragt_integration_connection', 'c', 'c.uuid = d.integration_connection_uuid AND c.scope_group_id = d.scope_group_id');
    $query->addField('c', 'presentation');
    $stored = $query->condition('d.application_id', $application_id)
      ->condition('d.adapter', 'fit_connect')
      ->condition('d.status', DeliveryLogRepository::STATUS_ACCEPTED)
      ->orderBy('d.id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!is_string($stored) || $stored === '') {
      return NULL;
    }
    return IntegrationRepository::presentation(json_decode($stored, TRUE));
  }

  /**
   * Maps machine names of the application's catalogue to semantic flags.
   *
   * @return array<string, string[]>
   *   Flags keyed by status machine name.
   */
  private function flagsByMachineName(ApplicationInterface $application): array {
    $status_id = $application->getWorkflowStatusId();
    if ($status_id === NULL || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $current = $storage->load($status_id);
    if (!$current instanceof WorkflowStatusInterface) {
      return [];
    }
    $flags = [];
    foreach ($storage->loadByProperties(['field_group' => $current->getGroupId()]) as $status) {
      if ($status instanceof WorkflowStatusInterface) {
        $flags[$status->getMachineName()] = $status->getSemanticFlags();
      }
    }
    return $flags;
  }

}
