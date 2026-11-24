<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Functional;

use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests admin import route and local-action access.
 */
#[Group('beantragt_schema')]
final class FormSchemaImportAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'block',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_roles_api',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * The route follows form schema entity-create access.
   */
  public function testImportRouteUsesEntityCreateAccess(): void {
    $without_create = $this->drupalCreateUser(['access administration pages']);
    $this->drupalLogin($without_create);
    $this->drupalGet('/admin/content/form-schemas/import');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $with_create = $this->drupalCreateUser(['create form_schema entities']);
    $this->drupalLogin($with_create);
    $this->drupalGet('/admin/content/form-schemas/import');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('schema_json');
    $this->assertSession()->pageTextContains('No manageable jurisdiction workspace is available for your account.');
    $submit = $this->assertSession()->buttonExists('Validate and import');
    self::assertTrue($submit->hasAttribute('disabled'));
    $this->drupalLogout();

    $administrator = $this->drupalCreateUser(['administer form_schema entities']);
    $this->drupalLogin($administrator);
    $this->drupalGet('/admin/content/form-schemas');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Import schema JSON');
    $this->drupalGet('/admin/content/form-schemas/import');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $admin_with_create = $this->drupalCreateUser(['administer form_schema entities', 'create form_schema entities']);
    $this->drupalLogin($admin_with_create);
    $this->drupalGet('/admin/content/form-schemas');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Import schema JSON');
  }

  /**
   * The entity add form exposes only manageable workspace metadata.
   */
  public function testEntityAddFormUsesScopedWorkspaceSelects(): void {
    $group_storage = $this->container->get('entity_type.manager')->getStorage('group');
    $jurisdiction = $group_storage->create([
      'type' => 'jur',
      'label' => 'US-SMOKE Visible jurisdiction',
      'field_slug' => 'us-smoke-visible-jurisdiction',
    ]);
    $jurisdiction->save();
    assert($jurisdiction instanceof GroupInterface);
    $organisation = $group_storage->create([
      'type' => 'org',
      'label' => 'US-SMOKE Visible organisation',
      'field_slug' => 'us-smoke-visible-organisation',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $organisation->save();
    $foreign_jurisdiction = $group_storage->create([
      'type' => 'jur',
      'label' => 'US-SMOKE Hidden jurisdiction',
      'field_slug' => 'us-smoke-hidden-jurisdiction',
    ]);
    $foreign_jurisdiction->save();
    $foreign_organisation = $group_storage->create([
      'type' => 'org',
      'label' => 'US-SMOKE Hidden organisation',
      'field_slug' => 'us-smoke-hidden-organisation',
      'field_jurisdiction' => ['target_id' => $foreign_jurisdiction->id()],
    ]);
    $foreign_organisation->save();

    $account = $this->drupalCreateUser(['create form_schema entities']);
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/content/form-schemas/add');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->selectExists('field_jurisdiction[0][target_id]');
    $this->assertSession()->selectExists('field_organisation[0][target_id]');
    $this->assertSession()->selectExists('field_group[0][target_id]');
    $this->assertSession()->pageTextContains('US-SMOKE Visible jurisdiction');
    $this->assertSession()->pageTextContains('US-SMOKE Visible organisation');
    $this->assertSession()->pageTextNotContains('US-SMOKE Hidden jurisdiction');
    $this->assertSession()->pageTextNotContains('US-SMOKE Hidden organisation');
  }

}
