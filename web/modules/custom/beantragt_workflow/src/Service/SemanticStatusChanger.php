<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves an application to a status by semantic flag on behalf of the system.
 *
 * Statuses are resolved by flag, never by machine name, so renamed or custom
 * catalogues keep working, and only among the statuses of the workflow that
 * governs the application (EffectiveWorkflowResolver::governingStatusIds()):
 * a status of the catalogue the form never switched on is no target. Changes
 * inside the graph look there only; a workflow without such a status is a
 * normal outcome, not a warning. Closing changes (outside the graph) look
 * there first with their whole candidate list, and only when that finds
 * nothing in the catalogue as before, so a notice still closes a form whose
 * workflow has no closing status.
 */
class SemanticStatusChanger {

  /**
   * A staff comment asked the applicant for information.
   */
  public const COMMENT_REQUEST_INFO = 'beantragt_comment:auto_request_info';

  /**
   * The case system accepted the transfer of the application.
   */
  public const CASE_SYSTEM_ACCEPTED = 'beantragt_delivery:auto_in_case_system';

  /**
   * The case system sent the decision notice (Bescheid).
   */
  public const DECISION_ISSUED = 'beantragt_delivery:auto_decision_issued';

  /**
   * A staff member released a decision notice drafted in beantragt.ai.
   *
   * Same target as DECISION_ISSUED, but a person decided (no fully
   * automated administrative act, section 35a VwVfG): the status revision
   * names the releasing account, which is the current user of the release
   * request.
   */
  public const DECISION_RELEASED = 'beantragt_docgen:decision_released';

  /**
   * The case system approved the application (reply outcome "approved").
   */
  public const CASE_SYSTEM_APPROVED = 'beantragt_delivery:auto_case_system_approved';

  /**
   * The case system rejected the application (reply outcome "rejected").
   */
  public const CASE_SYSTEM_REJECTED = 'beantragt_delivery:auto_case_system_rejected';

  /**
   * The case system filed the application without a decision notice.
   */
  public const CASE_SYSTEM_FILED = 'beantragt_delivery:auto_case_system_filed';

  /**
   * The connection closes the procedure once the transfer was accepted.
   */
  public const CLOSED_ON_RECEIPT = 'beantragt_delivery:auto_closed_on_receipt';

  /**
   * A staff member released an approval drafted in beantragt.ai.
   */
  public const DECISION_RELEASED_APPROVED = 'beantragt_docgen:decision_released_approved';

  /**
   * A staff member released a rejection drafted in beantragt.ai.
   */
  public const DECISION_RELEASED_REJECTED = 'beantragt_docgen:decision_released_rejected';

  /**
   * Outcomes a response may carry.
   */
  public const OUTCOMES = ['approved', 'rejected', 'filed'];

  /**
   * Flag of the standard's in-progress status ("In Bearbeitung").
   *
   * The acceptance of a transfer moves an application of a form without the
   * function "Übergabe an ein Fachverfahren" there (caseSystemAccepted()).
   */
  public const FLAG_IN_PROGRESS = 'documents_received';

  /**
   * Flag of the start status ("Eingegangen").
   */
  public const FLAG_RECEIVED = 'received';

  /**
   * Why an accepted transfer left a case in its start status (closed list).
   *
   * Only on a form without the hand-over function, only for a case in its
   * start status: no in-progress status in the governing workflow, no
   * transition from the current status to it, or a transition that needs
   * attributes a system change cannot give.
   */
  public const ACCEPTED_UNCHANGED_REASONS = [
    'no_target_status',
    'no_transition',
    'transition_attributes_required',
  ];

  /**
   * Attributes of the stored workflow attributes no transition asks for.
   *
   * Mirrors WorkflowManager::SYSTEM_WORKFLOW_ATTRIBUTES.
   */
  private const SYSTEM_WORKFLOW_ATTRIBUTES = ['submissionLocale' => TRUE];

  /**
   * Allowed follow-up flags per event; the first entry is the default.
   *
   * Events: approved, rejected and filed are reply outcomes, decision is a
   * reply without outcome, receipt is the accepted transfer under the
   * closure rule on_receipt.
   */
  public const FOLLOW_UP_FLAGS = [
    'approved' => ['approved', 'decision_issued'],
    'rejected' => ['rejected', 'decision_issued'],
    'filed' => ['filed', 'closed', 'decision_issued'],
    'decision' => ['decision_issued', 'closed'],
    'receipt' => ['filed', 'closed'],
  ];

  /**
   * Reasons whose citizen notice travels in its own mail.
   *
   * The comment mail and the decision release mail already inform the
   * applicant, so the generic status mail would be a second mail. A filing
   * or a closure on receipt has no document for the applicant: the status
   * mail is the only notice, so those reasons are not silent.
   */
  public const SILENT_REASONS = [
    self::COMMENT_REQUEST_INFO,
    self::DECISION_ISSUED,
    self::DECISION_RELEASED,
    self::CASE_SYSTEM_APPROVED,
    self::CASE_SYSTEM_REJECTED,
    self::DECISION_RELEASED_APPROVED,
    self::DECISION_RELEASED_REJECTED,
  ];

  /**
   * Reasons without a person behind them.
   *
   * Their revisions belong to the system (uid 0). A staff comment is a
   * person's act, so its automatic follow-up keeps that person as actor.
   */
  public const SYSTEM_ACTOR_REASONS = [
    self::CASE_SYSTEM_ACCEPTED,
    self::DECISION_ISSUED,
    self::CASE_SYSTEM_APPROVED,
    self::CASE_SYSTEM_REJECTED,
    self::CASE_SYSTEM_FILED,
    self::CLOSED_ON_RECEIPT,
  ];

  /**
   * Stable civic.revision.v1 event code of each reason.
   *
   * The status revision stores the code next to status_changed, so the
   * history names the cause without generated prose.
   *
   * @see \Drupal\beantragt_application\RevisionLog::EVENTS
   */
  public const REVISION_EVENTS = [
    self::COMMENT_REQUEST_INFO => 'comment_request_info',
    self::CASE_SYSTEM_ACCEPTED => 'case_system_accepted',
    self::DECISION_ISSUED => 'decision_issued',
    self::DECISION_RELEASED => 'decision_released',
    self::CASE_SYSTEM_APPROVED => 'case_system_approved',
    self::CASE_SYSTEM_REJECTED => 'case_system_rejected',
    self::CASE_SYSTEM_FILED => 'case_system_filed',
    self::CLOSED_ON_RECEIPT => 'closed_on_receipt',
    self::DECISION_RELEASED_APPROVED => 'decision_released_approved',
    self::DECISION_RELEASED_REJECTED => 'decision_released_rejected',
  ];

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the changer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\beantragt_application\Service\EffectiveWorkflowResolver|null $workflowResolver
   *   The resolver of the governing workflow. Without it every lookup asks
   *   the whole catalogue, as before the lookup rule.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ?EffectiveWorkflowResolver $workflowResolver = NULL,
  ) {
    $this->logger = $loggerFactory->get('beantragt_workflow');
  }

  /**
   * Returns the statuses of the workflow that governs the application.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param int $group_id
   *   The group whose catalogue is asked.
   *
   * @return int[]|null
   *   Status ids of the governing workflow in the group; NULL when no
   *   resolver is available (no restriction).
   */
  public function governingStatusIds(ApplicationInterface $application, int $group_id): ?array {
    return $this->workflowResolver?->governingStatusIds($application, $group_id);
  }

  /**
   * Records that the case system accepted the application.
   *
   * Under the closure rules on_response and manual the application moves to
   * in_case_system, and only where the status graph has a transition to it:
   * staff may have moved the application on already, and they own the
   * graph. A form without the function "Übergabe an ein Fachverfahren" (its
   * governing workflow has no in_case_system status) gets the standard's
   * meaning instead: the acceptance starts the work, so an application in
   * its start status (flag received) moves to the in-progress status (flag
   * documents_received), again only along an edge of the graph; an
   * application already further stays. Under on_receipt the accepted
   * transfer closes the procedure (follow_up.receipt, default filed),
   * outside the graph like a notice, with or without the function.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $closure_rule
   *   The closure rule of the connection that sent the application.
   * @param array<string, string> $follow_up
   *   Follow-up flags of that connection; missing or invalid entries use the
   *   defaults.
   *
   * @return bool
   *   TRUE when the status changed. Never throws.
   */
  public function caseSystemAccepted(ApplicationInterface $application, string $closure_rule = 'on_response', array $follow_up = []): bool {
    if ($closure_rule === 'on_receipt') {
      return $this->changeTo(
        $application,
        $this->candidates('filed', self::followUp($follow_up)['receipt']),
        self::CLOSED_ON_RECEIPT,
        TRUE,
        ['closed'],
        TRUE,
      );
    }
    if ($this->governingWorkflowLacks($application, 'in_case_system')) {
      return $this->acceptOnStandard($application)['changed'];
    }
    return $this->change(
      $application,
      ['in_case_system'],
      self::CASE_SYSTEM_ACCEPTED,
      FALSE,
      ['in_case_system', 'closed'],
    );
  }

  /**
   * Records the acceptance and says why a start status did not move.
   *
   * The same change as caseSystemAccepted(). "unchanged" is set only on a
   * form without the hand-over function for a case still in its start
   * status, with a code of ACCEPTED_UNCHANGED_REASONS; the caller stores it
   * with the transfer so staff see it. A case past the start status, a
   * closure on receipt and a form with the function never carry it.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $closure_rule
   *   The closure rule of the connection that sent the application.
   * @param array<string, string> $follow_up
   *   Follow-up flags of that connection.
   *
   * @return array{changed: bool, unchanged: string|null}
   *   Whether the status changed, and why a start status did not.
   */
  public function caseSystemAcceptedOutcome(ApplicationInterface $application, string $closure_rule = 'on_response', array $follow_up = []): array {
    if ($closure_rule !== 'on_receipt' && $this->governingWorkflowLacks($application, 'in_case_system')) {
      return $this->acceptOnStandard($application);
    }
    return ['changed' => $this->caseSystemAccepted($application, $closure_rule, $follow_up), 'unchanged' => NULL];
  }

  /**
   * The acceptance on a form without the hand-over function.
   *
   * A case in its start status (flag received) moves to the in-progress
   * status (flag documents_received) of the governing workflow, along an
   * allowed transition. What would stop the save is checked first, so the
   * reason is known: no such status, no transition from the current status,
   * or a transition that needs attributes. Any other case stays without a
   * reason. Never throws.
   *
   * @return array{changed: bool, unchanged: string|null}
   *   Whether the status changed, and why a start status did not.
   */
  protected function acceptOnStandard(ApplicationInterface $application): array {
    $unchanged = static fn(?string $reason): array => ['changed' => FALSE, 'unchanged' => $reason];
    try {
      $stored = $application->isNew() ? NULL : $this->entityTypeManager->getStorage('application')->loadUnchanged($application->id());
      $current = $stored instanceof ApplicationInterface ? $this->currentStatus($stored) : NULL;
      if (!$stored instanceof ApplicationInterface || $current === NULL || $current->isTerminal() || !$current->hasSemanticFlag(self::FLAG_RECEIVED)) {
        // A case past the start status: the expected outcome, no note.
        $this->logger->info('Application @id: automatic change @reason not applicable, the current status carries none of the flags @flags.', [
          '@id' => $application->id(),
          '@reason' => self::CASE_SYSTEM_ACCEPTED,
          '@flags' => self::FLAG_RECEIVED,
        ]);
        return $unchanged(NULL);
      }
      $group_id = (int) $current->getGroupId();
      $candidates = [['flags' => [self::FLAG_IN_PROGRESS], 'exclude' => ['closed']]];
      $target = $this->firstCandidate($group_id, $candidates, FALSE, $this->governingStatusIds($stored, $group_id));
      $reason = match (TRUE) {
        $target === NULL => 'no_target_status',
        !in_array((int) $target->id(), $this->allowedTargets($stored, $current), TRUE) => 'no_transition',
        $this->needsAttributes($stored, $current, $target) => 'transition_attributes_required',
        default => NULL,
      };
      if ($reason !== NULL) {
        $this->logger->info('Application @id: accepted transfer, status unchanged (@code).', [
          '@id' => $application->id(),
          '@code' => $reason,
        ]);
        return $unchanged($reason);
      }
      return [
        'changed' => $this->changeTo($application, $candidates, self::CASE_SYSTEM_ACCEPTED, FALSE, [], FALSE, [self::FLAG_RECEIVED]),
        'unchanged' => NULL,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Application @id: automatic change @reason failed: @message', [
        '@id' => $application->id(),
        '@reason' => self::CASE_SYSTEM_ACCEPTED,
        '@message' => $e->getMessage(),
      ]);
      return $unchanged(NULL);
    }
  }

  /**
   * Status ids a person could move the application to from its status.
   *
   * @return int[]
   *   The governing workflow's targets, or the status's own edges when the
   *   resolver leaves the choice to the organisation graph.
   */
  protected function allowedTargets(ApplicationInterface $application, WorkflowStatusInterface $current): array {
    $targets = $this->workflowResolver?->allowedTargetIds($application, $current);
    return array_map('intval', $targets ?? $current->getAllowedNextIds());
  }

  /**
   * Whether a transition asks for attributes a system change cannot give.
   *
   * The check WorkflowManager runs at save: the transition's attribute
   * definitions against the stored attributes, system attributes left out.
   */
  protected function needsAttributes(ApplicationInterface $application, WorkflowStatusInterface $from, WorkflowStatusInterface $to): bool {
    if ($this->workflowResolver === NULL) {
      return FALSE;
    }
    $definitions = $this->workflowResolver->transitionAttributes($application, $from, $to);
    if ($definitions === []) {
      return FALSE;
    }
    $values = [];
    if ($application->hasField('field_workflow_attributes') && !$application->get('field_workflow_attributes')->isEmpty()) {
      $decoded = json_decode((string) $application->get('field_workflow_attributes')->value, TRUE);
      $values = is_array($decoded) ? array_diff_key($decoded, self::SYSTEM_WORKFLOW_ATTRIBUTES) : [];
    }
    return $this->workflowResolver->validateTransitionAttributes($definitions, $values) !== [];
  }

  /**
   * Whether the workflow governing an application has no status with a flag.
   *
   * FALSE without a resolver (every lookup then asks the whole catalogue, as
   * before the lookup rule) and on any error: the caller keeps its old path.
   */
  protected function governingWorkflowLacks(ApplicationInterface $application, string $flag): bool {
    if ($this->workflowResolver === NULL || $application->isNew()) {
      return FALSE;
    }
    try {
      $stored = $this->entityTypeManager->getStorage('application')->loadUnchanged($application->id());
      $current = $stored instanceof ApplicationInterface ? $this->currentStatus($stored) : NULL;
      if ($current === NULL || (int) $current->getGroupId() <= 0) {
        return FALSE;
      }
      $group_id = (int) $current->getGroupId();
      return $this->resolve($group_id, [$flag], [], FALSE, $this->governingStatusIds($stored, $group_id)) === NULL;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Closes the procedure because a response arrived.
   *
   * Decided by the product owner on 2026-10-20: once the notice exists, the
   * procedure counts as closed, whatever the status graph says. The outcome
   * of the response picks the target (follow_up of the connection, then the
   * fallbacks of resolveCandidates()). Without an outcome a neutral closed
   * status is used; approved, rejected or withdrawn would claim an outcome
   * the notice may not have.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string|null $outcome
   *   approved, rejected, filed, or NULL for a response without outcome.
   * @param bool $released_by_person
   *   TRUE when the current user released the notice in beantragt.ai; the
   *   status revision then names that person instead of the system.
   * @param array<string, string> $follow_up
   *   Follow-up flags of the connection; missing entries use the defaults.
   * @param string $source
   *   The source: fit_connect (any connection) or docgen.
   *
   * @return bool
   *   TRUE when the status changed. Never throws.
   */
  public function caseSystemResponded(ApplicationInterface $application, ?string $outcome, bool $released_by_person = FALSE, array $follow_up = [], string $source = 'fit_connect'): bool {
    if ($outcome !== NULL && !in_array($outcome, self::OUTCOMES, TRUE)) {
      $this->logger->warning('Application @id: unknown response outcome, automatic change skipped.', ['@id' => $application->id()]);
      return FALSE;
    }
    $follow_up = self::followUp($follow_up);
    return $this->changeTo(
      $application,
      $this->candidates($outcome, $outcome === NULL ? $follow_up['decision'] : $follow_up[$outcome]),
      self::responseReason($outcome, $released_by_person, $source),
      TRUE,
      ['closed'],
      TRUE,
    );
  }

  /**
   * Closes the procedure because a decision notice without outcome exists.
   *
   * Kept for backwards compatibility: caseSystemResponded() without outcome.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param bool $released_by_person
   *   TRUE when the current user released the notice in beantragt.ai.
   *
   * @return bool
   *   TRUE when the status changed.
   */
  public function decisionIssued(ApplicationInterface $application, bool $released_by_person = FALSE): bool {
    return $this->caseSystemResponded($application, NULL, $released_by_person, [], $released_by_person ? 'docgen' : 'fit_connect');
  }

  /**
   * Normalizes follow-up flags; missing or invalid entries use the default.
   *
   * @param array<string, mixed> $follow_up
   *   Stored or submitted follow-up flags.
   *
   * @return array<string, string>
   *   One allowed flag per event of FOLLOW_UP_FLAGS.
   */
  public static function followUp(array $follow_up): array {
    $normalized = [];
    foreach (self::FOLLOW_UP_FLAGS as $event => $allowed) {
      $value = $follow_up[$event] ?? NULL;
      $normalized[$event] = in_array($value, $allowed, TRUE) ? $value : $allowed[0];
    }
    return $normalized;
  }

  /**
   * Chooses the reason, and with it event, author and mail, of a response.
   *
   * A response over a connection names the case system. A docgen release
   * names the releasing person; a resume by someone else names neither the
   * person nor the case system (DECISION_ISSUED).
   */
  public static function responseReason(?string $outcome, bool $released_by_person, string $source): string {
    if ($source === 'docgen') {
      if (!$released_by_person) {
        return self::DECISION_ISSUED;
      }
      return match ($outcome) {
        'approved' => self::DECISION_RELEASED_APPROVED,
        'rejected' => self::DECISION_RELEASED_REJECTED,
        default => self::DECISION_RELEASED,
      };
    }
    return match ($outcome) {
      'approved' => self::CASE_SYSTEM_APPROVED,
      'rejected' => self::CASE_SYSTEM_REJECTED,
      'filed' => self::CASE_SYSTEM_FILED,
      default => self::DECISION_ISSUED,
    };
  }

  /**
   * Target candidates of a closing change, the first match wins.
   *
   * @param string|null $outcome
   *   approved, rejected, filed (also for the receipt), or NULL.
   * @param string $flag
   *   The follow-up flag of the event.
   *
   * @return list<array{flags: string[], exclude: string[]}>
   *   Candidates in order of preference.
   */
  protected function candidates(?string $outcome, string $flag): array {
    $outcomes = ['approved', 'rejected', 'withdrawn'];
    return match ($outcome) {
      'approved', 'rejected' => [
        ['flags' => [$flag], 'exclude' => [$outcome === 'approved' ? 'rejected' : 'approved', 'withdrawn']],
        ['flags' => ['decision_issued'], 'exclude' => $outcomes],
        ['flags' => ['closed'], 'exclude' => [...$outcomes, 'filed']],
      ],
      // A filing has no notice: an explicit "closed" must not land on a
      // decision status, the fallbacks below keep that order.
      'filed' => [
        ['flags' => [$flag], 'exclude' => $flag === 'decision_issued' ? $outcomes : [...$outcomes, 'decision_issued']],
        ['flags' => ['closed'], 'exclude' => [...$outcomes, 'decision_issued']],
        ['flags' => ['decision_issued'], 'exclude' => $outcomes],
      ],
      default => [
        ['flags' => [$flag], 'exclude' => $outcomes],
        ['flags' => ['closed'], 'exclude' => $outcomes],
      ],
    };
  }

  /**
   * Changes the status to the first status carrying one of the flags.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application; its stored revision is changed, not this object.
   * @param string[] $flags
   *   Semantic flags in order of preference.
   * @param string $reason
   *   One of the reason constants of this class.
   * @param bool $outside_graph
   *   TRUE when the change must happen even if the status graph has no
   *   transition to the target. Reserved for facts the graph cannot veto,
   *   such as a decision notice that already exists.
   * @param string[] $skip_when_current_has
   *   Flags of the current status that make the change unnecessary.
   * @param string[] $exclude_flags
   *   Flags a target status must not carry.
   *
   * @return bool
   *   TRUE when the status changed. Never throws.
   */
  protected function change(ApplicationInterface $application, array $flags, string $reason, bool $outside_graph = FALSE, array $skip_when_current_has = [], array $exclude_flags = []): bool {
    return $this->changeTo($application, [['flags' => $flags, 'exclude' => $exclude_flags]], $reason, $outside_graph, $skip_when_current_has);
  }

  /**
   * Changes the status to the first matching candidate.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application; its stored revision is changed, not this object.
   * @param list<array{flags: string[], exclude: string[]}> $candidates
   *   Flag sets with their exclusions, in order of preference.
   * @param string $reason
   *   One of the reason constants of this class.
   * @param bool $outside_graph
   *   TRUE when the change must happen even if the status graph has no
   *   transition to the target.
   * @param string[] $skip_when_current_has
   *   Flags of the current status that make the change unnecessary.
   * @param bool $closing
   *   TRUE for a closing change: within each candidate a terminal status
   *   wins over a lighter non-terminal one, so a closed procedure stays
   *   closed (a non-terminal "closed" status is only the last resort).
   * @param string[] $only_when_current_has
   *   When not empty: the change applies only to a current status carrying
   *   one of these flags; any other status stays.
   *
   * @return bool
   *   TRUE when the status changed. Never throws.
   */
  protected function changeTo(ApplicationInterface $application, array $candidates, string $reason, bool $outside_graph = FALSE, array $skip_when_current_has = [], bool $closing = FALSE, array $only_when_current_has = []): bool {
    try {
      // Work on the stored state: pending edits of the caller must not ride
      // along, and a refused change must not leave a half-changed object.
      $stored = $application->isNew() ? NULL : $this->entityTypeManager->getStorage('application')->loadUnchanged($application->id());
      if (!$stored instanceof ApplicationInterface) {
        return FALSE;
      }
      $current = $this->currentStatus($stored);
      if ($current === NULL || $current->isTerminal() || array_intersect($skip_when_current_has, $current->getSemanticFlags()) !== []) {
        return FALSE;
      }
      if ($only_when_current_has !== [] && array_intersect($only_when_current_has, $current->getSemanticFlags()) === []) {
        // A normal outcome, not a fault: the application is past that point.
        $this->logger->info('Application @id: automatic change @reason not applicable, the current status carries none of the flags @flags.', [
          '@id' => $stored->id(),
          '@reason' => $reason,
          '@flags' => implode('|', $only_when_current_has),
        ]);
        return FALSE;
      }
      $group_id = (int) $current->getGroupId();
      $governing = $this->governingStatusIds($stored, $group_id);
      $target = $this->firstCandidate($group_id, $candidates, $closing, $governing);
      // A notice is a fact the graph cannot veto: a workflow without a
      // fitting closing status closes in the catalogue, as before.
      if ($target === NULL && $outside_graph && $governing !== NULL) {
        $target = $this->firstCandidate($group_id, $candidates, $closing, NULL);
      }
      if ($target === NULL) {
        $context = [
          '@id' => $stored->id(),
          '@flags' => implode('|', array_merge(...array_column($candidates, 'flags'))),
          '@reason' => $reason,
        ];
        if ($governing !== NULL && !$outside_graph) {
          // The workflow of this form has no such status: a normal outcome.
          // A closing change that found nothing in the catalogue either
          // leaves the case open: that stays a warning.
          $this->logger->info('Application @id: the governing workflow has no status with flag @flags, automatic change @reason not applicable.', $context);
        }
        else {
          $this->logger->warning('Application @id: no workflow status with flag @flags, automatic change @reason skipped.', $context);
        }
        return FALSE;
      }
      if ((int) $target->id() === (int) $current->id()) {
        return FALSE;
      }

      $stored->setWorkflowStatusId((int) $target->id());
      $stored->markSystemStatusChange($reason);
      if ($outside_graph) {
        $stored->allowWorkflowStatusReset();
      }
      $stored->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      // The trigger is a fact already (a sent transfer, a stored notice, a
      // comment); a status the graph refuses must never undo it.
      $this->logger->warning('Application @id: automatic change @reason failed: @message', [
        '@id' => $application->id(),
        '@reason' => $reason,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Returns the target of the first candidate that matches.
   *
   * @param int $group_id
   *   The group owning the catalogue.
   * @param list<array{flags: string[], exclude: string[]}> $candidates
   *   Flag sets with their exclusions, in order of preference.
   * @param bool $closing
   *   TRUE to prefer terminal statuses within each candidate.
   * @param int[]|null $within
   *   Status ids to choose from; NULL = the whole catalogue.
   */
  protected function firstCandidate(int $group_id, array $candidates, bool $closing, ?array $within): ?WorkflowStatusInterface {
    foreach ($candidates as $candidate) {
      $target = $this->resolve($group_id, $candidate['flags'], $candidate['exclude'], $closing, $within);
      if ($target !== NULL) {
        return $target;
      }
    }
    return NULL;
  }

  /**
   * Resolves the lightest status of a catalogue carrying one of the flags.
   *
   * Flags are tried in order, so a specific flag wins over a generic one.
   *
   * @param int $group_id
   *   The group owning the catalogue.
   * @param string[] $flags
   *   Semantic flags in order of preference.
   * @param string[] $exclude_flags
   *   Flags a matching status must not carry.
   * @param bool $prefer_terminal
   *   TRUE to try terminal statuses first for each flag, then the others.
   * @param int[]|null $within
   *   Status ids to choose from, usually governingStatusIds(); NULL = the
   *   whole catalogue.
   */
  public function resolve(int $group_id, array $flags, array $exclude_flags = [], bool $prefer_terminal = FALSE, ?array $within = NULL): ?WorkflowStatusInterface {
    if ($group_id <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->sort('field_weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    if ($within !== NULL) {
      $ids = array_intersect($ids, array_map('intval', $within));
      if ($ids === []) {
        return NULL;
      }
    }
    $statuses = array_filter(
      $storage->loadMultiple($ids),
      static fn($status): bool => $status instanceof WorkflowStatusInterface,
    );
    $passes = $prefer_terminal ? [TRUE, FALSE] : [NULL];
    foreach ($flags as $flag) {
      foreach ($passes as $terminal) {
        foreach ($statuses as $status) {
          if (($terminal === NULL || $status->isTerminal() === $terminal)
            && $status->hasSemanticFlag($flag)
            && array_intersect($exclude_flags, $status->getSemanticFlags()) === []) {
            return $status;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Loads the current workflow status of an application.
   */
  protected function currentStatus(ApplicationInterface $application): ?WorkflowStatusInterface {
    $status_id = $application->getWorkflowStatusId();
    if ($status_id === NULL || $status_id <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return NULL;
    }
    $status = $this->entityTypeManager->getStorage('workflow_status')->load($status_id);
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

}
