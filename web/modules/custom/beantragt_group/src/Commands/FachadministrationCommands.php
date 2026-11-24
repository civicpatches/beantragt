<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Commands;

use Drupal\beantragt_group\Service\FachadministrationGrant;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Operator command: grant a Fachadministration by name.
 *
 * Takes an address and either a municipality, or a municipality plus one of
 * its offices, each by slug. Resolves every name to exactly one entity or
 * stops; creates no account and no group; removes nothing.
 */
final class FachadministrationCommands extends DrushCommands {

  /**
   * The actor name this command writes into the grant log.
   */
  private const ACTOR = 'drush beantragt:fachadmin:grant';

  public function __construct(
    private readonly FachadministrationGrant $grant,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Grants the Fachadministration of one municipality or one office.
   *
   * Municipality only: full role (jur-admin, every office of it). With an
   * office: limited role (org-admin in that office only). Adds the site role
   * department_admin if missing; because that role is account-wide, the grant
   * is then refused while the account holds memberships outside the target,
   * unless an option names the widening. Prints what the account reaches
   * afterwards.
   *
   * @param string $mail
   *   The address of an existing account.
   * @param string $municipality
   *   Slug of the municipality.
   * @param string|null $office
   *   Optional slug of an office of that municipality. Given but empty is
   *   refused.
   * @param array $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   *
   * @command beantragt:fachadmin:grant
   * @option additional-municipality Accept reach in a municipality other than the target's.
   * @option with-existing-offices Office grant only: accept that the account's memberships in other groups of the same municipality become Fachadministration reach when the site role is added.
   * @usage drush beantragt:fachadmin:grant person@example.org sample-town
   *   Full Fachadministration of the municipality with the slug sample-town.
   * @usage drush beantragt:fachadmin:grant person@example.org sample-town citizen-office
   *   Limited Fachadministration of one office of that municipality.
   */
  public function grant(
    string $mail,
    string $municipality,
    ?string $office = NULL,
    array $options = [
      'additional-municipality' => FALSE,
      'with-existing-offices' => FALSE,
    ],
  ): int {
    $allow_additional = (bool) ($options['additional-municipality'] ?? FALSE);
    $with_existing_offices = (bool) ($options['with-existing-offices'] ?? FALSE);
    try {
      if ($office !== NULL && trim($office) === '') {
        throw new \InvalidArgumentException('The office argument is given but empty; leave it out for a municipality grant.');
      }
      if ($office === NULL && $with_existing_offices) {
        throw new \InvalidArgumentException('The with-existing-offices option applies to an office grant only.');
      }
      $account = $this->loadAccount($mail);
      $jurisdiction = $this->loadOne(['type' => 'jur', 'field_slug' => trim($municipality)], 'municipality', $municipality);
      if ($office === NULL) {
        $changes = $this->grant->grantMunicipality($account, $jurisdiction, self::ACTOR, $allow_additional);
      }
      else {
        $organisation = $this->loadOne([
          'type' => 'org',
          'field_slug' => trim($office),
          'field_jurisdiction' => $jurisdiction->id(),
        ], 'office of that municipality', $office);
        $changes = $this->grant->grantOffice($account, $organisation, self::ACTOR, $allow_additional, $with_existing_offices);
      }
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->writeError('Refused, nothing changed: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->output()->writeln($changes === []
      ? 'Nothing changed: the account already held this grant.'
      : 'Granted: ' . implode(', ', $changes) . '.');
    $this->printReach($account);
    return self::EXIT_SUCCESS;
  }

  /**
   * Loads exactly one account by address.
   */
  private function loadAccount(string $mail): UserInterface {
    $mail = trim($mail);
    if ($mail === '') {
      throw new \InvalidArgumentException('An address is required.');
    }
    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail]);
    if (count($accounts) !== 1) {
      throw new \InvalidArgumentException(sprintf('No single account has the address %s (%d found).', $mail, count($accounts)));
    }
    return reset($accounts);
  }

  /**
   * Loads exactly one group by properties.
   *
   * @param array<string, mixed> $properties
   *   The group properties, including the type.
   * @param string $what
   *   What the group is, for the message.
   * @param string $slug
   *   The slug as given, for the message.
   */
  private function loadOne(array $properties, string $what, string $slug): GroupInterface {
    if (trim($slug) === '') {
      throw new \InvalidArgumentException(sprintf('A slug for the %s is required.', $what));
    }
    $groups = $this->entityTypeManager->getStorage('group')->loadByProperties($properties);
    if (count($groups) !== 1) {
      throw new \InvalidArgumentException(sprintf('No single %s has the slug %s (%d found).', $what, trim($slug), count($groups)));
    }
    $group = reset($groups);
    assert($group instanceof GroupInterface);
    return $group;
  }

  /**
   * Prints, read back from storage, status, site roles and reach.
   */
  private function printReach(UserInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('user');
    $storage->resetCache([$account->id()]);
    $stored = $storage->load($account->id());
    $account = $stored instanceof UserInterface ? $stored : $account;

    $output = $this->output();
    $output->writeln(sprintf('Account %s (uid %d), %s, site roles: %s',
      $account->getAccountName(),
      (int) $account->id(),
      $account->isActive() ? 'active' : 'BLOCKED',
      implode(', ', $account->getRoles(TRUE)),
    ));
    $slugs = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $group = $membership->getGroup();
      $slugs[] = sprintf('%s [%s]', (string) $group->label(), $group->hasField('field_slug') ? (string) $group->get('field_slug')->value : '');
    }
    sort($slugs);
    $reach = $this->grant->describeReach($account);
    $output->writeln($reach === []
      ? 'Reaches as Fachadministration: nothing'
      : array_merge(['Reaches as Fachadministration:'], array_map(static fn (string $line): string => '  ' . $line, $reach)));
    if ($slugs !== []) {
      $output->writeln('Slugs: ' . implode(', ', $slugs));
    }
  }

  /**
   * Writes a message to the error output.
   */
  private function writeError(string $message): void {
    $output = $this->output();
    ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($message);
  }

}
