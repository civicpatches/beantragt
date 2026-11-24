<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface for the Workflow Status entity.
 *
 * A workflow_status is a per-group, config-like status definition. Each
 * jurisdiction or organisation group owns its own set of statuses,
 * MaS-aligned: where Mark-a-Spot uses per-jurisdiction taxonomy terms with a
 * JSON definition, beantragt uses a dedicated content entity.
 */
interface WorkflowStatusInterface extends ContentEntityInterface {

  /**
   * Languages that carry their own citizen text.
   */
  public const CITIZEN_LANGCODES = ['de', 'en'];

  /**
   * Hand over to the case system: setting it may start the transfer.
   */
  public const FLAG_HAND_OVER = 'hand_over';

  /**
   * Filed by the case system without a decision notice.
   */
  public const FLAG_FILED = 'filed';

  /**
   * Follow-up documents were received and taken over (Nachreichung).
   */
  public const FLAG_DOCUMENTS_RECEIVED = 'documents_received';

  /**
   * Flags an assignment may use as trigger status.
   */
  public const TRIGGER_FLAGS = ['hand_over'];

  /**
   * Text an applicant sees for this status in the given language.
   *
   * English: field_citizen_label_en, else field_citizen_label_de, else the
   * label. German and every other language: field_citizen_label_de, else
   * the label. Never empty while the label is not empty.
   *
   * @param string $langcode
   *   The language of the reader.
   *
   * @return string
   *   The citizen text.
   */
  public function getCitizenLabel(string $langcode): string;

  /**
   * TRUE when a citizen text is set that differs from the label.
   *
   * Staff UI uses it for the "intern" hint; no logic depends on it.
   *
   * @return bool
   *   TRUE when applicants see another text than the label.
   */
  public function hasCitizenLabelOverride(): bool;

  /**
   * Gets the owning group ID.
   *
   * @return int|null
   *   The group ID, or NULL if unset.
   */
  public function getGroupId(): ?int;

  /**
   * Gets the stable machine name.
   *
   * @return string
   *   The machine name (e.g. "new", "in_review").
   */
  public function getMachineName(): string;

  /**
   * Gets the display color.
   *
   * @return string|null
   *   A hex color (e.g. "#3b82f6"), or NULL if unset.
   */
  public function getColor(): ?string;

  /**
   * Gets the display icon.
   *
   * @return string|null
   *   A Lucide icon name (e.g. "i-lucide-inbox"), or NULL if unset.
   */
  public function getIcon(): ?string;

  /**
   * Gets the ordering weight.
   *
   * @return int
   *   The weight.
   */
  public function getWeight(): int;

  /**
   * Whether this is the default (initial) status for its group.
   *
   * @return bool
   *   TRUE if this status is the group default.
   */
  public function isDefault(): bool;

  /**
   * Whether this is a terminal status (no outgoing transitions).
   *
   * @return bool
   *   TRUE if terminal.
   */
  public function isTerminal(): bool;

  /**
   * Gets the SLA due-date offset for applications entering this status.
   *
   * @return int
   *   Number of calendar days after transition. 0 disables automatic due dates.
   */
  public function getSlaDays(): int;

  /**
   * Gets the escalation window for applications staying in this status.
   *
   * @return int
   *   Number of days after the last status change. 0 disables escalation.
   */
  public function getEscalationDays(): int;

  /**
   * Gets semantic workflow flags.
   *
   * @return string[]
   *   Semantic flags such as request_info, approved or rejected.
   */
  public function getSemanticFlags(): array;

  /**
   * Checks whether this status carries one semantic flag.
   *
   * @param string $flag
   *   The semantic flag.
   *
   * @return bool
   *   TRUE when the status has this semantic flag.
   */
  public function hasSemanticFlag(string $flag): bool;

  /**
   * Gets the IDs of the allowed target statuses.
   *
   * @return int[]
   *   Workflow status entity IDs reachable from this status.
   */
  public function getAllowedNextIds(): array;

  /**
   * Gets the machine names of the allowed target statuses.
   *
   * @return string[]
   *   Machine names reachable from this status.
   */
  public function getAllowedNextMachineNames(): array;

}
