<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decision documents on an application (staff only).
 */
final class DocgenCaseController extends DocgenControllerBase {

  /**
   * Permissions that allow reading documents of a case.
   */
  private const READ = [
    DocgenScopeAccess::PERMISSION_CREATE,
    DocgenScopeAccess::PERMISSION_RELEASE,
  ];

  /**
   * Permission that allows changing documents of a case.
   */
  private const WRITE = [DocgenScopeAccess::PERMISSION_CREATE];

  /**
   * Constructs the controller.
   */
  public function __construct(
    DocgenScopeAccess $scopeAccess,
    AccountProxyInterface $account,
    CsrfTokenGenerator $csrfToken,
    LoggerInterface $logger,
    private readonly DocumentService $documents,
    FloodInterface $flood,
  ) {
    parent::__construct($scopeAccess, $account, $csrfToken, $logger, $flood);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_docgen.scope_access'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('logger.channel.beantragt_docgen'),
      $container->get('beantragt_docgen.document_service'),
      $container->get('flood'),
    );
  }

  /**
   * GET /api/admin/applications/{application}/docgen.
   */
  public function overview(Request $request, ApplicationInterface $application): Response {
    return $this->handle($request, function () use ($application): array {
      $this->requireCase($application, self::READ);
      $available = $this->documents->availableTemplates($application);
      return [
        'route' => $available['route'],
        'templates' => array_map(static fn (DocgenTemplateInterface $template): array => [
          'id' => $template->uuid(),
          'family' => $template->getFamily(),
          'version' => $template->getVersion(),
          'name' => (string) $template->label(),
          'purpose' => $template->getPurpose(),
          'closesProcedure' => in_array($template->getPurpose(), DocgenTemplateInterface::CLOSING_PURPOSES, TRUE),
        ], $available['templates']),
        'documents' => $this->documents->listDocuments($application, $this->account),
        'canRelease' => $this->scopeAccess->canWorkOnCase($application, $this->account, DocgenScopeAccess::PERMISSION_RELEASE, TRUE),
        'maxReleaseDocuments' => DocumentService::MAX_RELEASE_DOCUMENTS,
        // Where the next decision and the next communication go.
        'delivery' => $this->documents->deliveryPreview($application),
      ];
    });
  }

  /**
   * POST /api/admin/applications/{application}/docgen/documents.
   */
  public function createDocument(Request $request, ApplicationInterface $application): Response {
    return $this->handle($request, function () use ($request, $application): array {
      $this->requireCase($application, self::WRITE, TRUE);
      $data = $this->body($request);
      $this->allowKeys($data, ['templateId', 'title']);
      $this->throttle('create');
      return $this->documents->create($application, (string) $this->string($data, 'templateId', TRUE, 36), $this->string($data, 'title', FALSE, 200), $this->account);
    }, 201);
  }

  /**
   * GET|DELETE …/docgen/documents/{document} of an application.
   */
  public function document(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($request, $application, $document): array {
      $this->requireCase($application, $request->isMethod('DELETE') ? self::WRITE : self::READ, $request->isMethod('DELETE'));
      if ($request->isMethod('DELETE')) {
        $this->documents->discard($application, $document);
        return ['deleted' => TRUE];
      }
      return $this->documents->get($application, $document, $this->account);
    });
  }

  /**
   * GET|POST …/docgen/documents/{document}/docx of an application.
   */
  public function docx(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($request, $application, $document): Response|array {
      $this->requireCase($application, $request->isMethod('POST') ? self::WRITE : self::READ, $request->isMethod('POST'));
      if ($request->isMethod('POST')) {
        $this->throttle('upload');
        $data = $this->body($request, self::MAX_UPLOAD_BYTES);
        $this->allowKeys($data, ['filename', 'content']);
        return $this->documents->uploadRevision($application, $document, (string) $this->string($data, 'filename'), $this->content($data), $this->account);
      }
      return $this->download($this->documents->docxFile($application, $document, $this->account), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'bescheid.docx');
    });
  }

  /**
   * POST …/docgen/documents/{document}/preview of an application.
   */
  public function preview(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($request, $application, $document): array {
      $this->requireCase($application, self::WRITE, TRUE);
      $this->allowKeys($this->body($request), []);
      $this->throttle('preview');
      return $this->documents->preview($application, $document, $this->account);
    });
  }

  /**
   * GET …/docgen/documents/{document}/pdf of an application.
   *
   * Serves the PDF without recording a view; see viewed().
   */
  public function pdf(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($application, $document): Response {
      $this->requireCase($application, self::READ);
      return $this->download($this->documents->pdfFile($application, $document, $this->account), 'application/pdf', 'bescheid.pdf');
    });
  }

  /**
   * GET …/docgen/documents/{document}/cover-pdf of an application.
   *
   * The cover sheet of a request letter, with the same gate, headers and
   * audit as the letter's PDF; no view is recorded (the release rule
   * concerns the letter).
   */
  public function coverPdf(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($application, $document): Response {
      $this->requireCase($application, self::READ);
      return $this->download($this->documents->coverPdfFile($application, $document, $this->account), 'application/pdf', 'deckblatt.pdf');
    });
  }

  /**
   * POST …/docgen/documents/{document}/rerender of an application.
   *
   * Renders the draft again from its template: new deadline, request record
   * and cover sheet; uploaded Word edits are lost.
   */
  public function rerender(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($request, $application, $document): array {
      $this->requireCase($application, self::WRITE, TRUE);
      $this->allowKeys($this->body($request), []);
      $this->throttle('create');
      return $this->documents->rerender($application, $document, $this->account);
    });
  }

  /**
   * POST …/docgen/documents/{document}/viewed of an application.
   *
   * The browser confirms the preview it received: {"data": {"pdfSha256":
   * "<SHA-256 of the received bytes>"}}. Only the hash of the current
   * preview counts; it is recorded per person and PDF hash, and only that
   * person can release exactly that PDF (mandatory preview).
   */
  public function viewed(Request $request, ApplicationInterface $application, string $document): Response {
    return $this->handle($request, function () use ($request, $application, $document): array {
      $this->requireCase($application, self::READ);
      $data = $this->body($request);
      $this->allowKeys($data, ['pdfSha256']);
      return $this->documents->confirmViewed($application, $document, strtolower((string) $this->string($data, 'pdfSha256', TRUE, 64)), $this->account);
    });
  }

  /**
   * POST /api/admin/applications/{application}/docgen/release.
   */
  public function release(Request $request, ApplicationInterface $application): Response {
    return $this->handle($request, function () use ($request, $application): array {
      $this->requireCase($application, [DocgenScopeAccess::PERMISSION_RELEASE], TRUE);
      $data = $this->body($request);
      $this->allowKeys($data, ['documents']);
      $documents = $data['documents'] ?? NULL;
      if (!is_array($documents) || !array_is_list($documents)) {
        throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'documents']);
      }
      $documents = array_values(array_unique(array_map('strtolower', array_filter($documents, 'is_string'))));
      if ($documents === [] || count($documents) > DocumentService::MAX_RELEASE_DOCUMENTS) {
        throw new DocgenApiException('docgen.release_count_invalid', 422, ['max' => DocumentService::MAX_RELEASE_DOCUMENTS]);
      }
      foreach ($documents as $uuid) {
        if ($this->documents->get($application, $uuid, $this->account)['state'] === 'releasing') {
          return $this->documents->resumeRelease($application, $documents, $this->account);
        }
      }
      return $this->documents->release($application, $documents, $this->account);
    });
  }

  /**
   * Requires one of the permissions and the case scope.
   *
   * Answers 404 without any case access, 403 when the person works on the
   * case but lacks the permission (for example release) or, for a change,
   * update access to the application.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string[] $permissions
   *   Any of these permissions suffices.
   * @param bool $write
   *   The operation changes the case.
   */
  private function requireCase(ApplicationInterface $application, array $permissions, bool $write = FALSE): void {
    foreach ($permissions as $permission) {
      if ($this->scopeAccess->canWorkOnCase($application, $this->account, $permission, $write)) {
        return;
      }
    }
    foreach ([DocgenScopeAccess::PERMISSION_CREATE, DocgenScopeAccess::PERMISSION_RELEASE] as $permission) {
      if ($this->scopeAccess->canWorkOnCase($application, $this->account, $permission)) {
        throw new DocgenApiException('docgen.access_denied', 403);
      }
    }
    throw new DocgenApiException('docgen.application_not_found', 404);
  }

}
