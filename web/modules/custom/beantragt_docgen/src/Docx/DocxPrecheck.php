<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Docx;

use Drupal\beantragt_docgen\Exception\DocgenApiException;

/**
 * Check of uploaded Word files before they are stored or served.
 *
 * Templates and revised drafts are stored as uploaded and served to staff
 * for printing, so this check covers everything that makes Word load or run
 * outside content, at the level of docgen's guard (services/docgen,
 * docx-guard.js): size, ZIP structure, entry and expansion limits, macro and
 * template content types, VBA, ActiveX, OLE and other embeddings, altChunks,
 * forbidden relationship types (attachedTemplate, subDocument, frame,
 * package, control) in every .rels part, DTDs, and field codes that load
 * outside content (DDE, DDEAUTO, INCLUDETEXT, INCLUDEPICTURE, INCLUDE, LINK,
 * IMPORT, DATABASE, RD) in every XML part, simple and complex fields alike.
 *
 * XML parts together may hold at most MAX_XML_BYTES (the same budget as
 * docgen's maxXmlBytes): a part is measured before it is read in full and
 * before any DOM is built, so a small ZIP with tens of MB of XML never
 * reaches DOMDocument in the PHP process.
 *
 * Unlike docgen, which strips external relationships from what it renders,
 * Drupal never rewrites an upload: a file with an external relationship is
 * rejected. Only hyperlinks are accepted, because Word follows them on a
 * click, never on opening.
 */
class DocxPrecheck {

  public const MAX_BYTES = 5 * 1024 * 1024;
  public const MAX_ENTRIES = 2000;
  public const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
  public const MAX_RATIO = 100;
  /**
   * Sum of all XML parts, as docgen's DOCGEN_MAX_XML_BYTES default.
   */
  public const MAX_XML_BYTES = 4 * 1024 * 1024;
  public const DOCX_MAIN_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

  private const PACKAGE_RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
  private const WORD_NAMESPACES = [
    'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'http://purl.oclc.org/ooxml/wordprocessingml/main',
  ];
  private const FORBIDDEN_CONTENT_TYPE = '/macroEnabled|vbaProject|vbaData|ms-office\.vba|activeX|oleObject|template\.main\+xml|wordprocessingml\.template/i';
  private const FORBIDDEN_RELATIONSHIP_TYPE = '#/(activeXControl|activeXControlBinary|control|vbaProject|attachedTemplate|subDocument|frame)$#i';
  private const FORBIDDEN_FIELD_TYPES = [
    'DDE',
    'DDEAUTO',
    'INCLUDETEXT',
    'INCLUDEPICTURE',
    'INCLUDE',
    'LINK',
    'IMPORT',
    'DATABASE',
    'RD',
  ];

  /**
   * Validates DOCX bytes or throws a docgen validation error.
   */
  public static function assertDocx(string $bytes): void {
    if (strlen($bytes) > self::MAX_BYTES) {
      throw new DocgenApiException('docgen.docx_too_large', 413, ['maxBytes' => self::MAX_BYTES]);
    }
    if (strlen($bytes) < 22 || !str_starts_with($bytes, "PK\x03\x04")) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'not_zip']);
    }
    if (!static::zipAvailable()) {
      throw new DocgenApiException('docgen.validation_unavailable', 503, ['reason' => 'structural_validation_unavailable']);
    }
    $path = tempnam(sys_get_temp_dir(), 'docgen');
    if ($path === FALSE) {
      throw new DocgenApiException('docgen.storage_unavailable', 503);
    }
    try {
      file_put_contents($path, $bytes);
      $zip = new \ZipArchive();
      if ($zip->open($path, \ZipArchive::RDONLY) !== TRUE) {
        throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'not_zip']);
      }
      try {
        self::assertEntries($zip);
      }
      finally {
        $zip->close();
      }
    }
    finally {
      @unlink($path);
    }
  }

  /**
   * Whether structural validation is available (overridable by tests).
   */
  protected static function zipAvailable(): bool {
    return class_exists(\ZipArchive::class);
  }

  /**
   * Checks the central directory and the content types.
   */
  private static function assertEntries(\ZipArchive $zip): void {
    $count = $zip->count();
    if ($count < 1 || $count > self::MAX_ENTRIES) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'entry_count']);
    }
    $total = 0;
    $names = [];
    for ($index = 0; $index < $count; $index++) {
      $stat = $zip->statIndex($index);
      if (!is_array($stat)) {
        throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'entry_unreadable']);
      }
      $name = (string) $stat['name'];
      $size = (int) $stat['size'];
      $compressed = max(1, (int) $stat['comp_size']);
      if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
        throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'entry_name']);
      }
      $total += $size;
      if ($total > self::MAX_UNCOMPRESSED_BYTES || ($size > 1024 * 1024 && $size / $compressed > self::MAX_RATIO)) {
        throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'expansion']);
      }
      $lower = strtolower($name);
      if (str_ends_with($lower, 'vbaproject.bin') || str_ends_with($lower, 'vbadata.xml')) {
        throw new DocgenApiException('docgen.docx_macro', 422);
      }
      if (str_starts_with($lower, 'word/activex/') || str_starts_with($lower, 'word/embeddings/')) {
        throw new DocgenApiException('docgen.docx_forbidden_content', 422, ['reason' => 'embedded_object']);
      }
      $names[$name] = TRUE;
    }
    if (!isset($names['[Content_Types].xml'], $names['word/document.xml'])) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'not_docx']);
    }
    $types = $zip->getFromName('[Content_Types].xml', 1024 * 1024);
    if (!is_string($types) || !str_contains($types, self::DOCX_MAIN_TYPE)) {
      // .docm, .dotx and .dotm use other main content types.
      throw new DocgenApiException('docgen.docx_macro', 422, ['reason' => 'content_type']);
    }
    if (stripos($types, 'macroEnabled') !== FALSE || stripos($types, 'vbaProject') !== FALSE) {
      throw new DocgenApiException('docgen.docx_macro', 422);
    }
    if (preg_match(self::FORBIDDEN_CONTENT_TYPE, $types)) {
      throw new DocgenApiException('docgen.docx_macro', 422, ['reason' => 'content_type']);
    }
    self::assertParts($zip, array_keys($names));
  }

  /**
   * Checks every XML part: DTDs, relationships and field codes.
   *
   * @param \ZipArchive $zip
   *   The open archive.
   * @param string[] $names
   *   Entry names of the archive.
   */
  private static function assertParts(\ZipArchive $zip, array $names): void {
    $budget = self::MAX_XML_BYTES;
    foreach ($names as $name) {
      $lower = strtolower($name);
      if (!str_ends_with($lower, '.xml') && !str_ends_with($lower, '.rels')) {
        // Other names count as XML when their first bytes look like XML.
        $head = $zip->getFromName($name, 512);
        if (!is_string($head) || !self::isXmlPart($name, $head)) {
          continue;
        }
      }
      // Measured against the remaining budget before the part is read in
      // full; the read itself never takes more than the budget allows, even
      // when the declared size is wrong.
      $stat = $zip->statName($name);
      if (is_array($stat) && (int) $stat['size'] > $budget) {
        throw self::xmlTooLarge($name);
      }
      $xml = $zip->getFromName($name, $budget + 1);
      if (!is_string($xml)) {
        continue;
      }
      if (strlen($xml) > $budget) {
        throw self::xmlTooLarge($name);
      }
      $budget -= strlen($xml);
      if (self::isUtf16($xml)) {
        throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'encoding', 'part' => $name]);
      }
      if (stripos($xml, '<!DOCTYPE') !== FALSE) {
        throw self::forbidden('dtd', $name);
      }
      if (str_ends_with(strtolower($name), '.rels')) {
        self::assertRelationships($name, $xml);
        continue;
      }
      if (stripos($xml, 'instr') !== FALSE) {
        self::assertFieldCodes($name, $xml);
      }
    }
  }

  /**
   * Rejects forbidden relationship types and external targets.
   */
  private static function assertRelationships(string $name, string $xml): void {
    $document = self::parse($name, $xml);
    $relationships = $document->getElementsByTagNameNS(self::PACKAGE_RELATIONSHIP_NS, 'Relationship');
    foreach ($relationships as $relationship) {
      $type = $relationship->getAttribute('Type');
      if (preg_match('#/aFChunk$#i', $type) || preg_match('#/(oleObject|package)$#i', $type)) {
        throw self::forbidden('altchunk_or_ole', $name);
      }
      if (preg_match(self::FORBIDDEN_RELATIONSHIP_TYPE, $type)) {
        throw self::forbidden('relationship_type', $name);
      }
      if (strtolower($relationship->getAttribute('TargetMode')) === 'external' && !preg_match('#/hyperlink$#i', $type)) {
        throw self::forbidden('external_relationship', $name);
      }
    }
  }

  /**
   * Rejects fields that load outside content.
   *
   * Namespace-aware (any prefix, Transitional and Strict), covers simple
   * fields (w:fldSimple/@w:instr) and complex fields (w:fldChar
   * begin/separate/end with w:instrText runs, nested and split across runs),
   * like docgen's guard.
   */
  private static function assertFieldCodes(string $name, string $xml): void {
    $document = self::parse($name, $xml);
    $instructions = [];
    $stack = [];
    foreach ($document->getElementsByTagName('*') as $element) {
      if (!in_array($element->namespaceURI, self::WORD_NAMESPACES, TRUE)) {
        continue;
      }
      switch ($element->localName) {
        case 'fldSimple':
          $instructions[] = self::wordAttribute($element, 'instr');
          break;

        case 'fldChar':
          $type = self::wordAttribute($element, 'fldCharType');
          if ($type === 'begin') {
            $stack[] = ['text' => '', 'done' => FALSE];
          }
          elseif ($type === 'separate' && $stack !== []) {
            $top = array_key_last($stack);
            if (!$stack[$top]['done']) {
              $stack[$top]['done'] = TRUE;
              $instructions[] = $stack[$top]['text'];
            }
          }
          elseif ($type === 'end' && $stack !== []) {
            $field = array_pop($stack);
            if (!$field['done']) {
              $instructions[] = $field['text'];
            }
          }
          break;

        case 'instrText':
        case 'delInstrText':
          $top = array_key_last($stack);
          if ($top !== NULL && !$stack[$top]['done']) {
            $stack[$top]['text'] .= $element->textContent;
          }
          else {
            $instructions[] = $element->textContent;
          }
          break;
      }
    }
    foreach ($stack as $field) {
      if (!$field['done']) {
        $instructions[] = $field['text'];
      }
    }
    foreach ($instructions as $instruction) {
      $type = strtoupper((string) (preg_split('/\s+/u', trim($instruction))[0] ?? ''));
      if (in_array($type, self::FORBIDDEN_FIELD_TYPES, TRUE)) {
        throw self::forbidden('field_code', $name);
      }
    }
  }

  /**
   * A docgen.docx_invalid error for XML beyond the budget.
   */
  private static function xmlTooLarge(string $part): DocgenApiException {
    return new DocgenApiException('docgen.docx_invalid', 422, [
      'reason' => 'xml_too_large',
      'part' => $part,
      'maxXmlBytes' => self::MAX_XML_BYTES,
    ]);
  }

  /**
   * A docgen.docx_forbidden_content error for one part.
   */
  private static function forbidden(string $reason, string $part): DocgenApiException {
    return new DocgenApiException('docgen.docx_forbidden_content', 422, ['reason' => $reason, 'part' => $part]);
  }

  /**
   * Reads a WordprocessingML attribute (any prefix).
   */
  private static function wordAttribute(\DOMElement $element, string $local_name): string {
    foreach ($element->attributes ?? [] as $attribute) {
      if ($attribute->localName === $local_name && in_array($attribute->namespaceURI, self::WORD_NAMESPACES, TRUE)) {
        return $attribute->value;
      }
    }
    return '';
  }

  /**
   * Parses an XML part without network access or entity expansion.
   */
  private static function parse(string $name, string $xml): \DOMDocument {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $document->loadXML(preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml, LIBXML_NONET | LIBXML_COMPACT);
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'xml', 'part' => $name]);
    }
    return $document;
  }

  /**
   * Like docgen: .xml/.rels parts and anything whose bytes look like XML.
   */
  private static function isXmlPart(string $name, string $bytes): bool {
    $lower = strtolower($name);
    if (str_ends_with($lower, '.xml') || str_ends_with($lower, '.rels')) {
      return TRUE;
    }
    if (self::isUtf16($bytes)) {
      return TRUE;
    }
    $head = ltrim(substr(preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? '', 0, 256), " \t\r\n");
    return str_starts_with($head, '<');
  }

  /**
   * UTF-16 with a byte order mark, or '<' plus an ASCII character without.
   */
  private static function isUtf16(string $bytes): bool {
    if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
      return TRUE;
    }
    return (bool) preg_match('/^(<\x00[\x21-\x7E]\x00|\x00<\x00[\x21-\x7E])/', $bytes);
  }

}
