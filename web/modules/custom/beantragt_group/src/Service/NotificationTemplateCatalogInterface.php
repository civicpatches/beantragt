<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Exposes the notification template catalogue to jurisdiction settings.
 *
 * The concrete catalogue lives in beantragt_notification. The interface stays
 * here so beantragt_group can validate the optional notification_mail section
 * without declaring a hard module dependency back to beantragt_notification.
 */
interface NotificationTemplateCatalogInterface {

  /**
   * Returns all template definitions keyed by template id.
   *
   * @return array<string, array{subject: string, body: string, enabled: bool, requiredTokens: list<string>, lockedEnabled: bool}>
   *   Template definitions.
   */
  public function all(): array;

  /**
   * Returns valid template ids in catalogue order.
   *
   * @return list<string>
   *   Template ids.
   */
  public function keys(): array;

  /**
   * Checks whether a template id is known.
   */
  public function has(string $templateKey): bool;

  /**
   * Returns one template definition, or NULL when the id is unknown.
   *
   * @return array{subject: string, body: string, enabled: bool, requiredTokens: list<string>, lockedEnabled: bool}|null
   *   Template definition.
   */
  public function get(string $templateKey): ?array;

  /**
   * Returns required tokens for one template id.
   *
   * @return list<string>
   *   Required token strings, including the leading "@".
   */
  public function requiredTokens(string $templateKey): array;

  /**
   * Returns TRUE when the template must never be disabled per jurisdiction.
   */
  public function isLockedEnabled(string $templateKey): bool;

}
