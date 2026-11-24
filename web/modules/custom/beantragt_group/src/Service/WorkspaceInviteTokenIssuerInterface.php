<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Issues persisted workspace invite tokens with an immutable scope snapshot.
 */
interface WorkspaceInviteTokenIssuerInterface {

  public const PURPOSE_MEMBER_INVITE = 'member_invite';
  public const PURPOSE_WORKSPACE_CREATE = 'workspace_create';
  public const TOKEN_EXPIRY_SECONDS = 604800;

  /**
   * Creates a pending invite token record.
   *
   * @param \Drupal\user\UserInterface $issuer
   *   User issuing the invitation.
   * @param \Drupal\group\Entity\GroupInterface $target_parent
   *   Jurisdiction that bounds the invite.
   * @param string $email
   *   Invitee email address.
   * @param string $purpose
   *   One of the PURPOSE_* constants.
   * @param string[] $roles
   *   Optional group role IDs for member invitations.
   * @param \Drupal\group\Entity\GroupInterface|null $target_group
   *   Optional concrete target group for member invitations.
   * @param int $ttl
   *   Token lifetime in seconds.
   * @param array<string, mixed> $payload
   *   Optional provisioning metadata.
   *
   * @return array<string, mixed>
   *   Inserted invite data, including the generated token.
   */
  public function issue(
    UserInterface $issuer,
    GroupInterface $target_parent,
    string $email,
    string $purpose = self::PURPOSE_WORKSPACE_CREATE,
    array $roles = [],
    ?GroupInterface $target_group = NULL,
    int $ttl = self::TOKEN_EXPIRY_SECONDS,
    array $payload = [],
  ): array;

  /**
   * Lists pending invite tokens below a target parent jurisdiction.
   *
   * @return array<int, array<string, mixed>>
   *   Pending invite records without plaintext token values.
   */
  public function listPending(UserInterface $issuer, GroupInterface $target_parent): array;

  /**
   * Revokes one pending invite token below a target parent jurisdiction.
   */
  public function revoke(UserInterface $issuer, GroupInterface $target_parent, int $invitation_id): bool;

  /**
   * Inspects a token without mutating it.
   *
   * @return array<string, mixed>
   *   Status payload. Status is one of pending, missing, expired or claimed.
   */
  public function inspect(string $token): array;

  /**
   * Atomically claims a pending token.
   *
   * @return array<string, mixed>
   *   Status payload. Status is claimed, missing, expired or already_claimed.
   */
  public function claim(string $token): array;

}
