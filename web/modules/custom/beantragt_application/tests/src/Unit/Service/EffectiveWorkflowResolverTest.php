<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which form revision governs the workflow.
 */
#[CoversClass(EffectiveWorkflowResolver::class)]
#[Group('beantragt_application')]
final class EffectiveWorkflowResolverTest extends UnitTestCase {

  /**
   * A published default revision governs every application, without a query.
   */
  public function testPublishedDefaultRevisionGovernsOlderApplications(): void {
    $default = $this->schema(17, 501, 2, 'published', 'approved');
    $storage = $this->storage($default);
    $storage->expects($this->never())->method('getQuery');
    $storage->expects($this->never())->method('loadRevision');
    $resolver = $this->resolver($storage);

    $this->assertSame('approved', $resolver->workflowConfig($this->application(17, 1))['transitions'][0]['to']);
    $this->assertSame('approved', $resolver->workflowConfig($this->application(17, 2))['transitions'][0]['to']);
    $this->assertSame(501, $resolver->workflowRevisionId($this->application(17, 1)));
    $this->assertSame(EffectiveWorkflowResolver::SOURCE_PUBLISHED, $resolver->workflowSource($this->application(17, 1))['source']);
  }

  /**
   * While the form is a draft again, the last published revision governs.
   */
  public function testDraftAfterPublishUsesLatestPublishedRevision(): void {
    $default = $this->schema(17, 503, 3, 'draft', 'draft_target');
    $published = $this->schema(17, 502, 2, 'published', 'approved');
    $conditions = [];
    $storage = $this->storage($default, ['published' => [502 => 17]], $conditions);
    $storage->expects($this->once())->method('loadRevision')->with(502)->willReturn($published);
    $resolver = $this->resolver($storage);

    $this->assertSame('approved', $resolver->workflowConfig($this->application(17, 1))['transitions'][0]['to']);
    // Memoised per form and default revision: no second query or load.
    $this->assertSame('approved', $resolver->workflowConfig($this->application(17, 3))['transitions'][0]['to']);
    $this->assertSame(502, $resolver->workflowRevisionId($this->application(17, 1)));
    $this->assertContains(['field_workflow_status', 'published'], $conditions);
  }

  /**
   * A never-published form keeps the intake revision per application.
   */
  public function testNeverPublishedFormUsesStoredSchemaVersion(): void {
    $current = $this->schema(17, 602, 2, 'draft', 'approved');
    $historic = $this->schema(17, 601, 1, 'draft', 'returned');
    $storage = $this->storage($current, ['published' => [], 'version' => [601 => 17]]);
    $storage->expects($this->once())->method('loadRevision')->with(601)->willReturn($historic);
    $resolver = $this->resolver($storage);

    $this->assertSame('approved', $resolver->workflowConfig($this->application(17, 2))['transitions'][0]['to']);
    $this->assertSame('returned', $resolver->workflowConfig($this->application(17, 1))['transitions'][0]['to']);
    $this->assertSame(601, $resolver->workflowRevisionId($this->application(17, 1)));
    $this->assertSame(EffectiveWorkflowResolver::SOURCE_INTAKE, $resolver->workflowSource($this->application(17, 1))['source']);
  }

  /**
   * Without a form the organisation graph applies.
   */
  public function testApplicationWithoutFormHasNoWorkflow(): void {
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->expects($this->never())->method('load');
    $resolver = $this->resolver($storage);

    $application = $this->application(0, 1);
    $this->assertSame([], $resolver->workflowConfig($application));
    $this->assertSame(0, $resolver->workflowRevisionId($application));
    $this->assertNull($resolver->workflowSource($application));
  }

  /**
   * Refresh drops a stale form object when the default revision moved.
   */
  public function testRefreshResetsStaleFormWhenDefaultRevisionMoved(): void {
    $held = $this->schema(17, 501, 1, 'published', 'approved');
    $storage = $this->storage($held, ['default' => [502 => 17]]);
    $storage->expects($this->once())->method('resetCache')->with([17]);
    $this->resolver($storage)->refresh($this->application(17, 1));

    $fresh = $this->storage($held, ['default' => [501 => 17]]);
    $fresh->expects($this->never())->method('resetCache');
    $this->resolver($fresh)->refresh($this->application(17, 1));
  }

  /**
   * The decision predicate mirrors the mail template choice.
   */
  #[DataProvider('decisionStatuses')]
  public function testDecisionStatusPredicate(array $flags, string $machine_name, bool $expected): void {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('getSemanticFlags')->willReturn($flags);
    $status->method('getMachineName')->willReturn($machine_name);
    $this->assertSame($expected, EffectiveWorkflowResolver::isDecisionStatus($status));
  }

  /**
   * Flagged, flagless-named and ordinary statuses.
   */
  public static function decisionStatuses(): array {
    return [
      'approved flag' => [['approved', 'closed'], 'positiv', TRUE],
      'rejected flag' => [['rejected'], 'negativ', TRUE],
      'flags win over a legacy name' => [['received'], 'abgelehnt', FALSE],
      'flagless genehmigt' => [[], 'genehmigt', TRUE],
      'flagless abgelehnt' => [[], 'abgelehnt', TRUE],
      'flagless approved' => [[], 'approved', TRUE],
      'flagless rejected' => [[], 'rejected', TRUE],
      'ordinary flagged' => [['request_info'], 'nachforderung_offen', FALSE],
      'ordinary flagless' => [[], 'fachpruefung', FALSE],
      'closed only' => [['withdrawn', 'closed'], 'zurueckgezogen', FALSE],
    ];
  }

  /**
   * Builds the resolver over one form storage.
   */
  private function resolver(RevisionableStorageInterface $storage): EffectiveWorkflowResolver {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('form_schema')->willReturn($storage);
    return new EffectiveWorkflowResolver(
      $manager,
      $this->createMock(WorkflowStatusManager::class),
    );
  }

  /**
   * Builds a form storage whose default revision is the given schema.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $default
   *   The default revision.
   * @param array<string, array<int, int>>|null $results
   *   Query results keyed by query kind: "published" (latest published
   *   revision), "version" (intake revision by version) and "default" (the
   *   refresh probe). NULL when no query is expected.
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Collects the query conditions.
   */
  private function storage(FormSchemaInterface $default, ?array $results = NULL, array &$conditions = []): RevisionableStorageInterface {
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('load')->with($default->id())->willReturn($default);
    if ($results !== NULL) {
      $storage->method('getQuery')->willReturnCallback(function () use ($results, &$conditions): QueryInterface {
        $kind = 'default';
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->with(FALSE)->willReturnSelf();
        $query->method('allRevisions')->willReturnSelf();
        $query->method('condition')->willReturnCallback(function (string $field, mixed $value) use ($query, &$conditions, &$kind): QueryInterface {
          $conditions[] = [$field, $value];
          $kind = match ($field) {
            'field_workflow_status' => 'published',
            'field_version' => 'version',
            default => $kind,
          };
          return $query;
        });
        $query->method('sort')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturnCallback(static function () use ($results, &$kind): array {
          return $results[$kind] ?? [];
        });
        return $query;
      });
    }
    return $storage;
  }

  /**
   * Creates a form schema revision with one workflow target.
   */
  private function schema(int $id, int $revision_id, int $version, string $lifecycle, string $target): FormSchemaInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('id')->willReturn($id);
    $schema->method('uuid')->willReturn('00000000-0000-4000-8000-0000000000' . $id);
    $schema->method('getRevisionId')->willReturn($revision_id);
    $schema->method('getVersion')->willReturn($version);
    $schema->method('getSchemaStatus')->willReturn($lifecycle);
    $schema->method('getWorkflowDefinition')->willReturn(json_encode([
      'mode' => 'override',
      'statuses' => ['in_review', $target],
      'transitions' => [['from' => 'in_review', 'to' => $target]],
    ], JSON_THROW_ON_ERROR));
    return $schema;
  }

  /**
   * Creates an application pinned to one form and intake version.
   */
  private function application(int $form_id, int $version): ApplicationInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => $property === 'target_id' && $form_id > 0);
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $property === 'target_id' && $form_id > 0 ? $form_id : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getSchemaVersion')->willReturn($version);
    $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')->with('field_schema')->willReturn($field);
    return $application;
  }

}
