<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\WorkspaceInviteAccess;
use Drupal\beantragt_group\Service\WorkspaceInviteTokenIssuerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership as GroupMembershipEntity;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * API controller for scoped workspace invitations.
 */
final class WorkspaceInviteController implements ContainerInjectionInterface {

  private const CLAIM_FLOOD_EVENT = 'beantragt_group.workspace_invite_claim';
  private const CLAIM_FLOOD_LIMIT = 5;
  private const CLAIM_FLOOD_WINDOW = 3600;

  private const VERIFY_FLOOD_EVENT = 'beantragt_group.workspace_invite_verify';
  private const VERIFY_FLOOD_LIMIT = 20;
  private const VERIFY_FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceInviteTokenIssuerInterface $tokenIssuer,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly Connection $database,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
    private readonly FloodInterface $flood,
    private readonly ?VerifiedAccountMail $verifiedMail = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('beantragt_group.workspace_invite_token_issuer'),
      $container->get('beantragt_group.jurisdiction_hierarchy_resolver'),
      $container->get('database'),
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('language_manager'),
      $container->get('logger.factory')->get('beantragt_group'),
      $container->get('flood'),
      $container->has('beantragt_passwordless.verified_mail') ? $container->get('beantragt_passwordless.verified_mail') : NULL,
    );
  }

  /**
   * Route access for invite management endpoints.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(in_array(WorkspaceInviteAccess::ROLE_ID, $account->getRoles(), TRUE))
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Creates a pending workspace invitation.
   */
  public function createInvite(Request $request): JsonResponse {
    $body = $this->decodeJsonBody($request);
    if ($body === NULL) {
      return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
    }

    $issuer = $this->currentUserEntity();
    if (!$issuer) {
      return $this->jsonError('Authenticated workspace inviter required.', Response::HTTP_FORBIDDEN);
    }

    $target_parent = $this->loadTargetParent((int) ($body['target_parent_id'] ?? 0));
    if (!$target_parent instanceof GroupInterface) {
      return $this->jsonError('A valid target_parent_id is required.', Response::HTTP_BAD_REQUEST);
    }

    $purpose = (string) ($body['purpose'] ?? WorkspaceInviteTokenIssuerInterface::PURPOSE_WORKSPACE_CREATE);
    $target_group = NULL;
    if (!empty($body['group_id'])) {
      $target_group = $this->loadGroup((int) $body['group_id']);
      if (!$target_group instanceof GroupInterface) {
        return $this->jsonError('Target group not found.', Response::HTTP_NOT_FOUND);
      }
    }

    try {
      $membership_target = $target_group ?? $target_parent;
      if (!$this->targetGroupWithinParent($membership_target, $target_parent)) {
        throw new \UnexpectedValueException('Invite target group is outside target parent scope.');
      }
      $this->validatedGrantRoles(
        ['roles' => is_array($body['roles'] ?? NULL) ? $body['roles'] : []],
        $membership_target,
      );
    }
    catch (\UnexpectedValueException $e) {
      return $this->jsonError($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    $frontend_base_url = $this->resolveFrontendBaseUrl();
    if ($frontend_base_url === NULL) {
      return $this->jsonError(
        'Workspace invitations are unavailable. Configure beantragt_group.settings:frontend_base_url with a valid HTTPS URL or a local loopback HTTP URL.',
        Response::HTTP_SERVICE_UNAVAILABLE,
      );
    }

    try {
      $invite = $this->tokenIssuer->issue(
        $issuer,
        $target_parent,
        (string) ($body['email'] ?? ''),
        $purpose,
        is_array($body['roles'] ?? NULL) ? $body['roles'] : [],
        $target_group,
        WorkspaceInviteTokenIssuerInterface::TOKEN_EXPIRY_SECONDS,
        is_array($body['payload'] ?? NULL) ? $body['payload'] : [],
      );
    }
    catch (AccessDeniedHttpException) {
      return $this->jsonError('Access denied to target parent.', Response::HTTP_FORBIDDEN);
    }
    catch (\InvalidArgumentException $e) {
      return $this->jsonError($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\RuntimeException $e) {
      return $this->jsonError($e->getMessage(), Response::HTTP_CONFLICT);
    }

    $mail_sent = $this->sendInvitationMail($invite, $target_parent, $frontend_base_url);

    return new JsonResponse([
      'status' => 'invited',
      'mail_sent' => $mail_sent,
      'invite' => $this->publicInviteResponse($invite),
    ], Response::HTTP_ACCEPTED);
  }

  /**
   * Lists pending invitations for a target parent jurisdiction.
   */
  public function listInvites(Request $request): JsonResponse {
    $issuer = $this->currentUserEntity();
    if (!$issuer) {
      return $this->jsonError('Authenticated workspace inviter required.', Response::HTTP_FORBIDDEN);
    }

    $target_parent = $this->loadTargetParent((int) $request->query->get('target_parent_id', 0));
    if (!$target_parent instanceof GroupInterface) {
      return $this->jsonError('target_parent_id query parameter is required.', Response::HTTP_BAD_REQUEST);
    }

    try {
      $invites = $this->tokenIssuer->listPending($issuer, $target_parent);
    }
    catch (AccessDeniedHttpException) {
      return $this->jsonError('Access denied to target parent.', Response::HTTP_FORBIDDEN);
    }

    return new JsonResponse(['invitations' => $invites]);
  }

  /**
   * Revokes one pending invitation.
   */
  public function revokeInvite(int $invitation_id, Request $request): JsonResponse {
    $issuer = $this->currentUserEntity();
    if (!$issuer) {
      return $this->jsonError('Authenticated workspace inviter required.', Response::HTTP_FORBIDDEN);
    }

    $target_parent = $this->loadTargetParent((int) $request->query->get('target_parent_id', 0));
    if (!$target_parent instanceof GroupInterface) {
      return $this->jsonError('target_parent_id query parameter is required.', Response::HTTP_BAD_REQUEST);
    }

    try {
      $revoked = $this->tokenIssuer->revoke($issuer, $target_parent, $invitation_id);
    }
    catch (AccessDeniedHttpException) {
      return $this->jsonError('Access denied to target parent.', Response::HTTP_FORBIDDEN);
    }
    catch (\InvalidArgumentException $e) {
      return $this->jsonError($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    if (!$revoked) {
      return $this->jsonError('Invitation not found or already claimed.', Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse(['status' => 'revoked']);
  }

  /**
   * Verifies a workspace invitation token without claiming it.
   *
   * This endpoint is anonymous so the invitee can preview the invite before
   * authenticating. It is IP rate-limited and exposes only non-PII fields,
   * and it collapses every non-pending state into one indistinguishable 404
   * so the token cannot be used as a state oracle.
   */
  public function verifyInvite(string $token, Request $request): JsonResponse {
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::VERIFY_FLOOD_EVENT, self::VERIFY_FLOOD_LIMIT, self::VERIFY_FLOOD_WINDOW, $ip)) {
      return $this->jsonError('Too many attempts. Try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register(self::VERIFY_FLOOD_EVENT, self::VERIFY_FLOOD_WINDOW, $ip);

    try {
      $status = $this->tokenIssuer->inspect($token);
    }
    catch (\InvalidArgumentException) {
      return $this->jsonError('Invalid or already claimed invitation.', Response::HTTP_NOT_FOUND);
    }

    if (($status['status'] ?? 'missing') !== 'pending' || !is_array($status['invite'] ?? NULL)) {
      return $this->jsonError('Invalid or already claimed invitation.', Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse([
      'status' => 'pending',
      'invite' => $this->publicVerifyResponse($status['invite']),
    ]);
  }

  /**
   * Claims a workspace invitation token.
   *
   * Requires an authenticated session. The session account email must match
   * the invitee email recorded on the invite.
   */
  public function claimInvite(string $token, Request $request): JsonResponse {
    $claimant = $this->currentUserEntity();
    if (!$claimant) {
      return $this->jsonError('Authentication is required to claim an invitation.', Response::HTTP_UNAUTHORIZED);
    }

    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::CLAIM_FLOOD_EVENT, self::CLAIM_FLOOD_LIMIT, self::CLAIM_FLOOD_WINDOW, $ip)) {
      return $this->jsonError('Too many attempts. Try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register(self::CLAIM_FLOOD_EVENT, self::CLAIM_FLOOD_WINDOW, $ip);

    try {
      $status = $this->tokenIssuer->inspect($token);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->debug('Workspace invite claim rejected: @message', ['@message' => $e->getMessage()]);
      return $this->jsonError('Invalid invitation token.', Response::HTTP_BAD_REQUEST);
    }

    if (!is_array($status['invite'] ?? NULL)) {
      return $this->tokenStatusResponse($status);
    }

    if (!$this->inviteEmailMatchesClaimant($status['invite'], $claimant)) {
      return $this->jsonError('This invitation is for a different email address.', Response::HTTP_FORBIDDEN);
    }

    if (($status['status'] ?? 'missing') !== 'pending') {
      return $this->tokenStatusResponse($status);
    }

    try {
      $membership_target = $this->membershipTarget($status['invite']);
      $grant_roles = $this->validatedGrantRoles($status['invite'], $membership_target);
    }
    catch (\UnexpectedValueException $e) {
      $this->logger->error('Workspace invite claim rejected because the invite grant is invalid: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->jsonError('Invitation grant configuration is invalid.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction('workspace_invite_claim');
      try {
        $status = $this->tokenIssuer->claim($token);
      }
      catch (\InvalidArgumentException $e) {
        $this->rollBack($transaction);
        $this->logger->debug('Workspace invite claim rejected: @message', ['@message' => $e->getMessage()]);
        return $this->jsonError('Invalid invitation token.', Response::HTTP_BAD_REQUEST);
      }
      if (($status['status'] ?? NULL) === 'claimed' && is_array($status['invite'] ?? NULL)) {
        $this->grantInviteRoles($claimant, $membership_target, $grant_roles);
      }
      unset($transaction);
    }
    catch (\Throwable $e) {
      $this->rollBack($transaction);
      $this->logger->error('Workspace invite claim failed before access could be granted: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->jsonError('Invitation could not be claimed.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return $this->tokenStatusResponse($status);
  }

  /**
   * Decodes a JSON request body.
   *
   * @return mixed[]|null
   *   The decoded associative array, or NULL when the body is empty or not a
   *   valid JSON object.
   */
  private function decodeJsonBody(Request $request): ?array {
    $content = trim($request->getContent());
    if ($content === '') {
      return NULL;
    }

    $decoded = json_decode($content, TRUE);

    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Loads the current account as a user entity.
   */
  private function currentUserEntity(): ?UserInterface {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Loads and validates a target parent jurisdiction group.
   */
  private function loadTargetParent(int $group_id): ?GroupInterface {
    $group = $this->loadGroup($group_id);
    if (!$group instanceof GroupInterface || $group->bundle() !== $this->jurisdictionGroupType()) {
      return NULL;
    }

    return $group;
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory->get('beantragt_group.settings')->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Returns the configured organisation group bundle.
   */
  private function organisationGroupType(): string {
    $configured = $this->configFactory->get('beantragt_group.settings')->get('organisation_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'org';
  }

  /**
   * Loads a group by ID.
   */
  private function loadGroup(int $group_id): ?GroupInterface {
    if ($group_id <= 0) {
      return NULL;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($group_id);

    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Resolves the group receiving invitee membership.
   *
   * @param array<string, mixed> $invite
   *   Normalized invite data.
   */
  private function membershipTarget(array $invite): GroupInterface {
    $target_parent = $this->loadTargetParent((int) ($invite['target_parent_id'] ?? 0));
    if (!$target_parent instanceof GroupInterface) {
      throw new \UnexpectedValueException('Invite target parent is missing.');
    }

    $target_id = (int) ($invite['group_id'] ?? 0);
    if ($target_id <= 0) {
      $group = $target_parent;
    }
    else {
      $group = $this->loadGroup($target_id);
    }

    if (!$group instanceof GroupInterface) {
      throw new \UnexpectedValueException('Invite target group is missing.');
    }

    if (!$this->targetGroupWithinParent($group, $target_parent)) {
      throw new \UnexpectedValueException('Invite target group is outside target parent scope.');
    }

    return $group;
  }

  /**
   * Validates invite role IDs against the membership target group type.
   *
   * @param array<string, mixed> $invite
   *   Normalized invite data.
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Group receiving the invitee membership.
   *
   * @return string[]
   *   Valid group role IDs to grant.
   */
  private function validatedGrantRoles(array $invite, GroupInterface $target): array {
    $roles = $invite['roles'] ?? [];
    if (!is_array($roles)) {
      throw new \UnexpectedValueException('Invite roles are malformed.');
    }

    $role_ids = array_values(array_unique(array_filter(
      $roles,
      static fn(mixed $role): bool => is_string($role) && trim($role) !== '',
    )));
    if ($role_ids === []) {
      throw new \UnexpectedValueException('Invite must grant at least one role.');
    }

    $grantable = $this->configFactory->get('beantragt_group.settings')->get('grantable_roles');
    $grantable_role_ids = is_array($grantable) ? array_values(array_filter($grantable, 'is_string')) : [];
    $role_storage = $this->entityTypeManager->getStorage('group_role');
    foreach ($role_ids as $role_id) {
      if (!in_array($role_id, $grantable_role_ids, TRUE)) {
        throw new \UnexpectedValueException(sprintf('Invite role "%s" is not grantable through workspace invites.', $role_id));
      }
      $role = $role_storage->load($role_id);
      if (!$role instanceof GroupRoleInterface) {
        throw new \UnexpectedValueException(sprintf('Invite role "%s" does not exist.', $role_id));
      }
      if ($role->getGroupTypeId() !== $target->bundle()) {
        throw new \UnexpectedValueException(sprintf('Invite role "%s" does not belong to group type "%s".', $role_id, $target->bundle()));
      }
    }

    return $role_ids;
  }

  /**
   * Checks whether a target group belongs to the target parent jurisdiction.
   */
  private function targetGroupWithinParent(GroupInterface $target, GroupInterface $target_parent): bool {
    $scope_id = (int) $target_parent->id();
    if ($scope_id <= 0) {
      return FALSE;
    }

    $candidate_jurisdiction_id = NULL;
    if ($target->bundle() === $this->jurisdictionGroupType()) {
      $candidate_jurisdiction_id = (int) $target->id();
    }
    elseif ($target->bundle() === $this->organisationGroupType()) {
      if (!$target->hasField('field_jurisdiction') || $target->get('field_jurisdiction')->isEmpty()) {
        return FALSE;
      }
      $candidate_jurisdiction_id = (int) $target->get('field_jurisdiction')->target_id;
    }
    else {
      return FALSE;
    }

    return $candidate_jurisdiction_id > 0
      && $this->hierarchyResolver->isSameOrDescendantById($candidate_jurisdiction_id, $scope_id);
  }

  /**
   * Checks whether the authenticated claimant owns the invitee email.
   *
   * @param array<string, mixed> $invite
   *   Normalized invite data.
   * @param \Drupal\user\UserInterface $claimant
   *   User claiming the invitation.
   */
  private function inviteEmailMatchesClaimant(array $invite, UserInterface $claimant): bool {
    $invite_email = strtolower(trim((string) ($invite['email'] ?? '')));
    $claimant_email = $this->verifiedMail?->getVerifiedEmail($claimant) ?? '';

    return $invite_email !== '' && $claimant_email !== '' && $invite_email === $claimant_email;
  }

  /**
   * Grants the invite roles on the target group to the claiming user.
   *
   * @param \Drupal\user\UserInterface $claimant
   *   User claiming the invitation.
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Group receiving the invitee membership.
   * @param string[] $role_ids
   *   Validated group role IDs.
   */
  private function grantInviteRoles(UserInterface $claimant, GroupInterface $target, array $role_ids): void {
    if ($role_ids === []) {
      return;
    }

    $membership = GroupMembershipEntity::loadSingle($target, $claimant);
    if (!$membership) {
      $target->addMember($claimant, ['group_roles' => $role_ids]);
      return;
    }

    $existing_roles = array_keys($membership->getRoles(FALSE));
    foreach ($role_ids as $role_id) {
      if (!in_array($role_id, $existing_roles, TRUE)) {
        $membership->addRole($role_id);
      }
    }
  }

  /**
   * Sends the invite mail through Drupal mail plugins.
   *
   * @param array<string, mixed> $invite
   *   Invite data returned by the token issuer.
   * @param \Drupal\group\Entity\GroupInterface $target_parent
   *   Target parent jurisdiction.
   * @param string $frontend_base_url
   *   Validated frontend base URL.
   */
  private function sendInvitationMail(array $invite, GroupInterface $target_parent, string $frontend_base_url): bool {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $platform_name = $target_parent->hasField('field_platform_name') && !$target_parent->get('field_platform_name')->isEmpty()
      ? trim((string) $target_parent->get('field_platform_name')->value)
      : '';
    $site_name = $platform_name !== '' && strcasecmp($platform_name, 'beantragt.ai') !== 0
      ? $platform_name
      : (trim((string) $target_parent->label()) ?: (string) ($this->configFactory->get('system.site')->get('name') ?: 'beantragt.ai'));
    $claim_url = $this->buildClaimUrl((string) $invite['token'], $frontend_base_url);

    $result = $this->mailManager->mail(
      'beantragt_group',
      'workspace_invitation',
      (string) $invite['email'],
      $langcode,
      [
        'site_name' => $site_name,
        'target_parent' => $target_parent->label(),
        'claim_url' => $claim_url,
        'expires_in' => (string) ((int) (($invite['expires'] - $invite['created']) / 86400)),
        'langcode' => $langcode,
        'jurisdiction_id' => $target_parent->id(),
      ],
      NULL,
      TRUE,
    );

    $sent = !empty($result['result']);
    if (!$sent) {
      $this->logger->error('Failed to send workspace invitation email for invite @id.', [
        '@id' => (string) ($invite['id'] ?? 'unknown'),
      ]);
    }

    return $sent;
  }

  /**
   * Builds the frontend invite acceptance URL.
   */
  private function buildClaimUrl(string $token, string $frontend_base_url): string {
    $settings = $this->configFactory->get('beantragt_group.settings');
    $claim_path = trim((string) ($settings->get('workspace_invite_claim_path') ?? '/workspace/invite'));
    if ($claim_path === '') {
      $claim_path = '/workspace/invite';
    }
    if (!str_starts_with($claim_path, '/')) {
      $claim_path = '/' . $claim_path;
    }

    return $frontend_base_url . $claim_path . '?token=' . rawurlencode($token);
  }

  /**
   * Resolves the validated frontend base URL for invite links.
   */
  private function resolveFrontendBaseUrl(): ?string {
    $configured = trim((string) ($this->configFactory->get('beantragt_group.settings')->get('frontend_base_url') ?? ''));
    if ($configured === '' || filter_var($configured, FILTER_VALIDATE_URL) === FALSE) {
      return NULL;
    }

    $parts = parse_url($configured);
    if (!is_array($parts)
      || trim((string) ($parts['host'] ?? '')) === ''
      || isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])) {
      return NULL;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower($parts['host']);
    $loopback_http = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], TRUE);
    if ($scheme !== 'https' && !$loopback_http) {
      return NULL;
    }

    return rtrim($configured, '/');
  }

  /**
   * Strips private fields from authenticated management API output.
   *
   * @param array<string, mixed> $invite
   *   Invite data returned by the token issuer.
   *
   * @return array<string, mixed>
   *   Public response data for authenticated invite-management endpoints.
   */
  private function publicInviteResponse(array $invite): array {
    unset($invite['token'], $invite['payload'], $invite['claimed']);
    $target_parent = $this->loadGroup((int) ($invite['target_parent_id'] ?? 0));
    if ($target_parent instanceof GroupInterface) {
      $invite['target_parent_label'] = $target_parent->label();
    }

    return $invite;
  }

  /**
   * Strips internal invite fields from the authenticated claim response.
   *
   * @param array<string, mixed> $invite
   *   Invite data returned by the token issuer.
   *
   * @return array<string, mixed>
   *   Claim response data for the matched invitee.
   */
  private function publicClaimResponse(array $invite): array {
    $response = [
      'id' => (int) ($invite['id'] ?? 0),
      'purpose' => (string) ($invite['purpose'] ?? ''),
      'email' => (string) ($invite['email'] ?? ''),
      'target_parent_id' => (int) ($invite['target_parent_id'] ?? 0),
      'expires' => (int) ($invite['expires'] ?? 0),
    ];

    $target_parent = $this->loadGroup($response['target_parent_id']);
    if ($target_parent instanceof GroupInterface) {
      $response['target_parent_label'] = $target_parent->label();
    }

    return $response;
  }

  /**
   * Builds a minimal, non-PII response for the anonymous verify endpoint.
   *
   * @param array<string, mixed> $invite
   *   Invite data returned by the token issuer.
   *
   * @return array<string, mixed>
   *   Response data without email, issuer, scope, or role information.
   */
  private function publicVerifyResponse(array $invite): array {
    $response = [
      'id' => (int) ($invite['id'] ?? 0),
      'purpose' => (string) ($invite['purpose'] ?? ''),
      'target_parent_id' => (int) ($invite['target_parent_id'] ?? 0),
      'expires' => (int) ($invite['expires'] ?? 0),
    ];

    $target_parent = $this->loadGroup($response['target_parent_id']);
    if ($target_parent instanceof GroupInterface) {
      $response['target_parent_label'] = $target_parent->label();
    }

    return $response;
  }

  /**
   * Converts a token service status payload to an authenticated JSON response.
   *
   * Used for the claim endpoint. Detailed states are acceptable here because
   * the caller is authenticated and matched against the invite email.
   *
   * @param array<string, mixed> $status
   *   Token service status payload.
   */
  private function tokenStatusResponse(array $status): JsonResponse {
    $state = (string) ($status['status'] ?? 'missing');

    if ($state === 'expired') {
      return $this->jsonError('This invitation has expired.', Response::HTTP_GONE);
    }

    if (in_array($state, ['missing', 'already_claimed', 'claimed'], TRUE)
      && !is_array($status['invite'] ?? NULL)) {
      return $this->jsonError('Invalid or already claimed invitation.', Response::HTTP_NOT_FOUND);
    }

    if ($state === 'already_claimed') {
      return $this->jsonError('This invitation has already been claimed.', Response::HTTP_CONFLICT);
    }

    if (!is_array($status['invite'] ?? NULL)) {
      return $this->jsonError('Invitation not found.', Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse([
      'status' => $state,
      'invite' => $this->publicClaimResponse($status['invite']),
    ]);
  }

  /**
   * Rolls back an open claim transaction.
   */
  private function rollBack(?Transaction $transaction): void {
    if ($transaction instanceof Transaction) {
      $transaction->rollBack();
    }
  }

  /**
   * Builds a JSON error response.
   */
  private function jsonError(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
