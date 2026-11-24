<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use Psr\Log\LoggerInterface;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

/**
 * Validates payloads offline without exposing their values in diagnostics.
 */
final class DataContractValidator {

  private const MAX_ERRORS = 50;

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly DataContractRegistry $registry,
    private readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Returns bounded JSON-pointer/keyword/code errors, or an empty list.
   */
  public function validate(array|object $data, string $key, string $version): array {
    $schema = $this->registry->load($key, $version);
    try {
      $schema_object = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), FALSE, 128, JSON_THROW_ON_ERROR);
      // No protocols, prefixes, directories or files are registered.
      // Unknown references fail without filesystem or network IO.
      $resolver = new SchemaResolver();
      $resolver->registerRaw($schema_object, $schema['$id']);
      $validator = new Validator(new SchemaLoader(NULL, $resolver), self::MAX_ERRORS, FALSE);
      // Preserve JSON arrays and objects, including explicit empty objects.
      $payload = json_decode(json_encode($data, JSON_THROW_ON_ERROR), FALSE, 128, JSON_THROW_ON_ERROR);
      $error = $validator->validate($payload, $schema['$id'])->error();
      $errors = [];
      if ($error !== NULL) {
        $this->collect($error, $errors);
      }
      return $errors;
    }
    catch (UnresolvedReferenceException $exception) {
      $this->logger?->error('Contract reference resolution failed ({class}).', ['class' => $exception::class]);
      return [['pointer' => '', 'keyword' => '$ref', 'code' => 'integration.contract_reference_unresolved']];
    }
    catch (\Throwable $exception) {
      // Log the class only, never messages, values, URIs or traces.
      $this->logger?->error('Contract validation failed ({class}).', ['class' => $exception::class]);
      return [['pointer' => '', 'keyword' => NULL, 'code' => 'integration.contract_validation_failed']];
    }
  }

  /**
   * Collects leaf errors without messages or arguments containing data.
   */
  private function collect(ValidationError $error, array &$errors): void {
    if (count($errors) >= self::MAX_ERRORS) {
      return;
    }
    if ($error->subErrors() !== []) {
      foreach ($error->subErrors() as $child) {
        $this->collect($child, $errors);
      }
      return;
    }
    $parts = array_map(static fn($part): string => str_replace(['~', '/'], ['~0', '~1'], (string) $part), $error->data()->fullPath());
    $pointer = $parts === [] ? '' : '/' . implode('/', $parts);
    $keyword = $error->keyword();
    $code = TransferFailures::validationCode($keyword);
    // Required arguments are schema field names, never supplied payload values.
    $missing = $keyword === 'required' ? ($error->args()['missing'] ?? []) : [];
    foreach ($missing ?: [NULL] as $name) {
      $errors[] = [
        'pointer' => $name === NULL ? $pointer : $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name),
        'keyword' => $keyword,
        'code' => $code,
      ];
      if (count($errors) >= self::MAX_ERRORS) {
        return;
      }
    }
  }

}
