<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Docx;

/**
 * What the cover sheet shows: literal text, never placeholders.
 *
 * No name, address, birth date, amount or URL. The proof lines carry only
 * the label of a requested proof, once per field, with the number of people
 * when several share it.
 */
final class CoverSheetContent {

  /**
   * Constructs the content.
   *
   * @param string $referenceNumber
   *   Reference number of the application.
   * @param string $formLabel
   *   Label of the form.
   * @param string[] $lines
   *   Proof lines without the leading dash, see proofLines().
   * @param \DateTimeImmutable|null $deadline
   *   Deadline of the request, NULL without one.
   * @param string $groupedCode
   *   Identifier in groups (CoverCode::group()).
   * @param string $png
   *   QR PNG bytes (QrPng::render()).
   */
  public function __construct(
    public readonly string $referenceNumber,
    public readonly string $formLabel,
    public readonly array $lines,
    public readonly ?\DateTimeImmutable $deadline,
    public readonly string $groupedCode,
    public readonly string $png,
  ) {}

  /**
   * Builds the proof lines from requested proofs in stored order.
   *
   * @param array<int, mixed> $requested
   *   Entries {key, fieldId, personBlock, label, reason, criteria[]}; stored
   *   JSON, read defensively.
   *
   * @return string[]
   *   One line per fieldId: the label, plus " für N Personen" when N > 1
   *   entries share the fieldId.
   */
  public static function proofLines(array $requested): array {
    $order = [];
    foreach ($requested as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $field = (string) ($entry['fieldId'] ?? '');
      $label = trim((string) ($entry['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $id = $field !== '' ? $field : 'label:' . $label;
      if (!isset($order[$id])) {
        $order[$id] = ['label' => $label, 'count' => 0];
      }
      $order[$id]['count']++;
    }
    $lines = [];
    foreach ($order as $item) {
      $lines[] = $item['label'] . ($item['count'] > 1 ? ' für ' . $item['count'] . ' Personen' : '');
    }
    return $lines;
  }

  /**
   * Makes a text literal for docxtemplater and escapes it for XML.
   *
   * Braces would be read as placeholders: { becomes (, } becomes ).
   */
  public static function literal(string $text): string {
    return htmlspecialchars(strtr($text, ['{' => '(', '}' => ')']), ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

}
