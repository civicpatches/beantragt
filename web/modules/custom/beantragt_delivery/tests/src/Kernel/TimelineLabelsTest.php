<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\TimelineLabels;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests connection texts on automatic timeline entries.
 */
#[Group('beantragt_delivery')]
final class TimelineLabelsTest extends KernelTestBase {

  private const CONNECTION = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/beantragt_delivery.install';
    $database = $this->container->get('database');
    foreach (beantragt_delivery_schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
  }

  /**
   * Handover and decision entries take the connection's texts.
   */
  public function testConnectionTextsReplaceAutomaticStatusLabels(): void {
    $this->transfer('accepted', [
      'handover_label' => ['de' => 'In der E-Akte abgelegt', 'en' => 'Filed in the e-file'],
      'response_label' => ['de' => 'Bescheid zugestellt', 'en' => ''],
    ]);

    $timeline = $this->timeline();
    $this->labels('en')->apply($timeline, $this->application());
    self::assertSame('Eingegangen', $timeline[0]['label']);
    self::assertSame('Filed in the e-file', $timeline[1]['label']);
    // No English text: the German source text wins over the status label.
    self::assertSame('Bescheid zugestellt', $timeline[2]['label']);

    $timeline = $this->timeline();
    $this->labels('de')->apply($timeline, $this->application());
    self::assertSame('In der E-Akte abgelegt', $timeline[1]['label']);

    // The language the reader asked for wins over the site language.
    $timeline = $this->timeline();
    $this->labels('de')->apply($timeline, $this->application(), 'en');
    self::assertSame('Filed in the e-file', $timeline[1]['label']);
  }

  /**
   * Without texts or without an accepted transfer the status labels stay.
   */
  public function testStatusLabelsStayWithoutConnectionTexts(): void {
    $this->transfer('failed', ['handover_label' => ['de' => 'In der E-Akte abgelegt', 'en' => '']]);
    $timeline = $this->timeline();
    $this->labels('de')->apply($timeline, $this->application());
    self::assertSame($this->timeline(), $timeline);

    $this->container->get('database')->update('beantragt_delivery_log')->fields(['status' => 'accepted'])->execute();
    $this->container->get('database')->update('beantragt_integration_connection')->fields(['presentation' => NULL])->execute();
    $this->labels('de')->apply($timeline, $this->application());
    self::assertSame($this->timeline(), $timeline);
  }

  /**
   * Outcome words come from the connection that accepted the transfer.
   */
  public function testOutcomeLabelsComeFromTheAcceptingConnection(): void {
    $this->transfer('accepted', [
      'approved_label' => ['de' => 'Festsetzung', 'en' => ''],
      'filed_label' => ['de' => 'In der E-Akte abgelegt', 'en' => 'Filed in the e-file'],
    ]);
    self::assertSame(
      ['approved' => 'Festsetzung', 'rejected' => '', 'filed' => 'Filed in the e-file'],
      $this->labels('en')->outcomeLabels($this->application()),
    );

    // Without an accepted transfer every outcome keeps the product's word.
    $this->container->get('database')->update('beantragt_delivery_log')->fields(['status' => 'failed'])->execute();
    self::assertSame(
      ['approved' => '', 'rejected' => '', 'filed' => ''],
      $this->labels('de')->outcomeLabels($this->application()),
    );
  }

  /**
   * Stores one FIT-Connect transfer of application 1 over the connection.
   */
  private function transfer(string $status, array $presentation): void {
    $database = $this->container->get('database');
    $database->insert('beantragt_integration_connection')->fields([
      'uuid' => self::CONNECTION,
      'scope_group_id' => 10,
      'created' => 1,
      'changed' => 1,
      'uid' => 1,
      'contract_key' => 'synthetic',
      'contract_version' => '1',
      'label' => 'E-Akte',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'default',
      'destination_id' => self::CONNECTION,
      'service_urn' => 'urn:de:fim:leika:leistung:99000000000000',
      'service_name' => 'Ablage',
      'status' => 'active',
      'presentation' => json_encode($presentation),
    ])->execute();
    $database->insert('beantragt_delivery_log')->fields([
      'id' => 7,
      'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
      'application_id' => 1,
      'application_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'scope_group_id' => 10,
      'adapter' => 'fit_connect',
      'status' => $status,
      'data_hash' => str_repeat('a', 64),
      'created' => 1,
      'changed' => 1,
      'integration_connection_uuid' => self::CONNECTION,
    ])->execute();
  }

  /**
   * Outcome statuses keep their own citizen text; the response text does not.
   */
  public function testResponseTextOnlyNamesNoticesWithoutOutcome(): void {
    $this->transfer('accepted', [
      'handover_label' => ['de' => 'Beim Steueramt eingegangen', 'en' => ''],
      'response_label' => ['de' => 'Bescheid zugestellt', 'en' => ''],
    ]);
    $timeline = [
      ['status' => 'fachverfahren_uebergeben', 'label' => 'In Bearbeitung'],
      ['status' => 'genehmigt_mit_bescheid', 'label' => 'Bewilligt'],
      ['status' => 'abgelegt_mit_bescheid', 'label' => 'Abgeschlossen'],
      ['status' => 'bescheid_erstellt', 'label' => 'Bescheid erstellt'],
    ];
    $this->labels('de', [
      4 => ['genehmigt_mit_bescheid', ['approved', 'decision_issued', 'closed']],
      5 => ['abgelegt_mit_bescheid', ['filed', 'decision_issued', 'closed']],
    ])->apply($timeline, $this->application());
    self::assertSame(['Beim Steueramt eingegangen', 'Bewilligt', 'Abgeschlossen', 'Bescheid zugestellt'], array_column($timeline, 'label'));
  }

  /**
   * The hand-over text never names the in-progress status.
   *
   * On a form without the hand-over function the acceptance moves the case
   * to the standard's in-progress status. Only an "in case system" status
   * takes the connection's hand-over text, so the status mail and the
   * timeline show the status's own citizen text, and no other text exists
   * that could send a second mail.
   */
  public function testHandOverTextLeavesTheInProgressStatus(): void {
    $this->transfer('accepted', ['handover_label' => ['de' => 'Beim Steueramt eingegangen', 'en' => '']]);
    $timeline = [
      ['status' => 'eingegangen', 'label' => 'Eingegangen'],
      ['status' => 'in_bearbeitung', 'label' => 'In Bearbeitung'],
    ];
    $this->labels('de', [6 => ['in_bearbeitung', ['documents_received']]])->apply($timeline, $this->application());
    self::assertSame(['Eingegangen', 'In Bearbeitung'], array_column($timeline, 'label'));
  }

  /**
   * Builds the service with the given current language.
   */
  private function labels(string $langcode, array $extra = []): TimelineLabels {
    $statuses = [];
    foreach ([
      1 => ['eingegangen', ['received']],
      2 => ['fachverfahren_uebergeben', ['in_case_system']],
      3 => ['bescheid_erstellt', ['decision_issued', 'closed']],
    ] + $extra as $id => [$machine_name, $flags]) {
      $status = $this->createMock(WorkflowStatusInterface::class);
      $status->method('getGroupId')->willReturn(5);
      $status->method('getMachineName')->willReturn($machine_name);
      $status->method('getSemanticFlags')->willReturn($flags);
      $statuses[$id] = $status;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(3)->willReturn($statuses[3]);
    $storage->method('loadByProperties')->with(['field_group' => 5])->willReturn($statuses);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $manager->method('getStorage')->with('workflow_status')->willReturn($storage);
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getCurrentLanguage')->willReturn(new Language(['id' => $langcode]));
    return new TimelineLabels($this->container->get('database'), $manager, $languages);
  }

  /**
   * Builds application 1 on the decision status.
   */
  private function application(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('getWorkflowStatusId')->willReturn(3);
    return $application;
  }

  /**
   * Returns the timeline as the builders produce it.
   */
  private function timeline(): array {
    return [
      ['status' => 'eingegangen', 'label' => 'Eingegangen'],
      ['status' => 'fachverfahren_uebergeben', 'label' => 'An Fachverfahren übergeben'],
      ['status' => 'bescheid_erstellt', 'label' => 'Bescheid erstellt'],
    ];
  }

}
