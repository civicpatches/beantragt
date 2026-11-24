<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Functional;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Serialization\Json;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests workflow-status tenant access through the JSON:API resource.
 */
#[Group('beantragt_group')]
#[Group('beantragt_workflow_status')]
final class WorkflowStatusJsonApiCrossJurisdictionAccessTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
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
  protected $defaultTheme = 'stark';

  /**
   * Workflow statuses are only readable inside their owning organisation.
   */
  public function testWorkflowStatusJsonApiRequiresEffectiveScopeMembership(): void {
    $jurisdiction_a = $this->createJurisdiction('workflow-jsonapi-jur-a', 'Workflow JSON:API Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('workflow-jsonapi-jur-b', 'Workflow JSON:API Jurisdiction B');
    $organisation_a = $this->createOrganisation('workflow-jsonapi-org-a', 'Workflow JSON:API Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('workflow-jsonapi-org-b', 'Workflow JSON:API Organisation B', $jurisdiction_b);

    $status_a = $this->createWorkflowStatus($organisation_a, 'eingegangen');
    $status_b = $this->createWorkflowStatus($organisation_b, 'eingegangen');
    $jsonapi_path = '/jsonapi/workflow_status/workflow_status/' . $status_a->uuid();

    $org_a_viewer = $this->createStatusReader();
    $organisation_a->addMember($org_a_viewer, ['group_roles' => ['org-member']]);

    $org_b_viewer = $this->createStatusReader();
    $organisation_b->addMember($org_b_viewer, ['group_roles' => ['org-member']]);

    $this->drupalLogin($org_a_viewer);
    $allowed_response = Json::decode($this->drupalGet($jsonapi_path));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('workflow_status--workflow_status', $allowed_response['data']['type']);
    $this->assertSame($status_a->uuid(), $allowed_response['data']['id']);

    $own_collection = $this->loadWorkflowStatusCollectionByInternalId($status_a);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $own_collection['data']);
    $this->assertSame($status_a->uuid(), $own_collection['data'][0]['id']);

    $foreign_collection = $this->loadWorkflowStatusCollectionByInternalId($status_b);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $foreign_collection['data']);
    $this->drupalLogout();

    $this->drupalLogin($org_b_viewer);
    $denied_response = Json::decode($this->drupalGet($jsonapi_path));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame('403', $denied_response['errors'][0]['status']);
    $this->assertSame('/data', $denied_response['errors'][0]['source']['pointer']);
  }

  /**
   * Loads the workflow status collection filtered by Drupal's internal ID.
   *
   * @return array<string, mixed>
   *   Decoded JSON:API response body.
   */
  private function loadWorkflowStatusCollectionByInternalId(WorkflowStatusInterface $status): array {
    return Json::decode($this->drupalGet('/jsonapi/workflow_status/workflow_status', [
      'query' => [
        'filter' => [
          'drupal_internal__id' => $status->id(),
        ],
      ],
    ]));
  }

  /**
   * Creates a user that may read workflow definitions directly.
   */
  private function createStatusReader(): UserInterface {
    $account = $this->createUser([
      'view published application entities',
    ]);
    // Staff pass the JSON:API read gate by role.
    if (!Role::load('case_worker')) {
      Role::create(['id' => 'case_worker', 'label' => 'case_worker'])->save();
    }
    assert($account instanceof UserInterface);
    $account->addRole('case_worker');
    $account->save();
    return $account;
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
  private function createWorkflowStatus(GroupInterface $organisation, string $machineName): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => $machineName,
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

}
