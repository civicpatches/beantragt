<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Persists Beantragt workspace invite tokens.
 *
 * The 64-hex token handed to the invitee is never stored. Only its
 * HMAC-SHA256 digest is persisted, so a database leak cannot be replayed.
 */
final class WorkspaceInviteTokenIssuer implements WorkspaceInviteTokenIssuerInterface {

  private const TABLE = 'beantragt_group_invitations';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly WorkspaceInviteAccessInterface $inviteAccess,
    private readonly PrivateKey $privateKey,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
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
  ): array {
    $email = $this->normalizeEmail($email);
    $purpose = $this->normalizePurpose($purpose);
    $roles = $this->normalizeRoles($roles);
    $this->assertRolesGrantable($roles);
    $target_parent_id = $this->positiveEntityId($target_parent, 'Target parent');
    $target_group_id = $target_group ? $this->positiveEntityId($target_group, 'Target group') : NULL;
    $issuer_id = $this->positiveEntityId($issuer, 'Issuer');

    if ($ttl <= 0) {
      throw new \InvalidArgumentException('Invite token TTL must be greater than zero.');
    }

    if ($purpose === self::PURPOSE_MEMBER_INVITE && $target_group_id === NULL) {
      throw new \InvalidArgumentException('Member invite tokens require a target group.');
    }

    if (!$this->inviteAccess->canIssue($issuer, $target_parent)) {
      throw new AccessDeniedHttpException('Issuer cannot create invites for the target parent jurisdiction.');
    }

    $now = $this->time->getRequestTime();
    if ($this->hasPendingInvite($email, $purpose, $target_parent_id, $now)) {
      throw new \RuntimeException('A pending invitation for this email, purpose, and target parent already exists.');
    }

    $token = bin2hex(random_bytes(32));
    $record = [
      'token' => $this->hashToken($token),
      'purpose' => $purpose,
      'email' => $email,
      'target_parent_id' => $target_parent_id,
      'group_id' => $target_group_id,
      'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
      'invited_by' => $issuer_id,
      'issuer_scope_id' => $this->inviteAccess->issuerScopeId($issuer),
      'payload' => $payload === [] ? NULL : json_encode($payload, JSON_THROW_ON_ERROR),
      'created' => $now,
      'expires' => $now + $ttl,
      'claimed' => NULL,
    ];

    $id = $this->database->insert(self::TABLE)
      ->fields($record)
      ->execute();

    return [
      'id' => is_numeric($id) ? (int) $id : NULL,
      'token' => $token,
      'purpose' => $purpose,
      'email' => $email,
      'target_parent_id' => $target_parent_id,
      'group_id' => $target_group_id,
      'roles' => $roles,
      'invited_by' => $issuer_id,
      'issuer_scope_id' => $record['issuer_scope_id'],
      'payload' => $payload,
      'created' => $now,
      'expires' => $now + $ttl,
      'claimed' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listPending(UserInterface $issuer, GroupInterface $target_parent): array {
    $this->assertCanManageTargetParent($issuer, $target_parent);

    $target_parent_id = $this->positiveEntityId($target_parent, 'Target parent');
    $rows = $this->database->select(self::TABLE, 'i')
      ->fields('i', [
        'id',
        'purpose',
        'email',
        'target_parent_id',
        'group_id',
        'roles',
        'invited_by',
        'issuer_scope_id',
        'created',
        'expires',
      ])
      ->condition('target_parent_id', $target_parent_id)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->isNull('claimed')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(FetchAs::Associative);

    return array_map(
      static fn(array $row): array => self::normalizeRow($row),
      $rows,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function revoke(UserInterface $issuer, GroupInterface $target_parent, int $invitation_id): bool {
    $this->assertCanManageTargetParent($issuer, $target_parent);

    if ($invitation_id <= 0) {
      throw new \InvalidArgumentException('Invitation ID must be greater than zero.');
    }

    $target_parent_id = $this->positiveEntityId($target_parent, 'Target parent');
    $row = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['id'])
      ->condition('id', $invitation_id)
      ->condition('target_parent_id', $target_parent_id)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->isNull('claimed')
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return FALSE;
    }

    $deleted = $this->database->delete(self::TABLE)
      ->condition('id', $invitation_id)
      ->execute();

    return (int) $deleted > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function inspect(string $token): array {
    $token = $this->normalizeToken($token);
    $row = $this->loadByToken($token);
    if ($row === NULL) {
      return ['status' => 'missing'];
    }

    $invite = self::normalizeRow($row);
    if ($invite['claimed'] !== NULL) {
      return [
        'status' => 'claimed',
        'invite' => $invite,
      ];
    }

    if ($invite['expires'] <= $this->time->getRequestTime()) {
      return [
        'status' => 'expired',
        'invite' => $invite,
      ];
    }

    return [
      'status' => 'pending',
      'invite' => $invite,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function claim(string $token): array {
    $token = $this->normalizeToken($token);
    $token_hash = $this->hashToken($token);
    $now = $this->time->getRequestTime();
    $affected = $this->database->update(self::TABLE)
      ->fields(['claimed' => $now])
      ->condition('token', $token_hash)
      ->condition('expires', $now, '>')
      ->isNull('claimed')
      ->execute();

    if ((int) $affected === 0) {
      $status = $this->inspect($token);
      if (($status['status'] ?? NULL) === 'claimed') {
        $status['status'] = 'already_claimed';
      }
      return $status;
    }

    $row = $this->loadByToken($token);
    if ($row === NULL) {
      return ['status' => 'missing'];
    }

    return [
      'status' => 'claimed',
      'invite' => self::normalizeRow($row),
    ];
  }

  /**
   * Checks whether a matching pending invite already exists.
   */
  private function hasPendingInvite(string $email, string $purpose, int $target_parent_id, int $now): bool {
    $existing = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['id'])
      ->condition('email', $email)
      ->condition('purpose', $purpose)
      ->condition('target_parent_id', $target_parent_id)
      ->condition('expires', $now, '>')
      ->isNull('claimed')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $existing !== FALSE && $existing !== NULL;
  }

  /**
   * Denies token management outside the issuer's invite scope.
   */
  private function assertCanManageTargetParent(UserInterface $issuer, GroupInterface $target_parent): void {
    if (!$this->inviteAccess->canIssue($issuer, $target_parent)) {
      throw new AccessDeniedHttpException('Issuer cannot manage invites for the target parent jurisdiction.');
    }
  }

  /**
   * Normalizes a database row for API output.
   *
   * @param array<string, mixed> $row
   *   Raw database row.
   *
   * @return array<string, mixed>
   *   Normalized row.
   */
  private static function normalizeRow(array $row): array {
    $roles = json_decode((string) ($row['roles'] ?? '[]'), TRUE);
    if (!is_array($roles)) {
      $roles = [];
    }
    $roles = array_values(array_filter($roles, 'is_string'));

    return [
      'id' => (int) $row['id'],
      'purpose' => (string) $row['purpose'],
      'email' => (string) $row['email'],
      'target_parent_id' => (int) $row['target_parent_id'],
      'group_id' => isset($row['group_id']) ? (int) $row['group_id'] : NULL,
      'roles' => $roles,
      'invited_by' => (int) $row['invited_by'],
      'issuer_scope_id' => isset($row['issuer_scope_id']) ? (int) $row['issuer_scope_id'] : NULL,
      'payload' => self::decodePayload($row['payload'] ?? NULL),
      'created' => (int) $row['created'],
      'expires' => (int) $row['expires'],
      'claimed' => isset($row['claimed']) ? (int) $row['claimed'] : NULL,
    ];
  }

  /**
   * Loads one invite row by its raw token.
   *
   * The raw token is hashed before the lookup; only digests are stored.
   *
   * @param string $token
   *   The raw 64-hex token as handed to the invitee.
   *
   * @return array<string, mixed>|null
   *   Invite row or NULL.
   */
  private function loadByToken(string $token): ?array {
    $row = $this->database->select(self::TABLE, 'i')
      ->fields('i')
      ->condition('token', $this->hashToken($token))
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return is_array($row) ? $row : NULL;
  }

  /**
   * Returns the HMAC-SHA256 digest of a raw invite token.
   *
   * The HMAC key is the site private key. Rotating that key invalidates every
   * pending invitation, because the stored digests can no longer be matched.
   * After a key rotation, outstanding invitations must be re-issued.
   *
   * @param string $token
   *   The raw 64-hex token.
   *
   * @return string
   *   The 64-character hex digest stored in the token column.
   */
  private function hashToken(string $token): string {
    return hash_hmac('sha256', $token, $this->privateKey->get());
  }

  /**
   * Rejects role IDs that are not on the configured grantable allowlist.
   *
   * @param string[] $roles
   *   Normalized role IDs.
   */
  private function assertRolesGrantable(array $roles): void {
    if ($roles === []) {
      return;
    }

    $grantable = $this->configFactory->get('beantragt_group.settings')->get('grantable_roles');
    $grantable = is_array($grantable) ? array_values(array_filter($grantable, 'is_string')) : [];

    $role_storage = $this->entityTypeManager->getStorage('group_role');
    foreach ($roles as $role) {
      if (!in_array($role, $grantable, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Role "%s" is not grantable through workspace invites.', $role));
      }
      if ($role_storage->load($role) === NULL) {
        throw new \InvalidArgumentException(sprintf('Role "%s" does not exist.', $role));
      }
    }
  }

  /**
   * Normalizes and validates a token.
   */
  private function normalizeToken(string $token): string {
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
      throw new \InvalidArgumentException('Invite token is not valid.');
    }

    return $token;
  }

  /**
   * Decodes stored invite payload.
   *
   * @return array<string, mixed>
   *   Decoded payload.
   */
  private static function decodePayload(mixed $payload): array {
    if (!is_string($payload) || $payload === '') {
      return [];
    }

    $decoded = json_decode($payload, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Normalizes and validates an invitee email address.
   */
  private function normalizeEmail(string $email): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('Invite email is not valid.');
    }

    return $email;
  }

  /**
   * Normalizes and validates the token purpose.
   */
  private function normalizePurpose(string $purpose): string {
    $purpose = trim($purpose);
    $allowed = [
      self::PURPOSE_MEMBER_INVITE,
      self::PURPOSE_WORKSPACE_CREATE,
    ];
    if (!in_array($purpose, $allowed, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported invite token purpose "%s".', $purpose));
    }

    return $purpose;
  }

  /**
   * Normalizes role IDs for storage.
   *
   * @param mixed[] $roles
   *   Raw role IDs.
   *
   * @return string[]
   *   Non-empty unique role IDs.
   */
  private function normalizeRoles(array $roles): array {
    $normalized = [];
    foreach ($roles as $role) {
      if (!is_string($role)) {
        continue;
      }
      $role = trim($role);
      if ($role !== '') {
        $normalized[] = $role;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * Returns a positive numeric entity ID.
   */
  private function positiveEntityId(object $entity, string $label): int {
    if (!method_exists($entity, 'id')) {
      throw new \InvalidArgumentException(sprintf('%s entity must expose an id().', $label));
    }

    $id = $entity->id();
    $id = is_numeric($id) ? (int) $id : 0;
    if ($id <= 0) {
      throw new \InvalidArgumentException(sprintf('%s entity must be saved before issuing an invite.', $label));
    }

    return $id;
  }

}
