<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves read access to confirmed anonymous submissions by mailbox proof.
 */
class ConfirmedApplicationMailbox {

  private const REQUEST_CACHE = 'beantragt_application.confirmed_mailbox';

  /**
   * Constructs the mailbox lookup.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly RequestStack $requestStack,
    private readonly ProtectedAccessLog $auditLog,
    private readonly ?VerifiedAccountMail $verifiedMail = NULL,
  ) {}

  /**
   * Finds application candidates by the mailbox on the confirmed revision.
   *
   * Confirmation mirrors the flag into the then-current revision. Staff data
   * edits can change the contact address in later revisions and must not move
   * the citizen's access. The current row must still carry double opt-in proof.
   *
   * @return list<int>
   *   Matching IDs. Call allowsRead() or check entity access before disclosure.
   */
  public function matchingApplicationIds(AccountInterface $account, ?string $application_uuid = NULL): array {
    if (!$account->isAuthenticated() || BeantragtRoles::isServiceAccount($account)) {
      return [];
    }
    $email = $this->verifiedMail?->getVerifiedEmail($account);
    if ($email === NULL) {
      return [];
    }

    $request = $this->requestStack->getCurrentRequest();
    $cache = $request?->isMethodSafe() ? $request->attributes : NULL;
    $matches = $cache?->get(self::REQUEST_CACHE, []) ?? [];
    $scope = strtolower($application_uuid ?? '');
    if (isset($matches[$email][''])) {
      $all = $matches[$email][''];
      return $scope === '' ? array_values($all) : array_values(array_intersect_key($all, [$scope => TRUE]));
    }
    if (isset($matches[$email][$scope])) {
      return array_values($matches[$email][$scope]);
    }

    try {
      $email_hash = $this->dataProtection->applicantEmailLookupHash($email);
    }
    catch (\RuntimeException) {
      $email_hash = NULL;
    }

    $query = $this->database->select('application_revision', 'confirmed');
    $query->innerJoin('application', 'current_app', 'current_app.id = confirmed.id');
    $query->addField('current_app', 'uuid');
    $earlier = $this->database->select('application_revision', 'earlier');
    $earlier->addExpression('1');
    $earlier->where('earlier.id = confirmed.id AND earlier.revision_id < confirmed.revision_id');
    $earlier->condition('earlier.field_email_confirmed', 1);
    $email_match = $query->orConditionGroup()->condition('confirmed.field_applicant_email', $email);
    if ($email_hash !== NULL) {
      $email_match->condition('confirmed.field_applicant_email_hash', $email_hash);
    }
    $query->fields('confirmed', ['id', 'field_applicant_email', 'field_applicant_email_hash'])
      ->condition('current_app.field_email_confirmed', 1)
      ->condition('current_app.field_email_confirmed_time', 0, '>')
      ->condition('current_app.field_email_confirmation_token_hash', '', '<>')
      ->condition('confirmed.field_email_confirmed', 1)
      ->condition($email_match)
      ->notExists($earlier);
    if ($application_uuid !== NULL) {
      $query->condition('current_app.uuid', $application_uuid);
    }

    $ids = [];
    foreach ($query->execute() as $match) {
      $stored_hash = (string) $match->field_applicant_email_hash;
      // SQL collation is only a prefilter. Compare the actual address or hash.
      $email_matches = $stored_hash !== ''
        ? $email_hash !== NULL && hash_equals($stored_hash, $email_hash)
        : hash_equals(strtolower(trim((string) $match->field_applicant_email)), $email);
      if ($email_matches) {
        $ids[strtolower($match->uuid)] = (int) $match->id;
      }
    }
    if ($cache !== NULL) {
      $matches[$email][$scope] = $ids;
      $cache->set(self::REQUEST_CACHE, $matches);
    }
    return array_values($ids);
  }

  /**
   * Checks that a candidate is an anonymous submission of an internet form.
   */
  public function allowsRead(ApplicationInterface $application, AccountInterface $account): bool {
    // Owner and form checks prevent address matching on regular UID-owned
    // submissions, staff intakes and applications from non-public forms.
    /** @var \Drupal\user\UserInterface|null $owner */
    $owner = $application->getOwner();
    return $application->hasField('field_public_status_token_hash')
      && !$application->get('field_public_status_token_hash')->isEmpty()
      && $owner !== NULL
      && BeantragtRoles::isServiceAccount($owner)
      && PublicSubmission::isPublicInternetSchema(PublicSubmission::submittedSchema($application, $this->entityTypeManager))
      && in_array((int) $application->id(), $this->matchingApplicationIds($account, $application->uuid()), TRUE);
  }

  /**
   * Audits an authorized read of a mailbox-associated application.
   */
  public function auditRead(ApplicationInterface $application, AccountInterface $account): void {
    if ((int) $application->getOwnerId() !== (int) $account->id()) {
      $this->auditLog->recordSubject('application_mailbox', $application->uuid(), $account, 'verified_mailbox_read');
    }
  }

}
