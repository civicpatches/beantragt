<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Controller\DocgenCaseController;
use Drupal\beantragt_docgen\Controller\DocgenCatalogController;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\FormTemplates;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foreign organisations and applications stay closed.
 *
 * Real jurisdiction and organisation groups, real memberships and the real
 * DocgenScopeAccess behind the controllers; the application is a double whose
 * view access is granted to everybody, so only the group scope can refuse.
 * Also proves that refusals, including a missing CSRF token, answer with the
 * documented error envelope and that release needs update
 * access to the application.
 */
#[Group('beantragt_docgen')]
final class DocgenScopeDenialKernelTest extends GroupKernelTestBase {

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
    'beantragt_docgen',
  ];

  private const CASE_PERMISSIONS = ['create docgen documents', 'release docgen documents'];

  /**
   * Organisation A owns the application, the form and the template.
   */
  private GroupInterface $organisationA;

  /**
   * Organisation B in another jurisdiction.
   */
  private GroupInterface $organisationB;

  /**
   * Case worker in organisation A.
   */
  private UserInterface $workerA;

  /**
   * Case worker in organisation B.
   */
  private UserInterface $workerB;

  /**
   * Member of A who may view but not edit the application.
   */
  private UserInterface $readerA;

  /**
   * Member of A who may draft but not release.
   */
  private UserInterface $drafterA;

  /**
   * Template administrator of organisation B.
   */
  private UserInterface $adminB;

  /**
   * Released template of organisation A.
   */
  private DocgenTemplateInterface $templateA;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('docgen_template');
    $this->installSchema('beantragt_docgen', array_keys(DocumentRepository::schema() + DocgenSettingsRepository::schema()));

    $jurisdiction_a = $this->createGroup(['type' => 'jur', 'label' => 'Stadt A', 'field_slug' => 'stadt-a']);
    $jurisdiction_b = $this->createGroup(['type' => 'jur', 'label' => 'Stadt B', 'field_slug' => 'stadt-b']);
    $this->organisationA = $this->createOrganisation('amt-a', $jurisdiction_a);
    $this->organisationB = $this->createOrganisation('amt-b', $jurisdiction_b);

    $this->workerA = $this->member($this->organisationA, self::CASE_PERMISSIONS);
    $this->workerB = $this->member($this->organisationB, self::CASE_PERMISSIONS);
    $this->readerA = $this->member($this->organisationA, self::CASE_PERMISSIONS);
    $this->drafterA = $this->member($this->organisationA, ['create docgen documents']);
    $this->adminB = $this->member($this->organisationB, ['administer docgen templates', 'create docgen documents'], 'org-admin');

    $directory = TemplateCatalog::DIRECTORY . '/' . $this->organisationA->id();
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file = $this->container->get('file.repository')->writeData('template A', $directory . '/a.docx', FileExists::Error);
    $template = $this->container->get('entity_type.manager')->getStorage('docgen_template')->create([
      'family' => $this->container->get('uuid')->generate(),
      'version' => 1,
      'label' => 'Bewilligung A',
      'purpose' => 'approval',
      'status' => DocgenTemplateInterface::STATUS_RELEASED,
      'field_jurisdiction' => $jurisdiction_a->id(),
      'field_organisation' => $this->organisationA->id(),
      'file' => ['target_id' => $file->id()],
      'sha256' => hash('sha256', 'template A'),
      'inspection' => '{"placeholders":[],"loops":[],"errors":[]}',
    ]);
    $template->save();
    assert($template instanceof DocgenTemplateInterface);
    $this->templateA = $template;
  }

  /**
   * Case routes: foreign application, missing release right, read-only role.
   */
  public function testCaseRoutesRefuseOutsideTheScope(): void {
    $application = $this->application();

    // Organisation B cannot even see that the case has documents.
    $this->assertError($this->caseController($this->workerB)->overview($this->get(), $application), 404, 'docgen.application_not_found');
    $this->assertError($this->caseController($this->workerB)->release($this->post(['documents' => []]), $application), 404, 'docgen.application_not_found');

    // Drafting without the release permission: 403 on release.
    $this->assertError($this->caseController($this->drafterA)->release($this->post(['documents' => []]), $application), 403, 'docgen.access_denied');

    // View access alone reads, but never drafts or releases.
    self::assertSame(200, $this->caseController($this->readerA)->overview($this->get(), $application)->getStatusCode());
    $this->assertError($this->caseController($this->readerA)->release($this->post(['documents' => []]), $application), 403, 'docgen.access_denied');
    $this->assertError($this->caseController($this->readerA)->createDocument($this->post(['templateId' => $this->templateA->uuid()]), $application), 403, 'docgen.access_denied');

    // An empty set passes scope but fails release input validation.
    $this->assertError($this->caseController($this->workerA)->release($this->post(['documents' => []]), $application), 422, 'docgen.release_count_invalid');

    // The case worker of A can release a valid document set.
    $documents = ['0b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d'];
    self::assertSame(200, $this->caseController($this->workerA)->release($this->post(['documents' => $documents]), $application)->getStatusCode());

    // A mutation without CSRF token answers with the envelope as well.
    $this->assertError($this->caseController($this->workerA)->release($this->post(['documents' => []], ''), $application), 403, 'docgen.csrf_invalid');
  }

  /**
   * Catalog routes: foreign scope, template and form stay hidden.
   */
  public function testCatalogRoutesRefuseOutsideTheScope(): void {
    $scope_a = (string) $this->organisationA->id();
    $this->assertError($this->catalogController($this->workerB)->listTemplates($this->get(['scope' => $scope_a])), 404, 'docgen.scope_not_found');
    self::assertSame(200, $this->catalogController($this->workerA)->listTemplates($this->get(['scope' => $scope_a]))->getStatusCode());

    $this->assertError($this->catalogController($this->workerB)->readTemplate($this->get(), $this->templateA->uuid()), 404, 'docgen.template_not_found');
    $this->assertError($this->catalogController($this->workerB)->templateFile($this->get(), $this->templateA->uuid()), 404, 'docgen.template_not_found');
    self::assertSame(200, $this->catalogController($this->workerA)->readTemplate($this->get(), $this->templateA->uuid())->getStatusCode());

    $form_uuid = '0b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d';
    $this->assertError($this->catalogController($this->workerB)->formSettings($this->get(), $form_uuid), 404, 'docgen.form_not_found');
    $this->assertError($this->catalogController($this->workerB)->formVariables($this->get(), $form_uuid), 404, 'docgen.form_not_found');

    // An administrator of B manages nothing in A.
    $upload = ['name' => 'x', 'purpose' => 'other', 'filename' => 'x.docx', 'content' => 'eA=='];
    $response = $this->catalogController($this->adminB)->uploadTemplate($this->post($upload, 'token', ['scope' => $scope_a]));
    $this->assertError($response, 403, 'docgen.access_denied');
    $this->assertError($this->catalogController($this->adminB)->releaseTemplate($this->post([]), $this->templateA->uuid()), 404, 'docgen.template_not_found');

    // Health is for template administrators only.
    $this->assertError($this->catalogController($this->workerA)->health($this->get()), 403, 'docgen.access_denied');
  }

  /**
   * Creates an organisation in a jurisdiction.
   */
  private function createOrganisation(string $slug, GroupInterface $jurisdiction): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $slug,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
  }

  /**
   * Creates a staff member of one organisation.
   *
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   The organisation.
   * @param string[] $permissions
   *   Global permissions.
   * @param string $group_role
   *   The group role.
   */
  private function member(GroupInterface $organisation, array $permissions, string $group_role = 'org-member'): UserInterface {
    $user = $this->createUser($permissions);
    $organisation->addMember($user, ['group_roles' => [$group_role]]);
    return $user;
  }

  /**
   * Application of organisation A that everybody may view.
   */
  private function application(): ApplicationInterface {
    $reference = $this->createMock(FieldItemListInterface::class);
    $reference->method('isEmpty')->willReturn(FALSE);
    $reference->method('__isset')->willReturn(TRUE);
    $reference->method('__get')->willReturnCallback(fn (string $name) => $name === 'entity' ? $this->organisationA : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_organisation');
    $application->method('get')->willReturn($reference);
    $reader = (int) $this->readerA->id();
    $application->method('access')->willReturnCallback(static fn (string $operation, ?AccountInterface $account = NULL): bool => $operation === 'view' || (int) $account?->id() !== $reader);
    return $application;
  }

  /**
   * Case controller acting as the given account.
   */
  private function caseController(UserInterface $account): DocgenCaseController {
    $this->setCurrentUser($account);
    $documents = $this->createMock(DocumentService::class);
    $documents->method('availableTemplates')->willReturn([
      'route' => DocgenSettingsRepository::ROUTE_NONE,
      'templates' => [],
    ]);
    $documents->method('listDocuments')->willReturn([]);
    $documents->method('get')->willReturn(['state' => DocumentRepository::STATE_DRAFT]);
    $documents->method('release')->willReturn(['documents' => [], 'response' => []]);
    return new DocgenCaseController(
      $this->container->get('beantragt_docgen.scope_access'),
      $this->container->get('current_user'),
      $this->csrf(),
      $this->container->get('logger.channel.beantragt_docgen'),
      $documents,
      $this->container->get('flood'),
    );
  }

  /**
   * Catalog controller acting as the given account.
   */
  private function catalogController(UserInterface $account): DocgenCatalogController {
    $this->setCurrentUser($account);
    $form = $this->createMock(FormSchemaInterface::class);
    $reference = $this->createMock(FieldItemListInterface::class);
    $reference->method('isEmpty')->willReturn(FALSE);
    $reference->method('__isset')->willReturn(TRUE);
    $reference->method('__get')->willReturnCallback(fn (string $name) => $name === 'entity' ? $this->organisationA : NULL);
    $form->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_organisation');
    $form->method('get')->willReturn($reference);
    $form->method('access')->willReturn(TRUE);
    $forms = $this->createMock(FormTemplates::class);
    $forms->method('loadForm')->willReturn($form);
    return new DocgenCatalogController(
      $this->container->get('beantragt_docgen.scope_access'),
      $this->container->get('current_user'),
      $this->csrf(),
      $this->container->get('logger.channel.beantragt_docgen'),
      $this->container->get('beantragt_docgen.template_catalog'),
      $forms,
      $this->container->get('beantragt_docgen.settings'),
      $this->container->get('beantragt_docgen.document_repository'),
      $this->container->get('beantragt_docgen.client'),
      $this->container->get('flood'),
    );
  }

  /**
   * CSRF validation that accepts the test token "token" only.
   */
  private function csrf(): CsrfTokenGenerator {
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturnCallback(static fn (string $token): bool => $token === 'token');
    return $csrf;
  }

  /**
   * A GET request.
   *
   * @param array<string, string> $query
   *   Query parameters.
   */
  private function get(array $query = []): Request {
    return Request::create('/api/admin/docgen', 'GET', $query);
  }

  /**
   * A JSON POST request with the {"data": ...} envelope.
   *
   * @param array<string, mixed> $data
   *   The data object.
   * @param string $token
   *   The X-CSRF-Token header ('' for none).
   * @param array<string, string> $query
   *   Query parameters.
   */
  private function post(array $data, string $token = 'token', array $query = []): Request {
    $uri = '/api/admin/docgen' . ($query === [] ? '' : '?' . http_build_query($query));
    $request = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['data' => (object) $data], JSON_THROW_ON_ERROR));
    if ($token !== '') {
      $request->headers->set('X-CSRF-Token', $token);
    }
    return $request;
  }

  /**
   * Asserts the error envelope.
   */
  private function assertError(Response $response, int $status, string $code): void {
    self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    $body = json_decode((string) $response->getContent(), TRUE);
    self::assertSame($code, $body['error']['code'] ?? NULL, (string) $response->getContent());
  }

}
