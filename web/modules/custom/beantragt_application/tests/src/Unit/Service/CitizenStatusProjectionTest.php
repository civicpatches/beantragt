<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Citizen texts, connection texts and collapsing in the citizen timeline.
 */
#[CoversClass(CitizenStatusProjection::class)]
#[Group('beantragt_application')]
final class CitizenStatusProjectionTest extends UnitTestCase {

  /**
   * Connection text for the hand-over entry; empty means none.
   */
  private string $handoverText = '';

  /**
   * Collapsing runs after the alter hook, so connection texts count.
   */
  public function testTimelineCollapsesAfterConnectionTexts(): void {
    $this->handoverText = 'Beim Steueramt zur Festsetzung eingegangen';
    $timeline = $this->projection()->timeline($this->application(), 'de');
    $this->assertSame(['Eingegangen', 'In Bearbeitung', 'Beim Steueramt zur Festsetzung eingegangen', 'Bewilligt'], array_column($timeline, 'label'));
    $this->assertSame(['eingegangen', 'fachpruefung', 'fachverfahren_uebergeben', 'genehmigt'], array_column($timeline, 'status'));

    $this->handoverText = '';
    $timeline = $this->projection()->timeline($this->application(), 'de');
    $this->assertSame(['Eingegangen', 'In Bearbeitung', 'Bewilligt'], array_column($timeline, 'label'));
    // The first entry of a run keeps its time.
    $this->assertSame(date(\DateTimeInterface::ATOM, 1700000200), $timeline[1]['timestamp']);
    $this->assertSame(['Received', 'In progress', 'Approved'], array_column($this->projection()->timeline($this->application(), 'en'), 'label'));
  }

  /**
   * The current status uses the same texts as the timeline.
   */
  public function testEntryAndLabelsUseTheSameText(): void {
    $this->handoverText = 'Beim Steueramt zur Festsetzung eingegangen';
    $projection = $this->projection();
    $handed_over = $this->workflowStatus('fachverfahren_uebergeben', 'An Fachverfahren übergeben', 'In Bearbeitung', 'In progress');
    $this->assertSame('Beim Steueramt zur Festsetzung eingegangen', $projection->entry($this->application(), $handed_over, 'de')['label']);
    $this->assertSame(
      ['In Bearbeitung', 'Beim Steueramt zur Festsetzung eingegangen', ''],
      $projection->labels($this->application(), [
        $this->workflowStatus('fachpruefung', 'Fachprüfung', 'In Bearbeitung', 'In progress'),
        $handed_over,
        NULL,
      ], 'de'),
    );
    $this->assertSame('', $projection->entry($this->application(), NULL, 'de')['label']);
  }

  /**
   * Only consecutive equal texts collapse.
   */
  public function testCollapseKeepsTheFirstOfRun(): void {
    $this->assertSame([
      ['label' => 'A', 'timestamp' => '1'],
      ['label' => 'B', 'timestamp' => '2'],
      ['label' => 'A', 'timestamp' => '4'],
    ], CitizenStatusProjection::collapse([
      ['label' => 'A', 'timestamp' => '1'],
      ['label' => 'B', 'timestamp' => '2'],
      ['label' => 'B', 'timestamp' => '3'],
      ['label' => 'A', 'timestamp' => '4'],
    ]));
  }

  /**
   * Builds the projection with a revision storage and an alter hook.
   */
  private function projection(): CitizenStatusProjection {
    $revisions = [];
    foreach ([
      ['eingegangen', 'Eingegangen', '', 'Received', 1700000100],
      ['fachpruefung', 'Fachprüfung', 'In Bearbeitung', 'In progress', 1700000200],
      ['fachverfahren_weiterleitung', 'Weiterleitung an Fachverfahren', 'In Bearbeitung', 'In progress', 1700000300],
      ['fachverfahren_uebergeben', 'An Fachverfahren übergeben', 'In Bearbeitung', 'In progress', 1700000400],
      ['genehmigt', 'Genehmigt', 'Bewilligt', 'Approved', 1700000500],
    ] as $index => [$machine_name, $label, $de, $en, $created]) {
      $revisions[$index + 1] = $this->revision($this->workflowStatus($machine_name, $label, $de, $en), $created);
    }
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'allRevisions', 'condition', 'sort'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn(array_combine(array_keys($revisions), array_keys($revisions)));
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->willReturnCallback(static fn(int $id) => $revisions[$id] ?? NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('alter')->willReturnCallback(function (string $type, array &$timeline): void {
      foreach ($timeline as &$entry) {
        if ($this->handoverText !== '' && ($entry['status'] ?? '') === 'fachverfahren_uebergeben') {
          $entry['label'] = $this->handoverText;
        }
      }
    });
    return new CitizenStatusProjection($manager, $module_handler);
  }

  /**
   * Builds the application whose revisions are listed.
   */
  private function application(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(9);
    return $application;
  }

  /**
   * Builds one revision on a status, by a system actor.
   */
  private function revision(WorkflowStatusInterface $status, int $created): ApplicationInterface {
    $status_field = $this->createMock(FieldItemListInterface::class);
    $status_field->method('isEmpty')->willReturn(FALSE);
    $status_field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $status : NULL);
    $created_field = $this->createMock(FieldItemListInterface::class);
    $created_field->method('isEmpty')->willReturn(FALSE);
    $created_field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $created : NULL);
    $user_field = $this->createMock(FieldItemListInterface::class);
    $user_field->method('isEmpty')->willReturn(TRUE);
    $revision = $this->createMock(ApplicationInterface::class);
    $revision->method('hasField')->willReturn(TRUE);
    $revision->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => match ($name) {
      'field_workflow_status' => $status_field,
      'revision_created' => $created_field,
      default => $user_field,
    });
    return $revision;
  }

  /**
   * Builds a status with its citizen texts.
   */
  private function workflowStatus(string $machine_name, string $label, string $de, string $en): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('label')->willReturn($label);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('getColor')->willReturn('#000000');
    $status->method('getIcon')->willReturn('i-lucide-circle');
    $status->method('getCitizenLabel')->willReturnCallback(static fn(string $langcode): string => ($langcode === 'en' ? ($en ?: $de) : $de) ?: $label);
    return $status;
  }

}
