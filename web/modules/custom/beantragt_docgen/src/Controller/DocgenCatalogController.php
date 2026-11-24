<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Controller;

use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\FormTemplates;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_docgen\Service\VariableCatalog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Template catalog, form assignment, variable catalog and docgen health.
 */
final class DocgenCatalogController extends DocgenControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DocgenScopeAccess $scopeAccess,
    AccountProxyInterface $account,
    CsrfTokenGenerator $csrfToken,
    LoggerInterface $logger,
    private readonly TemplateCatalog $catalog,
    private readonly FormTemplates $forms,
    private readonly DocgenSettingsRepository $settings,
    private readonly DocumentRepository $documents,
    private readonly DocgenClient $client,
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
      $container->get('beantragt_docgen.template_catalog'),
      $container->get('beantragt_docgen.form_templates'),
      $container->get('beantragt_docgen.settings'),
      $container->get('beantragt_docgen.document_repository'),
      $container->get('beantragt_docgen.client'),
      $container->get('flood'),
    );
  }

  /**
   * GET /api/admin/docgen/health.
   */
  public function health(Request $request): Response {
    return $this->handle($request, function (): array {
      // Infrastructure state is for template administrators; case work
      // learns about an outage from docgen.unavailable on the action itself.
      if (!$this->account->hasPermission(DocgenScopeAccess::PERMISSION_ADMINISTER)) {
        throw new DocgenApiException('docgen.access_denied', 403);
      }
      return ['configured' => $this->client->isConfigured()] + ($this->client->isConfigured() ? $this->client->health() : ['status' => 'not_configured']);
    });
  }

  /**
   * GET /api/admin/docgen/templates?scope=N.
   */
  public function listTemplates(Request $request): Response {
    return $this->handle($request, function () use ($request): array {
      $scope = $this->scopeFromQuery($request);
      $this->requireRead($scope);
      return [
        'templates' => array_map($this->project(...), $this->catalog->listForScope($scope)),
        'canManage' => $this->scopeAccess->canManageTemplates($scope, $this->account),
        'purposes' => DocgenTemplateInterface::PURPOSES,
        'closingPurposes' => DocgenTemplateInterface::CLOSING_PURPOSES,
      ];
    });
  }

  /**
   * POST /api/admin/docgen/templates?scope=N.
   */
  public function uploadTemplate(Request $request): Response {
    return $this->handle($request, function () use ($request): array {
      $scope = $this->scopeFromQuery($request);
      $this->requireManage($scope);
      $this->throttle('upload');
      $data = $this->body($request, self::MAX_UPLOAD_BYTES);
      $this->allowKeys($data, ['name', 'purpose', 'filename', 'content']);
      $template = $this->catalog->upload($scope, (string) $this->string($data, 'name'), (string) $this->string($data, 'purpose', TRUE, 32), (string) $this->string($data, 'filename'), $this->content($data), $this->account);
      return $this->project($template);
    }, 201);
  }

  /**
   * GET /api/admin/docgen/templates/{template}.
   */
  public function readTemplate(Request $request, string $template): Response {
    return $this->handle($request, function () use ($template): array {
      $entity = $this->requireTemplate($template, FALSE);
      return $this->project($entity) + ['versions' => array_map($this->project(...), $this->catalog->versions($entity->getFamily()))];
    });
  }

  /**
   * PATCH /api/admin/docgen/templates/{template} (draft name or purpose).
   */
  public function updateTemplate(Request $request, string $template): Response {
    return $this->handle($request, function () use ($request, $template): array {
      $entity = $this->requireTemplate($template, TRUE);
      $data = $this->body($request);
      $this->allowKeys($data, ['name', 'purpose']);
      return $this->project($this->catalog->updateDraft($entity, $this->string($data, 'name', FALSE), $this->string($data, 'purpose', FALSE, 32)));
    });
  }

  /**
   * DELETE /api/admin/docgen/templates/{template} (drafts only).
   */
  public function deleteTemplate(Request $request, string $template): Response {
    return $this->handle($request, function () use ($template): array {
      $entity = $this->requireTemplate($template, TRUE);
      if ($this->documents->countForTemplate((int) $entity->id()) > 0) {
        throw new DocgenApiException('docgen.template_in_use', 409);
      }
      $this->catalog->deleteDraft($entity);
      return ['deleted' => TRUE];
    });
  }

  /**
   * POST /api/admin/docgen/templates/{template}/release.
   */
  public function releaseTemplate(Request $request, string $template): Response {
    return $this->handle($request, function () use ($request, $template): array {
      $entity = $this->requireTemplate($template, TRUE);
      $this->allowKeys($this->body($request), []);
      return $this->project($this->catalog->release($entity, $this->account));
    });
  }

  /**
   * POST /api/admin/docgen/templates/{template}/versions (next version).
   */
  public function uploadVersion(Request $request, string $template): Response {
    return $this->handle($request, function () use ($request, $template): array {
      $entity = $this->requireTemplate($template, TRUE);
      $this->throttle('upload');
      $data = $this->body($request, self::MAX_UPLOAD_BYTES);
      $this->allowKeys($data, ['name', 'purpose', 'filename', 'content']);
      $scope = $entity->getScopeGroup() ?? throw new DocgenApiException('docgen.scope_not_found', 404);
      $new = $this->catalog->upload(
        $scope,
        $this->string($data, 'name', FALSE) ?? (string) $entity->label(),
        $this->string($data, 'purpose', FALSE, 32) ?? $entity->getPurpose(),
        (string) $this->string($data, 'filename'),
        $this->content($data),
        $this->account,
        $entity,
      );
      return $this->project($new);
    }, 201);
  }

  /**
   * GET /api/admin/docgen/templates/{template}/file.
   */
  public function templateFile(Request $request, string $template): Response {
    return $this->handle($request, function () use ($template): Response {
      $entity = $this->requireTemplate($template, FALSE);
      $file = $entity->getFile() ?? throw new DocgenApiException('docgen.template_not_found', 404);
      $this->catalog->bytes($entity);
      return $this->download($file, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'vorlage.docx');
    });
  }

  /**
   * GET|PUT /api/admin/docgen/settings?scope=N (sender address).
   */
  public function scopeSettings(Request $request): Response {
    return $this->handle($request, function () use ($request): array {
      $scope = $this->scopeFromQuery($request);
      if ($request->isMethod('PUT')) {
        $this->requireManage($scope);
        $data = $this->body($request);
        $this->allowKeys($data, ['senderAddress']);
        $address = trim((string) $this->string($data, 'senderAddress', FALSE, 1000));
        $this->settings->setSenderAddress((int) $scope->id(), $address);
      }
      else {
        $this->requireRead($scope);
      }
      return [
        'scope' => (int) $scope->id(),
        'senderAddress' => $this->settings->ownSenderAddress((int) $scope->id()),
        'effectiveSenderAddress' => $this->settings->senderAddress($scope),
      ];
    });
  }

  /**
   * GET|PUT /api/admin/docgen/forms/{form} (route, templates, delivery).
   *
   * The PUT keys route, templates and delivery are independently optional:
   * an absent key leaves its setting unchanged, an empty body changes
   * nothing.
   */
  public function formSettings(Request $request, string $form): Response {
    return $this->handle($request, function () use ($request, $form): array {
      $entity = $this->requireForm($form, $request->isMethod('PUT'));
      if (!$request->isMethod('PUT')) {
        return $this->forms->settings($entity, $this->account);
      }
      $data = $this->body($request);
      $this->allowKeys($data, ['route', 'templates', 'delivery']);
      $route = array_key_exists('route', $data) ? (string) $this->string($data, 'route', TRUE, 16) : NULL;
      $templates = NULL;
      if (array_key_exists('templates', $data)) {
        $templates = $data['templates'];
        if (!is_array($templates) || !array_is_list($templates)) {
          throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'templates']);
        }
      }
      $delivery = NULL;
      if (array_key_exists('delivery', $data)) {
        $delivery = $data['delivery'];
        if (!is_array($delivery) || ($delivery !== [] && array_is_list($delivery))) {
          throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'deliveryRoutes']);
        }
      }
      return $this->forms->saveSettings($entity, $route, $templates, $this->account, $delivery);
    });
  }

  /**
   * GET /api/admin/docgen/forms/{form}/variables.
   */
  public function formVariables(Request $request, string $form): Response {
    return $this->handle($request, function () use ($form): array {
      $entity = $this->requireForm($form, FALSE);
      return [
        'formId' => $entity->uuid(),
        'title' => (string) $entity->label(),
        'variables' => $this->forms->variables($entity),
        'loopExample' => '{#antrag.<abschnitt>}{feld}{/antrag.<abschnitt>}',
      ];
    });
  }

  /**
   * GET /api/admin/docgen/forms/{form}/sample?lang=de|en.
   */
  public function formSample(Request $request, string $form): Response {
    return $this->handle($request, function () use ($request, $form): Response {
      $entity = $this->requireForm($form, FALSE);
      $query = $request->query->all();
      if (array_diff(array_keys($query), ['lang']) !== []) {
        throw new DocgenApiException('docgen.unknown_key', 400);
      }
      $language = ($query['lang'] ?? 'de') === 'en' ? 'en' : 'de';
      $this->throttle('sample');
      $sample = $this->forms->sample($entity, $language);
      return $this->downloadBytes($sample['docx'], $sample['filename'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'muster.docx');
    });
  }

  /**
   * POST /api/admin/docgen/forms/{form}/templates (upload from the editor).
   */
  public function formUpload(Request $request, string $form): Response {
    return $this->handle($request, function () use ($request, $form): array {
      $entity = $this->requireForm($form, TRUE);
      $this->throttle('upload');
      $data = $this->body($request, self::MAX_UPLOAD_BYTES);
      $this->allowKeys($data, ['name', 'purpose', 'filename', 'content', 'replaces']);
      $previous = NULL;
      $replaces = $this->string($data, 'replaces', FALSE, 36);
      if ($replaces !== NULL) {
        $previous = $this->catalog->load($replaces) ?? throw new DocgenApiException('docgen.template_not_found', 404);
      }
      $result = $this->forms->upload(
        $entity,
        $this->string($data, 'name', $previous === NULL) ?? (string) $previous?->label(),
        $this->string($data, 'purpose', $previous === NULL, 32) ?? (string) $previous?->getPurpose(),
        (string) $this->string($data, 'filename'),
        $this->content($data),
        $this->account,
        $previous,
      );
      return [
        'template' => $this->project($result['template']),
        'comparison' => $result['comparison'],
        'settings' => $this->forms->settings($entity, $this->account),
      ];
    }, 201);
  }

  /**
   * Loads a template the account may read or manage.
   */
  private function requireTemplate(string $uuid, bool $manage): DocgenTemplateInterface {
    $template = $this->catalog->load($uuid);
    $scope = $template?->getScopeGroup();
    if (!$template instanceof DocgenTemplateInterface || !$scope instanceof GroupInterface
      || (!$this->scopeAccess->canReadTemplates($scope, $this->account) && !$this->scopeAccess->canManageTemplates($scope, $this->account))) {
      throw new DocgenApiException('docgen.template_not_found', 404);
    }
    if ($manage) {
      $this->requireManage($scope);
    }
    return $template;
  }

  /**
   * Loads a form whose catalog the account may read or manage.
   */
  private function requireForm(string $uuid, bool $manage): FormSchemaInterface {
    $form = $this->forms->loadForm($uuid);
    $scope = $form instanceof FormSchemaInterface ? $this->scopeAccess->scopeOf($form) : NULL;
    if (!$form instanceof FormSchemaInterface || !$scope instanceof GroupInterface || !$form->access('view', $this->account)
      || !$this->scopeAccess->canReadTemplates($scope, $this->account)) {
      throw new DocgenApiException('docgen.form_not_found', 404);
    }
    if ($manage) {
      $this->requireManage($scope);
    }
    return $form;
  }

  /**
   * Requires read access to a scope's catalog.
   */
  private function requireRead(GroupInterface $scope): void {
    if (!$this->scopeAccess->canReadTemplates($scope, $this->account)) {
      throw new DocgenApiException('docgen.scope_not_found', 404);
    }
  }

  /**
   * Requires management rights in a scope.
   */
  private function requireManage(GroupInterface $scope): void {
    if (!$this->scopeAccess->canManageTemplates($scope, $this->account)) {
      throw new DocgenApiException('docgen.access_denied', 403);
    }
  }

  /**
   * Staff projection of a template version.
   */
  private function project(DocgenTemplateInterface $template): array {
    $inspection = $template->getInspection();
    $scope = $template->getScopeGroup();
    $creator = $template->get('uid')->entity;
    $releaser = $template->get('released_by')->entity;
    return [
      'id' => $template->uuid(),
      'family' => $template->getFamily(),
      'version' => $template->getVersion(),
      'name' => (string) $template->label(),
      'purpose' => $template->getPurpose(),
      'closesProcedure' => in_array($template->getPurpose(), DocgenTemplateInterface::CLOSING_PURPOSES, TRUE),
      'status' => $template->getStatus(),
      'scope' => $scope instanceof GroupInterface ? FormTemplates::scopeProjection($scope) : NULL,
      'filename' => (string) $template->get('filename')->value,
      'size' => (int) $template->get('size')->value,
      'sha256' => $template->getSha256(),
      'placeholders' => $inspection['placeholders'],
      'loops' => $inspection['loops'],
      'errors' => $inspection['errors'],
      'systemPlaceholders' => array_values(array_intersect($inspection['placeholders'], array_keys(VariableCatalog::SYSTEM))),
      'createdAt' => (int) $template->get('created')->value,
      'createdBy' => $creator instanceof UserInterface ? (string) $creator->getDisplayName() : NULL,
      'releasedAt' => $template->get('released')->isEmpty() ? NULL : (int) $template->get('released')->value,
      'releasedBy' => $releaser instanceof UserInterface ? (string) $releaser->getDisplayName() : NULL,
      'downloadUrl' => '/api/admin/docgen/templates/' . $template->uuid() . '/file',
    ];
  }

}
