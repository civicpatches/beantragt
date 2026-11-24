<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Language-neutral action metadata in the standard revision log field.
 *
 * Contract shared with Mark-a-Spot: civic.revision.v1. Human explanations are
 * retained verbatim; clients translate the action codes, never the audit data.
 */
final class RevisionLog {

  /**
   * Stable automatic causes. Human explanations are kept separately.
   */
  public const EVENTS = [
    'assignment_changed', 'assignment_sync', 'delegated', 'data_edited',
    'attachment_deleted', 'proof_assignment_corrected', 'label_generated', 'anonymized',
    'status_changed', 'status_reset', 'comment_request_info', 'due_date_changed',
    'case_system_accepted', 'decision_issued', 'decision_released',
    'case_system_approved', 'case_system_rejected', 'case_system_filed',
    'closed_on_receipt', 'decision_released_approved', 'decision_released_rejected',
  ];

  /**
   * Explicit reasons awaiting their entity save.
   *
   * @var \WeakMap<\Drupal\Core\Entity\ContentEntityInterface, string>|null
   */
  private static ?\WeakMap $pendingReasons = NULL;

  /**
   * Automatic causes awaiting their entity save.
   *
   * @var \WeakMap<\Drupal\Core\Entity\ContentEntityInterface, string[]>|null
   */
  private static ?\WeakMap $pendingEvents = NULL;

  /**
   * Marks a server-generated event without storing translated prose or names.
   */
  public static function setEvent(ContentEntityInterface $entity, string $event): void {
    if (!$entity instanceof RevisionLogInterface || !in_array($event, self::EVENTS, TRUE)) {
      throw new \InvalidArgumentException('A supported revision event is required.');
    }
    self::$pendingEvents ??= new \WeakMap();
    self::$pendingEvents[$entity] = array_values(array_unique([
      ...(self::$pendingEvents[$entity] ?? []), $event,
    ]));
  }

  /**
   * Marks an explicit reason, including a deliberate repetition of an old one.
   */
  public static function setMessage(ContentEntityInterface $entity, string $message): void {
    if (!$entity instanceof RevisionLogInterface) {
      throw new \InvalidArgumentException('A revision log entity is required.');
    }
    self::$pendingReasons ??= new \WeakMap();
    self::$pendingReasons[$entity] = $message;
    $entity->setRevisionLogMessage($message);
  }

  /**
   * Returns an explicit pending reason without consuming it.
   */
  public static function pendingMessage(ContentEntityInterface $entity): ?string {
    return self::$pendingReasons !== NULL ? (self::$pendingReasons[$entity] ?? NULL) : NULL;
  }

  /**
   * Builds a log exclusively from the actual entity transition.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being saved.
   * @param array<string, string> $fields
   *   Entity reference field names mapped to stable domain concepts.
   */
  public static function build(ContentEntityInterface $entity, array $fields): string {
    if (!$entity instanceof RevisionLogInterface) {
      throw new \InvalidArgumentException('A revision log entity is required.');
    }
    $original = $entity->getOriginal();
    $message = (string) $entity->getRevisionLogMessage();
    // Only an explicitly supplied reason may repeat the previous message.
    // Different incoming text remains text, never trusted action metadata.
    if (self::$pendingReasons !== NULL && isset(self::$pendingReasons[$entity])) {
      $message = self::$pendingReasons[$entity];
      unset(self::$pendingReasons[$entity]);
    }
    elseif ($original instanceof RevisionLogInterface && $message === (string) $original->getRevisionLogMessage()) {
      $message = '';
    }
    $changes = [];
    foreach ($fields as $field => $concept) {
      if (!$entity->hasField($field)) {
        continue;
      }
      $before = $original instanceof ContentEntityInterface ? self::references($original, $field) : [];
      $after = self::references($entity, $field);
      if (array_column($before, 'id') !== array_column($after, 'id')) {
        $changes[] = ['field' => $concept, 'before' => $before, 'after' => $after];
      }
    }
    $events = self::$pendingEvents !== NULL ? (self::$pendingEvents[$entity] ?? []) : [];
    if (self::$pendingEvents !== NULL) {
      unset(self::$pendingEvents[$entity]);
    }
    return json_encode([
      'schema' => 'civic.revision.v1',
      'action' => $entity->isNew() ? 'created' : 'updated',
      'changes' => $changes,
      'events' => $events,
      'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Reads a versioned envelope; legacy free-text logs remain valid.
   */
  public static function decode(string $value): ?array {
    $data = json_decode($value, TRUE);
    if (!is_array($data) || ($data['schema'] ?? NULL) !== 'civic.revision.v1'
      || !in_array($data['action'] ?? NULL, ['created', 'updated'], TRUE)
      || !is_array($data['changes'] ?? NULL) || !array_is_list($data['changes'])
      || !is_string($data['message'] ?? NULL)) {
      return NULL;
    }
    foreach ($data['changes'] as $change) {
      if (!is_array($change) || !in_array($change['field'] ?? NULL, ['responsibility', 'person', 'team', 'status'], TRUE)) {
        return NULL;
      }
      foreach (['before', 'after'] as $side) {
        if (!is_array($change[$side] ?? NULL) || !array_is_list($change[$side])) {
          return NULL;
        }
        foreach ($change[$side] as $reference) {
          if (!is_array($reference) || !is_string($reference['id'] ?? NULL) || (isset($reference['label']) && !is_string($reference['label']))) {
            return NULL;
          }
        }
      }
    }
    if (isset($data['events']) && (!is_array($data['events']) || !array_is_list($data['events'])
      || array_filter($data['events'], static fn($event): bool => !is_string($event) || !in_array($event, self::EVENTS, TRUE)))) {
      return NULL;
    }
    return $data;
  }

  /**
   * Returns the original message, including existing semantic log prefixes.
   */
  public static function message(string $value): string {
    return self::decode($value)['message'] ?? $value;
  }

  /**
   * Captures stable reference IDs without duplicating names in revision text.
   */
  private static function references(ContentEntityInterface $entity, string $field): array {
    if (!$entity->hasField($field)) {
      return [];
    }
    $result = [];
    foreach ($entity->get($field)->getValue() as $value) {
      if (isset($value['target_id'])) {
        $result[] = ['id' => (string) $value['target_id']];
      }
    }
    usort($result, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return $result;
  }

}
