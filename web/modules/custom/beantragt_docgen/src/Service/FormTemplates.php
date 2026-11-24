<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Service\Postbox\PostboxTransportInterface;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Decision templates seen from the form editor.
 *
 * Decision route and allowed templates per form, the placeholder catalog of
 * the form, the sample template download and the upload with comparison.
 * Unknown placeholders are warnings, never a hard block (MVP, same pattern as
 * the privacy classification).
 */
class FormTemplates {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DocgenSettingsRepository $settings,
    private readonly TemplateCatalog $catalog,
    private readonly DocgenClient $client,
    private readonly DocgenScopeAccess $scopeAccess,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?PostboxTransportInterface $postbox = NULL,
  ) {}

  /**
   * Loads a form schema by UUID.
   */
  public function loadForm(string $uuid): ?FormSchemaInterface {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
      return NULL;
    }
    $forms = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => strtolower($uuid)]);
    $form = reset($forms);
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * The scope group whose catalog serves the form: organisation first.
   */
  public function scope(FormSchemaInterface $form): GroupInterface {
    return $this->scopeAccess->scopeOf($form) ?? throw new DocgenApiException('docgen.scope_not_found', 404);
  }

  /**
   * Placeholder catalog of the form's current version.
   *
   * @return array<int, array<string, mixed>>
   *   Variables (VariableCatalog::build()).
   */
  public function variables(FormSchemaInterface $form): array {
    $schema = json_decode((string) $form->getSchema(), TRUE);
    $schema = is_array($schema) ? $schema : [];
    $protected = $this->dataProtection?->protectedFieldIds($schema) ?? [];
    return VariableCatalog::build($schema, $protected);
  }

  /**
   * Decision route, allowed templates and warnings of a form.
   *
   * Each template carries its newest version (draft or released), so the
   * form editor can replace and release it without reading the workspace
   * catalog; canManage mirrors DocgenScopeAccess::canManageTemplates() for
   * the form's scope.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person reading the settings.
   *
   * @return array<string, mixed>
   *   Settings projection.
   */
  public function settings(FormSchemaInterface $form, AccountInterface $account): array {
    $settings = $this->settings->formSettings((string) $form->uuid());
    $variables = $this->variables($form);
    $scope = $this->scope($form);
    $templates = [];
    $warnings = [];
    foreach ($settings['templates'] as $family) {
      $latest = $this->catalog->latestVersion($family);
      if (!$latest instanceof DocgenTemplateInterface || (int) $latest->getScopeGroup()?->id() !== (int) $scope->id()) {
        $warnings[] = ['code' => 'docgen.template_missing', 'family' => $family];
        continue;
      }
      $released = $this->catalog->latestReleased($family);
      $comparison = VariableCatalog::compare($released?->getInspection() ?? $latest->getInspection(), $variables);
      $templates[] = [
        'family' => $family,
        'name' => (string) $latest->label(),
        'purpose' => $latest->getPurpose(),
        'latestVersion' => $latest->getVersion(),
        'latestId' => $latest->uuid(),
        'latestStatus' => $latest->getStatus(),
        'latestHasErrors' => $latest->getInspection()['errors'] !== [],
        'releasedVersion' => $released?->getVersion(),
        'releasedId' => $released?->uuid(),
        'comparison' => $comparison,
      ];
      if ($released === NULL) {
        $warnings[] = ['code' => 'docgen.template_not_released', 'family' => $family];
      }
      if ($comparison['unknown'] !== []) {
        $warnings[] = [
          'code' => 'docgen.placeholders_unknown',
          'family' => $family,
          'placeholders' => $comparison['unknown'],
        ];
      }
    }
    if ($settings['route'] === DocgenSettingsRepository::ROUTE_TEMPLATE && $templates === []) {
      $warnings[] = ['code' => 'docgen.no_template_assigned'];
    }
    return [
      'formId' => $form->uuid(),
      'route' => $settings['route'],
      'routes' => DocgenSettingsRepository::ROUTES,
      'templates' => $templates,
      'warnings' => $warnings,
      'scope' => self::scopeProjection($scope),
      'canManage' => $this->scopeAccess->canManageTemplates($scope, $account),
      'delivery' => [
        'configured' => $settings['delivery']['routes'] !== [],
        'routes' => $settings['delivery']['routes'],
        'default' => $settings['delivery']['default'],
        'availableRoutes' => $this->availableRoutes($scope),
      ],
    ];
  }

  /**
   * Delivery routes that work today for a scope.
   *
   * Every route but bundid while no BundID postbox transport is available.
   *
   * @return string[]
   *   Routes in DeliveryRoute::ROUTES order.
   */
  public function availableRoutes(?GroupInterface $scope): array {
    $postbox = $this->postbox?->available($scope) ?? FALSE;
    $routes = [];
    foreach (DeliveryRoute::ROUTES as $route) {
      if ($postbox || $route !== DeliveryRoute::BUNDID) {
        $routes[] = $route;
      }
    }
    return $routes;
  }

  /**
   * The public delivery offer of a form (computed field_delivery_offer).
   *
   * Only stored routes that are available today; without any, the form is
   * not configured. A stored default that became unavailable falls back to
   * postal, else to the first offered route.
   *
   * @return array{configured: bool, routes?: string[], default?: string}
   *   {"configured": false} or the offer.
   */
  public function deliveryOffer(FormSchemaInterface $form): array {
    $uuid = (string) $form->uuid();
    $stored = $uuid === '' ? ['routes' => [], 'default' => ''] : $this->settings->formSettings($uuid)['delivery'];
    $routes = array_values(array_intersect($stored['routes'], $this->availableRoutes($this->scopeAccess->scopeOf($form))));
    if ($routes === []) {
      return ['configured' => FALSE];
    }
    $default = match (TRUE) {
      in_array($stored['default'], $routes, TRUE) => $stored['default'],
      in_array(DeliveryRoute::POSTAL, $routes, TRUE) => DeliveryRoute::POSTAL,
      default => $routes[0],
    };
    return ['configured' => TRUE, 'routes' => $routes, 'default' => $default];
  }

  /**
   * Projects a scope group for the staff API.
   *
   * @return array{id: int, type: string, name: string}
   *   Group ID, organisation or jurisdiction, and its name.
   */
  public static function scopeProjection(GroupInterface $scope): array {
    return [
      'id' => (int) $scope->id(),
      'type' => $scope->bundle() === 'org' ? 'organisation' : 'jurisdiction',
      'name' => (string) $scope->label(),
    ];
  }

  /**
   * Stores the given settings; NULL leaves a setting unchanged.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param string|null $route
   *   One of none, template or case_system, or NULL.
   * @param array<int, mixed>|null $families
   *   Template family UUIDs of the form's catalog (unvalidated input), or
   *   NULL.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person saving.
   * @param array<string, mixed>|null $delivery
   *   {"routes": [...], "default": "..."} (unvalidated input), or NULL.
   *
   * @return array<string, mixed>
   *   The stored settings projection.
   */
  public function saveSettings(FormSchemaInterface $form, ?string $route, ?array $families, AccountInterface $account, ?array $delivery = NULL): array {
    if ($route !== NULL && !in_array($route, DocgenSettingsRepository::ROUTES, TRUE)) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'route']);
    }
    $scope = $this->scope($form);
    $clean = NULL;
    if ($families !== NULL) {
      $clean = [];
      foreach ($families as $family) {
        if (!is_string($family) || !preg_match('/^[0-9a-f-]{36}$/', $family)) {
          throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'templates']);
        }
        $latest = $this->catalog->latestVersion($family);
        if (!$latest instanceof DocgenTemplateInterface || (int) $latest->getScopeGroup()?->id() !== (int) $scope->id()) {
          throw new DocgenApiException('docgen.template_not_found', 404, ['family' => $family]);
        }
        $clean[] = $family;
      }
      if (count($clean) > 50) {
        throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'templates']);
      }
    }
    $offer = $delivery === NULL ? NULL : $this->validDelivery($delivery, $this->availableRoutes($scope));
    if ($route !== NULL || $clean !== NULL || $offer !== NULL) {
      $this->settings->saveFormSettings((string) $form->uuid(), $route, $clean, (int) $account->id(), $offer);
    }
    return $this->settings($form, $account);
  }

  /**
   * Validates the delivery offer of a PUT body.
   *
   * Valid: no routes and an empty default (not configured), or a unique
   * subset of the routes with at least one available route and an available
   * default among them. So bundid is never the default while no postbox is
   * connected, and an offer of only bundid is refused.
   *
   * @param array<string, mixed> $delivery
   *   Unvalidated input.
   * @param string[] $available
   *   Routes available for the form's scope.
   *
   * @return array{routes: string[], default: string}
   *   The offer.
   */
  private function validDelivery(array $delivery, array $available): array {
    $routes = $delivery['routes'] ?? NULL;
    if (array_diff(array_keys($delivery), ['routes', 'default']) !== [] || !is_array($routes) || !array_is_list($routes)
      || count($routes) > count(DeliveryRoute::ROUTES)) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'deliveryRoutes']);
    }
    foreach ($routes as $route) {
      if (!is_string($route) || !in_array($route, DeliveryRoute::ROUTES, TRUE)) {
        throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'deliveryRoutes']);
      }
    }
    if (count(array_unique($routes)) !== count($routes) || ($routes !== [] && array_intersect($routes, $available) === [])) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'deliveryRoutes']);
    }
    $default = $delivery['default'] ?? NULL;
    if (!is_string($default) || ($routes === [] ? $default !== '' : !in_array($default, $routes, TRUE) || !in_array($default, $available, TRUE))) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'deliveryDefault']);
    }
    return ['routes' => $routes, 'default' => $default];
  }

  /**
   * Uploads a template from the form editor into the form's catalog.
   *
   * @return array{template: \Drupal\beantragt_docgen\Entity\DocgenTemplateInterface, comparison: array<string, mixed>}
   *   The new draft version and its comparison with the form catalog.
   */
  public function upload(FormSchemaInterface $form, string $label, string $purpose, string $filename, string $bytes, AccountInterface $account, ?DocgenTemplateInterface $previous = NULL): array {
    $scope = $this->scope($form);
    if ($previous instanceof DocgenTemplateInterface && (int) $previous->getScopeGroup()?->id() !== (int) $scope->id()) {
      throw new DocgenApiException('docgen.template_not_found', 404);
    }
    $template = $this->catalog->upload($scope, $label, $purpose, $filename, $bytes, $account, $previous);
    $this->settings->allowTemplate((string) $form->uuid(), $template->getFamily(), (int) $account->id());
    return [
      'template' => $template,
      'comparison' => VariableCatalog::compare($template->getInspection(), $this->variables($form)),
    ];
  }

  /**
   * Builds the sample template of a form through docgen /render.
   *
   * The base template carries a table loop and a sample letter; the values
   * are the placeholder texts themselves, which docxtemplater inserts as
   * plain text. No fourth docgen route is needed.
   *
   * @return array{docx: string, filename: string}
   *   DOCX bytes and a download filename.
   */
  public function sample(FormSchemaInterface $form, string $language): array {
    $en = $language === 'en';
    $variables = $this->variables($form);
    $rows = [];
    foreach ($variables as $variable) {
      $label = self::plain($variable['label'][$en ? 'en' : 'de'] ?? '');
      $hints = [];
      if ($variable['protected'] === TRUE) {
        $hints[] = $en ? 'protected' : 'geschützt';
      }
      if ($variable['repeatable'] === TRUE) {
        $hints[] = $en ? 'repeatable, fields below' : 'wiederholbar, Felder darunter';
      }
      $rows[] = ['label' => $label, 'syntax' => $variable['syntax'], 'hinweis' => implode(', ', $hints)];
      foreach ($variable['children'] ?? [] as $child) {
        $rows[] = [
          'label' => '  ' . self::plain($child['label'][$en ? 'en' : 'de'] ?? ''),
          'syntax' => $child['syntax'],
          'hinweis' => ($en ? 'inside ' : 'innerhalb ') . '{#' . $variable['tag'] . '}' . ($child['protected'] ? ($en ? ', protected' : ', geschützt') : ''),
        ];
      }
    }
    // The sample letter shows a few plain values; checkboxes and uploads read
    // badly in a sentence.
    $form_fields = array_values(array_filter($variables, static fn (array $variable): bool => $variable['group'] === 'form'
      && $variable['protected'] === FALSE && $variable['repeatable'] === FALSE
      && in_array($variable['type'], ['text', 'textarea', 'date', 'select', 'radio', 'number'], TRUE)));
    $letter = [
      '{organisation.name}',
      '{organisation.anschrift}',
      '',
      ($en ? 'Your application of {vorgang.eingang}, reference {vorgang.aktenzeichen}' : 'Ihr Antrag vom {vorgang.eingang}, Aktenzeichen {vorgang.aktenzeichen}'),
      ($en ? 'Date: {heute}' : 'Datum: {heute}'),
      '',
      ($en ? 'Dear Sir or Madam,' : 'Sehr geehrte Damen und Herren,'),
      ($en ? 'we have decided on your application as follows.' : 'über Ihren Antrag haben wir wie folgt entschieden.'),
    ];
    foreach (array_slice($form_fields, 0, 3) as $field) {
      $letter[] = self::plain($field['label'][$en ? 'en' : 'de'] ?? '') . ': ' . $field['syntax'];
    }
    $letter[] = '';
    $letter[] = $en ? 'Yours faithfully' : 'Mit freundlichen Grüßen';
    $letter[] = '{bearbeitung.name}';
    $title = self::plain((string) $form->label());
    $data = [
      'titel' => ($en ? 'Placeholders for ' : 'Platzhalter für ') . $title,
      'hinweis' => $en
        ? 'Copy the placeholders into your Word template. Placeholders in braces are filled from the application; delete this table before you upload the template.'
        : 'Kopieren Sie die Platzhalter in Ihre Word-Vorlage. Platzhalter in geschweiften Klammern werden aus dem Antrag gefüllt; löschen Sie diese Tabelle, bevor Sie die Vorlage hochladen.',
      'kopf_label' => $en ? 'Label' : 'Bezeichnung',
      'kopf_platzhalter' => $en ? 'Placeholder' : 'Platzhalter',
      'kopf_hinweis' => $en ? 'Note' : 'Hinweis',
      'variablen' => $rows,
      'beispiel_titel' => $en ? 'Sample letter' : 'Beispielbrief',
      'brief' => array_map(static fn (string $line): array => ['zeile' => $line], $letter),
    ];
    $rendered = $this->client->render(self::baseTemplate(), $data);
    return [
      'docx' => $rendered['docx'],
      'filename' => TemplateCatalog::safeFilename(($en ? 'placeholders ' : 'Platzhalter ') . $title, 'muster.docx'),
    ];
  }

  /**
   * The base template of the sample download.
   */
  public static function baseTemplate(): string {
    return DocxWriter::build([
      ['text' => '{titel}', 'bold' => TRUE, 'size' => 32],
      '{hinweis}',
      [
        'table' => [
          ['{kopf_label}', '{kopf_platzhalter}', '{kopf_hinweis}'],
          ['{#variablen}{label}', '{syntax}', '{hinweis}{/variablen}'],
        ],
        'widths' => [3600, 3400, 2000],
      ],
      ['text' => '{beispiel_titel}', 'bold' => TRUE, 'size' => 28],
      '{#brief}',
      '{zeile}',
      '{/brief}',
    ]);
  }

  /**
   * Removes braces from labels so they never become tags.
   */
  private static function plain(string $text): string {
    return trim(str_replace(['{', '}'], ['(', ')'], $text));
  }

}
