<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\UserInterface;

/**
 * Tests tenant access against real workflow status entities.
 *
 * @group beantragt_group
 * @group beantragt_workflow_status
 */
final class WorkflowStatusCrossJurisdictionAccessTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
  }

  /**
   * Workflow status access stays scoped to the owning organisation.
   */
  public function testWorkflowStatusAccessRequiresEffectiveScopeMembership(): void {
    $jurisdiction_a = $this->createJurisdiction('jur-a', 'Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('jur-b', 'Jurisdiction B');
    $organisation_a = $this->createOrganisation('org-a', 'Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('org-b', 'Organisation B', $jurisdiction_b);
    $status_a = $this->createWorkflowStatus($organisation_a, 'eingegangen');

    $org_a_viewer = $this->createStatusReader();
    $organisation_a->addMember($org_a_viewer, ['group_roles' => ['org-member']]);

    $org_a_configurator = $this->createStatusAdministrator();
    $organisation_a->addMember($org_a_configurator, ['group_roles' => ['org-member']]);

    $org_a_admin = $this->createStatusAdministrator();
    $organisation_a->addMember($org_a_admin, ['group_roles' => ['org-admin']]);

    $jur_a_admin = $this->createStatusAdministrator();
    $jurisdiction_a->addMember($jur_a_admin, ['group_roles' => ['jur-admin']]);

    $org_b_viewer = $this->createStatusReader();
    $organisation_b->addMember($org_b_viewer, ['group_roles' => ['org-member']]);

    $org_b_admin = $this->createStatusAdministrator();
    $organisation_b->addMember($org_b_admin, ['group_roles' => ['org-admin']]);

    $this->assertTrue($status_a->access('view', $org_a_viewer), 'An organisation member can view workflow statuses in its own organisation.');
    $this->assertFalse($status_a->access('update', $org_a_viewer), 'A reader cannot update workflow statuses.');
    $this->assertFalse($status_a->access('update', $org_a_configurator), 'A non-admin organisation member cannot update workflow statuses even with global workflow-status permission.');
    $this->assertTrue($status_a->access('update', $org_a_admin), 'An organisation admin can update workflow statuses in its own organisation.');
    $this->assertTrue($status_a->access('update', $jur_a_admin), 'A jurisdiction admin can update workflow statuses in descendant organisations.');
    $this->assertFalse($status_a->access('view', $org_b_viewer), 'A different organisation member cannot view workflow statuses across jurisdictions.');
    $this->assertFalse($status_a->access('update', $org_b_admin), 'A different organisation admin cannot update workflow statuses across jurisdictions.');
  }

  /**
   * Jurisdiction seeding repairs partial catalogues, keeps custom statuses.
   */
  public function testJurisdictionSeederRepairsPartialCatalogue(): void {
    $jurisdiction = $this->createJurisdiction('partial-jur', 'Partial Jurisdiction');
    \Drupal::database()->update('workflow_status')
      ->fields(['field_is_default' => 0])
      ->condition('field_group', $jurisdiction->id())
      ->execute();
    $this->entityTypeManager()->getStorage('workflow_status')->resetCache();

    /** @var \Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder $seeder */
    $seeder = $this->container->get('beantragt_workflow_status.status_seeder');
    $statuses = $seeder->seedForGroup($jurisdiction);

    $this->assertArrayHasKey('eingegangen', $statuses);
    $this->assertTrue($statuses['eingegangen']->isDefault());

    $default_ids = $this->entityTypeManager()->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $jurisdiction->id())
      ->condition('field_is_default', TRUE)
      ->execute();
    $this->assertCount(1, $default_ids);
  }

  /**
   * Existing custom catalogues with a default status are left untouched.
   */
  public function testJurisdictionSeederKeepsCustomCatalogueWithDefault(): void {
    $jurisdiction = $this->createJurisdiction('custom-jur', 'Custom Jurisdiction');
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $before_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $jurisdiction->id())
      ->execute();

    /** @var \Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder $seeder */
    $seeder = $this->container->get('beantragt_workflow_status.status_seeder');
    $statuses = $seeder->seedForGroup($jurisdiction);
    $after_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $jurisdiction->id())
      ->execute();
    sort($before_ids);
    sort($after_ids);

    $this->assertSame($before_ids, $after_ids);
    $this->assertSameSize($before_ids, $statuses);
  }

  /**
   * Creates a user that may read workflow definitions directly.
   */
  private function createStatusReader(): UserInterface {
    return $this->createUser([
      'view published application entities',
    ]);
  }

  /**
   * Creates a user with global workflow-status write permission.
   */
  private function createStatusAdministrator(): UserInterface {
    return $this->createUser([
      'administer workflow_status entities',
      'view published application entities',
    ]);
  }

  /**
   * Creates a jurisdiction group.
   */
  private function createJurisdiction(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Creates an organisation group owned by one jurisdiction.
   */
  private function createOrganisation(string $slug, string $label, GroupInterface $jurisdiction): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
  }

  /**
   * Creates a workflow status owned by an organisation.
   */
  private function createWorkflowStatus(GroupInterface $organisation, string $machineName, bool $isDefault = TRUE): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => $machineName,
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => $isDefault,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

}
