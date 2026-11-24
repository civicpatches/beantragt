<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_comment\Unit\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_comment\Controller\CommentApiController;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * The staff message looks for "request info" in the governing workflow only.
 */
#[CoversClass(CommentApiController::class)]
#[Group('beantragt_comment')]
final class CommentRequestInfoLookupTest extends UnitTestCase {

  /**
   * The catalogue of organisation 7, keyed by id, in weight order.
   *
   * @var array<int, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   */
  private array $catalogue = [];

  /**
   * The logger of the controller.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->catalogue = [
      11 => $this->makeStatus(11, 'eingegangen', ['received']),
      12 => $this->makeStatus(12, 'nachforderung_offen', ['request_info']),
      13 => $this->makeStatus(13, 'rueckfrage_offen', []),
    ];
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturnCallback(fn(): array => array_combine(array_keys($this->catalogue), array_keys($this->catalogue)));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturnCallback(fn(array $ids): array => array_intersect_key($this->catalogue, array_flip(array_map('intval', $ids))));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $manager->method('getStorage')->with('workflow_status')->willReturn($storage);
    $container = new ContainerBuilder();
    $container->set('current_user', $this->createMock(AccountInterface::class));
    $container->set('entity_type.manager', $manager);
    \Drupal::setContainer($container);
    $this->logger = $this->createMock(LoggerInterface::class);
  }

  /**
   * Without the changer the whole catalogue is asked, as before.
   */
  public function testWithoutChangerTheCatalogueIsAsked(): void {
    $this->assertSame(12, $this->controller(NULL)->lookup($this->application()));
  }

  /**
   * A request-info status of the governing workflow is the target.
   */
  public function testStatusOfTheGoverningWorkflowIsTheTarget(): void {
    $this->assertSame(12, $this->controller([11, 12])->lookup($this->application()));
    // The legacy names count only inside the governing workflow as well.
    $this->assertSame(13, $this->controller([11, 13])->lookup($this->application()));
  }

  /**
   * A form whose workflow has no such status keeps its status, silently.
   */
  public function testFormWithoutRequestInfoStatusKeepsStatusWithoutLog(): void {
    $application = $this->application();
    $application->expects($this->never())->method('setWorkflowStatusId');
    $application->expects($this->never())->method('save');
    $this->logger->expects($this->never())->method('warning');
    $this->logger->expects($this->never())->method('error');
    $controller = $this->controller([11]);
    $this->assertNull($controller->lookup($application));
    $this->assertFalse($controller->mark($application));
  }

  /**
   * A miss without asking the governing workflow stays a warning.
   *
   * No changer, a changer without resolver (it answers NULL), or an
   * application without organisation: no workflow was asked.
   */
  public function testMissWithoutAskingTheWorkflowWarns(): void {
    unset($this->catalogue[12], $this->catalogue[13]);
    foreach (['no changer', 'changer without resolver', 'no organisation'] as $case) {
      $this->logger = $this->createMock(LoggerInterface::class);
      $this->logger->expects($this->once())->method('warning');
      $controller = match ($case) {
        'no changer' => $this->controller(NULL),
        'changer without resolver' => $this->controller(NULL, TRUE),
        'no organisation' => $this->controller([11]),
      };
      if ($case === 'no organisation') {
        $controller->organisation = NULL;
      }
      $this->assertFalse($controller->mark($this->application()), $case);
    }
  }

  /**
   * Builds the controller with a changer that knows the governing ids.
   *
   * @param int[]|null $governing
   *   Status ids of the governing workflow; NULL builds no changer.
   * @param bool $without_resolver
   *   With NULL ids: build a changer that answers NULL (no resolver).
   */
  private function controller(?array $governing, bool $without_resolver = FALSE): CommentRequestInfoTestController {
    $changer = NULL;
    if ($governing !== NULL || $without_resolver) {
      $changer = $this->createMock(SemanticStatusChanger::class);
      $changer->method('governingStatusIds')->with($this->anything(), 7)->willReturn($governing);
    }
    return new CommentRequestInfoTestController(
      $this->logger,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(TenantScopedEntityQuery::class),
      semanticStatuses: $changer,
    );
  }

  /**
   * An application on status 11.
   */
  private function application(): ApplicationInterface&MockObject {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(5);
    $application->method('getWorkflowStatusId')->willReturn(11);
    return $application;
  }

  /**
   * A status of the catalogue.
   *
   * @param int $id
   *   The id.
   * @param string $machine_name
   *   The machine name.
   * @param string[] $flags
   *   Semantic flags.
   */
  private function makeStatus(int $id, string $machine_name, array $flags): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('id')->willReturn($id);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('hasSemanticFlag')->willReturnCallback(static fn(string $flag): bool => in_array($flag, $flags, TRUE));
    return $status;
  }

}

/**
 * Exposes the lookup of the controller; the application belongs to org 7.
 */
final class CommentRequestInfoTestController extends CommentApiController {

  /**
   * The organisation the application belongs to.
   */
  public ?int $organisation = 7;

  /**
   * Resolves the request-info status like a public staff message does.
   */
  public function lookup(ApplicationInterface $application): ?int {
    return $this->resolveSemanticStatusId($application, 'request_info', [
      'returned',
      'nachforderung_offen',
      'rueckfrage_offen',
    ]);
  }

  /**
   * Runs the status follow-up of a public staff message.
   */
  public function mark(ApplicationInterface $application): bool {
    return $this->markApplicationRequestInfo($application);
  }

  /**
   * {@inheritdoc}
   */
  protected function applicationOrganisationId(ApplicationInterface $application): ?int {
    return $this->organisation;
  }

}
