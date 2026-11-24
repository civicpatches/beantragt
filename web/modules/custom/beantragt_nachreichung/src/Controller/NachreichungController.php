<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_nachreichung\Exception\NachreichungException;
use Drupal\beantragt_nachreichung\Service\CoverPrintService;
use Drupal\beantragt_nachreichung\Service\NachreichungActivation;
use Drupal\beantragt_nachreichung\Service\NachreichungCard;
use Drupal\beantragt_nachreichung\Service\NachreichungEntries;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Staff routes of the card "Nachreichungen" (internal, Nuxt proxy only).
 *
 * The route requirements are the protected-data gate (application view,
 * tenant administration, "view protected application data") and, for POST,
 * the CSRF header token. Answers use the {data} and {error: {code,
 * details}} envelope; nothing here carries an identifier.
 */
final class NachreichungController implements ContainerInjectionInterface {

  /**
   * Prints per person and hour.
   */
  public const PRINT_LIMIT = 10;
  public const PRINT_WINDOW = 3600;
  private const MAX_BODY_BYTES = 4096;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly NachreichungCard $card,
    private readonly NachreichungActivation $activation,
    private readonly CoverPrintService $print,
    private readonly ProtectedAccessLog $audit,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
    private readonly ?NachreichungEntries $entries = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('beantragt_nachreichung.card'),
      $container->get('beantragt_nachreichung.activation'),
      $container->get('beantragt_nachreichung.cover_print'),
      $container->get('beantragt_application.protected_access_log'),
      $container->get('flood'),
      $container->get('logger.channel.beantragt_nachreichung'),
      $container->get('beantragt_nachreichung.entries'),
    );
  }

  /**
   * GET /api/admin/applications/{application}/nachreichung.
   */
  public function card(Request $request, ApplicationInterface $application): Response {
    return $this->handle(function () use ($request, $application): array {
      $this->noQuery($request);
      $this->activation->reconcile((int) $application->id());
      try {
        $this->audit->record($application, $this->account, 'nachreichung_card');
      }
      catch (\Throwable $exception) {
        $this->logger->error('Nachreichung card read could not be audited: @class.', ['@class' => $exception::class]);
        throw new NachreichungException('nachreichung.audit_unavailable', 503);
      }
      return $this->card->build($application, $this->account);
    });
  }

  /**
   * POST /api/admin/applications/{application}/cover-sheet.
   */
  public function printSheet(Request $request, ApplicationInterface $application): Response {
    return $this->handle(function () use ($request, $application): Response {
      $this->noQuery($request);
      $data = $this->body($request);
      if ($data !== []) {
        throw new NachreichungException('nachreichung.input_invalid', 400);
      }
      $event = 'beantragt_nachreichung.cover_print';
      $identifier = 'uid:' . $this->account->id();
      if (!$this->flood->isAllowed($event, self::PRINT_LIMIT, self::PRINT_WINDOW, $identifier)) {
        throw new NachreichungException('nachreichung.rate_limited', 429, [], self::PRINT_WINDOW);
      }
      $this->flood->register($event, self::PRINT_WINDOW, $identifier);
      $printed = $this->print->print($application, $this->account);
      $response = new Response($printed['pdf'], 200, [
        'Content-Type' => 'application/pdf',
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
      ]);
      $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $printed['filename'], 'deckblatt.pdf'));
      return $response;
    });
  }

  /**
   * POST /api/admin/applications/{application}/cover-sheets/{cover}/revoke.
   */
  public function revoke(Request $request, ApplicationInterface $application, string $cover): Response {
    return $this->handle(function () use ($request, $application, $cover): array {
      $this->noQuery($request);
      if (!$application->access('update', $this->account)) {
        throw new NachreichungException('nachreichung.access_denied', 403);
      }
      if (!Uuid::isValid($cover)) {
        throw new NachreichungException('nachreichung.input_invalid', 400);
      }
      $data = $this->body($request);
      if (array_keys($data) !== ['reason'] || !is_string($data['reason'])) {
        throw new NachreichungException('nachreichung.input_invalid', 400);
      }
      $this->print->revoke($application, $cover, $data['reason'], $this->account);
      return $this->card->build($application, $this->account);
    });
  }

  /**
   * POST .../nachforderung/{request}/deadline: "Frist ... verlängern" (3.12).
   */
  public function extendDeadline(Request $request, ApplicationInterface $application, string $nachforderung): Response {
    return $this->handle(function () use ($request, $application, $nachforderung): array {
      $this->noQuery($request);
      if (!$application->access('update', $this->account)) {
        throw new NachreichungException('nachreichung.access_denied', 403);
      }
      $this->entriesService()->extendDeadline($application, $nachforderung, $this->body($request), $this->account);
      return $this->auditedCard($application);
    });
  }

  /**
   * POST .../nachreichung/{entry}/remove: "Vom Antrag entfernen" (3.13).
   */
  public function remove(Request $request, ApplicationInterface $application, string $entry): Response {
    return $this->handle(function () use ($request, $application, $entry): array {
      $this->noQuery($request);
      $this->body($request);
      $this->entriesService()->revert($application, $entry, 'remove', $this->account);
      return $this->auditedCard($application);
    });
  }

  /**
   * POST .../nachreichung/{entry}/reassign: "Neu zuordnen" (3.13).
   */
  public function reassign(Request $request, ApplicationInterface $application, string $entry): Response {
    return $this->handle(function () use ($request, $application, $entry): array {
      $this->noQuery($request);
      $this->body($request);
      $this->entriesService()->revert($application, $entry, 'reassign', $this->account);
      return $this->auditedCard($application);
    });
  }

  /**
   * The card after a change, audited like the card read.
   */
  private function auditedCard(ApplicationInterface $application): array {
    try {
      $this->audit->record($application, $this->account, 'nachreichung_card');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Nachreichung card read could not be audited: @class.', ['@class' => $exception::class]);
      throw new NachreichungException('nachreichung.audit_unavailable', 503);
    }
    return $this->card->build($application, $this->account);
  }

  /**
   * The entries service, required for the step 2 routes.
   */
  private function entriesService(): NachreichungEntries {
    if ($this->entries === NULL) {
      throw new NachreichungException('nachreichung.internal_error', 500);
    }
    return $this->entries;
  }

  /**
   * Runs an operation and maps every failure to the error envelope.
   */
  private function handle(callable $operation): Response {
    try {
      $result = $operation();
      return $result instanceof Response ? $result : $this->json(['data' => $result]);
    }
    catch (NachreichungException $exception) {
      $details = $exception->details;
      $headers = [];
      if ($exception->retryAfter !== NULL) {
        $details['retryAfter'] = $exception->retryAfter;
        $headers['Retry-After'] = (string) $exception->retryAfter;
      }
      return $this->json(['error' => ['code' => $exception->errorCode, 'details' => (object) $details]], $exception->status, $headers);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Nachreichung staff API failed: @class @message', [
        '@class' => $exception::class,
        '@message' => $exception instanceof DatabaseException ? '(database error, details withheld)' : mb_substr(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '', 0, 300),
      ]);
      return $this->json(['error' => ['code' => 'nachreichung.internal_error', 'details' => (object) []]], 500);
    }
  }

  /**
   * Refuses any query key.
   */
  private function noQuery(Request $request): void {
    if ($request->query->all() !== []) {
      throw new NachreichungException('nachreichung.input_invalid', 400);
    }
  }

  /**
   * Reads an optional {"data": {...}} body.
   *
   * @return array<string, mixed>
   *   The data object, [] for an empty body.
   */
  private function body(Request $request): array {
    $raw = (string) $request->getContent();
    if (strlen($raw) > self::MAX_BODY_BYTES) {
      throw new NachreichungException('nachreichung.input_invalid', 413);
    }
    if (trim($raw) === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new NachreichungException('nachreichung.input_invalid', 400);
    }
    if (!is_array($decoded) || array_keys($decoded) !== ['data'] || !is_array($decoded['data']) || ($decoded['data'] !== [] && array_is_list($decoded['data']))) {
      throw new NachreichungException('nachreichung.input_invalid', 400);
    }
    return $decoded['data'];
  }

  /**
   * Emits the private JSON envelope.
   */
  private function json(array $data, int $status = 200, array $headers = []): JsonResponse {
    return new JsonResponse($data, $status, $headers + [
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
