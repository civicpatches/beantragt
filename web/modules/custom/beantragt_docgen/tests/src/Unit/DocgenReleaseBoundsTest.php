<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Controller\DocgenCaseController;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Release input is bounded before expensive document projections.
 */
#[Group('beantragt_docgen')]
final class DocgenReleaseBoundsTest extends UnitTestCase {

  /**
   * Too many distinct documents never enter the projection loop.
   */
  public function testOversizedReleaseDoesNotProjectDocuments(): void {
    $documents = $this->createMock(DocumentService::class);
    $documents->expects(self::never())->method('get');
    $documents->expects(self::never())->method('release');
    $documents->expects(self::never())->method('resumeRelease');
    $ids = [];
    for ($i = 0; $i <= DocumentService::MAX_RELEASE_DOCUMENTS; $i++) {
      $ids[] = sprintf('00000000-0000-4000-8000-%012d', $i);
    }
    $response = $this->controller($documents)->release($this->request($ids), $this->createMock(ApplicationInterface::class));
    self::assertSame(422, $response->getStatusCode());
    self::assertSame('docgen.release_count_invalid', json_decode($response->getContent(), TRUE)['error']['code']);
  }

  /**
   * Repeated UUIDs are normalized once before inspecting state.
   */
  public function testDuplicatesProjectOnce(): void {
    $id = 'abcdef00-0000-4000-8000-000000000000';
    $documents = $this->createMock(DocumentService::class);
    $documents->expects(self::once())->method('get')->with(self::anything(), $id, self::anything())->willReturn(['state' => 'draft']);
    $documents->expects(self::once())->method('release')->with(self::anything(), [$id], self::anything())->willReturn([]);
    $documents->expects(self::never())->method('resumeRelease');
    $response = $this->controller($documents)->release($this->request([$id, strtoupper($id), $id]), $this->createMock(ApplicationInterface::class));
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Builds the real controller around a projection spy.
   */
  private function controller(DocumentService $documents): DocgenCaseController {
    $scope = $this->createMock(DocgenScopeAccess::class);
    $scope->method('canWorkOnCase')->willReturn(TRUE);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(TRUE);
    return new DocgenCaseController($scope, $account, $csrf, new NullLogger(), $documents, $this->createMock(FloodInterface::class));
  }

  /**
   * Builds an authenticated JSON release request.
   */
  private function request(array $documents): Request {
    return Request::create('/api/admin/applications/1/docgen/release', 'POST', [], [], [],
      ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'synthetic'],
      json_encode(['data' => ['documents' => $documents]], JSON_THROW_ON_ERROR),
    );
  }

}
