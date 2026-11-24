<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow_status\Kernel;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Citizen texts, the hand-over and filing statuses and their updates.
 */
#[Group('beantragt_workflow_status')]
final class CitizenLabelKernelTest extends GroupKernelTestBase {

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
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    require_once dirname(__DIR__, 3) . '/beantragt_workflow_status.install';
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
  }

  /**
   * German falls back to the label, English to German, then to the label.
   */
  public function testCitizenLabelFallbacks(): void {
    $status = $this->newStatus('Weiterleitung an Fachverfahren');
    $this->assertSame('Weiterleitung an Fachverfahren', $status->getCitizenLabel('de'));
    $this->assertSame('Weiterleitung an Fachverfahren', $status->getCitizenLabel('en'));
    $this->assertFalse($status->hasCitizenLabelOverride());

    $status->set('field_citizen_label_de', 'In Bearbeitung');
    $this->assertSame('In Bearbeitung', $status->getCitizenLabel('de'));
    $this->assertSame('In Bearbeitung', $status->getCitizenLabel('en'));
    $this->assertSame('In Bearbeitung', $status->getCitizenLabel('fr'));
    $this->assertTrue($status->hasCitizenLabelOverride());

    $status->set('field_citizen_label_en', 'In progress');
    $this->assertSame('In progress', $status->getCitizenLabel('en'));
    $this->assertSame('In Bearbeitung', $status->getCitizenLabel('de'));

    // Only English set: German readers see the label.
    $status->set('field_citizen_label_de', '');
    $this->assertSame('Weiterleitung an Fachverfahren', $status->getCitizenLabel('de'));
    $this->assertSame('In progress', $status->getCitizenLabel('en'));

    // A citizen text equal to the label is no override.
    $same = $this->newStatus('Eingegangen');
    $same->set('field_citizen_label_de', 'Eingegangen');
    $this->assertFalse($same->hasCitizenLabelOverride());
  }

  /**
   * Citizen texts are short plain text; saving trims them.
   */
  public function testCitizenLabelConstraintAndTrim(): void {
    foreach ([
      str_repeat('x', 121),
      '<b>In Bearbeitung</b>',
      "In\x07Bearbeitung",
      "Zeile\nzwei",
      'a > b',
    ] as $invalid) {
      $status = $this->newStatus('Fachprüfung');
      $status->set('field_citizen_label_en', $invalid);
      $violations = $status->validate();
      $paths = [];
      foreach ($violations as $violation) {
        $paths[] = $violation->getPropertyPath();
      }
      $this->assertContains('field_citizen_label_en', $paths, $invalid);
    }
    $status = $this->newStatus('Fachprüfung');
    $status->set('field_citizen_label_de', '  ' . str_repeat('ä', 120) . '  ');
    $status->set('field_citizen_label_en', '');
    $paths = [];
    foreach ($status->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertNotContains('field_citizen_label_de', $paths);
    $this->assertNotContains('field_citizen_label_en', $paths);
    $status->set('field_citizen_label_de', '  In Bearbeitung ');
    $status->save();
    $stored = $this->entityTypeManager()->getStorage('workflow_status')->loadUnchanged($status->id());
    $this->assertSame('In Bearbeitung', $stored->get('field_citizen_label_de')->value);
  }

  /**
   * A new catalogue carries hand-over and filing statuses with citizen texts.
   */
  public function testSeededCatalogueCarriesHandOverAndFiling(): void {
    $group = $this->createGroup(['type' => 'jur', 'label' => 'Seed jurisdiction', 'field_slug' => 'seed-jur']);
    $seeder = $this->container->get('beantragt_workflow_status.status_seeder');
    $statuses = $seeder->seedForGroup($group);
    $hand_over = $statuses['fachverfahren_weiterleitung'];
    $this->assertSame(['hand_over'], $hand_over->getSemanticFlags());
    $this->assertSame('In Bearbeitung', $hand_over->getCitizenLabel('de'));
    $this->assertSame('In progress', $hand_over->getCitizenLabel('en'));
    $this->assertEqualsCanonicalizing(
      ['fachverfahren_uebergeben', 'nachforderung_offen', 'in_bearbeitung', 'abgeschlossen', 'zurueckgezogen'],
      $hand_over->getAllowedNextMachineNames(),
    );
    $filed = $statuses['im_fachverfahren_abgelegt'];
    $this->assertEqualsCanonicalizing(['filed', 'closed'], $filed->getSemanticFlags());
    $this->assertTrue($filed->isTerminal());
    $this->assertSame('Abgeschlossen', $filed->getCitizenLabel('de'));
    $this->assertSame('Completed', $filed->getCitizenLabel('en'));
    $this->assertContains('im_fachverfahren_abgelegt', $statuses['fachverfahren_uebergeben']->getAllowedNextMachineNames());
    $this->assertContains('genehmigt', $statuses['fachverfahren_uebergeben']->getAllowedNextMachineNames());
    // A library status: no standard status leads to it.
    $this->assertNotContains('fachverfahren_weiterleitung', $statuses['eingegangen']->getAllowedNextMachineNames());
    $this->assertNotContains('fachverfahren_weiterleitung', $statuses['in_bearbeitung']->getAllowedNextMachineNames());
    $this->assertSame('Bewilligt', $statuses['genehmigt']->getCitizenLabel('de'));
    $this->assertSame('Eingegangen', $statuses['eingegangen']->getCitizenLabel('de'));
    $this->assertSame('Received', $statuses['eingegangen']->getCitizenLabel('en'));
    // Idempotent: nothing to add to a complete catalogue.
    $this->assertSame([], $seeder->addMissingSystemStatuses((int) $group->id()));
  }

  /**
   * Updates install the fields, add the statuses and fill unchanged texts.
   */
  public function testUpdatesInstallFieldsAddStatusesAndBackfill(): void {
    $update_manager = $this->container->get('entity.definition_update_manager');
    foreach (['field_citizen_label_de', 'field_citizen_label_en'] as $field_name) {
      $update_manager->uninstallFieldStorageDefinition($update_manager->getFieldStorageDefinition($field_name, 'workflow_status'));
    }
    $this->assertNull($update_manager->getFieldStorageDefinition('field_citizen_label_de', 'workflow_status'));
    beantragt_workflow_status_update_10007();
    beantragt_workflow_status_update_10007();
    $this->assertNotNull($update_manager->getFieldStorageDefinition('field_citizen_label_de', 'workflow_status'));
    $this->assertNotNull($update_manager->getFieldStorageDefinition('field_citizen_label_en', 'workflow_status'));
    $this->assertArrayNotHasKey('workflow_status', $update_manager->getChangeSummary());

    // A catalogue from before: no hand-over and no filing status, no texts.
    $group = $this->createGroup(['type' => 'jur', 'label' => 'Old jurisdiction', 'field_slug' => 'old-jur']);
    $statuses = $this->container->get('beantragt_workflow_status.status_seeder')->seedForGroup($group);
    foreach (['fachverfahren_weiterleitung' => 'alt_weiterleitung', 'im_fachverfahren_abgelegt' => 'alt_abgelegt'] as $machine_name => $renamed) {
      $statuses[$machine_name]->set('field_machine_name', $renamed);
      $statuses[$machine_name]->set('field_semantic_flags', []);
      $statuses[$machine_name]->save();
    }
    foreach ($statuses as $status) {
      $status->set('field_citizen_label_de', '');
      $status->set('field_citizen_label_en', '');
      $status->save();
    }
    // Renamed by staff: the seed text does not fit any more.
    $statuses['genehmigt']->set('label', 'Genehmigt (Altverfahren)');
    $statuses['genehmigt']->save();
    // Own text: kept.
    $statuses['in_bearbeitung']->set('field_citizen_label_en', 'Under review');
    $statuses['in_bearbeitung']->save();

    $message = beantragt_workflow_status_update_10008();
    $this->assertStringContainsString('Added 2 hand-over and filing statuses to 1 catalogues', $message);
    $reloaded = $this->catalogue((int) $group->id());
    $this->assertSame(['hand_over'], $reloaded['fachverfahren_weiterleitung']->getSemanticFlags());
    $this->assertEqualsCanonicalizing(['filed', 'closed'], $reloaded['im_fachverfahren_abgelegt']->getSemanticFlags());
    $this->assertSame('In Bearbeitung', $reloaded['fachverfahren_uebergeben']->get('field_citizen_label_de')->value);
    $this->assertSame('Received', $reloaded['eingegangen']->get('field_citizen_label_en')->value);
    $this->assertSame('', (string) $reloaded['genehmigt']->get('field_citizen_label_de')->value);
    $this->assertSame('', (string) $reloaded['in_bearbeitung']->get('field_citizen_label_de')->value);
    $this->assertSame('Under review', $reloaded['in_bearbeitung']->get('field_citizen_label_en')->value);
    // The re-added library statuses get no transition from the standard.
    foreach (['eingegangen', 'in_bearbeitung'] as $standard) {
      $next = $reloaded[$standard]->getAllowedNextMachineNames();
      $this->assertSame([], array_diff($next, ['in_bearbeitung', 'abgeschlossen']), $standard);
    }
    // The former transition Eingegangen to Nachforderung offen is not added.
    $before = $reloaded['eingegangen']->getAllowedNextMachineNames();
    $this->assertStringStartsWith('Skipped', beantragt_workflow_status_update_10002());
    $this->assertSame($before, $this->catalogue((int) $group->id())['eingegangen']->getAllowedNextMachineNames());
    // A second run changes nothing.
    $this->assertStringContainsString('Added 0 hand-over and filing statuses to 0 catalogues; filled citizen texts of 0 statuses', beantragt_workflow_status_update_10008());
  }

  /**
   * Builds an unsaved status of a fresh group.
   */
  private function newStatus(string $label): WorkflowStatusInterface {
    static $count = 0;
    $count++;
    $group = $this->createGroup([
      'type' => 'jur',
      'label' => 'Label jurisdiction ' . $count,
      'field_slug' => 'label-jur-' . $count,
    ]);
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => $label,
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => 'custom_' . $count,
      'field_weight' => 500,
    ]);
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Loads the catalogue of a group keyed by machine name.
   *
   * @return array<string, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   *   Statuses.
   */
  private function catalogue(int $group_id): array {
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $storage->resetCache();
    $statuses = [];
    foreach ($storage->loadByProperties(['field_group' => $group_id]) as $status) {
      assert($status instanceof WorkflowStatusInterface);
      $statuses[$status->getMachineName()] = $status;
    }
    return $statuses;
  }

}
