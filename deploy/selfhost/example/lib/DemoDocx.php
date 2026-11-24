<?php

/**
 * @file
 * Seed-side letter layout of Stadt Musterstadt on top of DocxWriter.
 */

declare(strict_types=1);

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;

/**
 * Builds the base body every showcase decision template starts from.
 *
 * The product owns the header: DocxLetterhead::apply() removes every header
 * of a template and writes logo, jurisdiction, office and sender address
 * itself. So this helper adds only what a letter
 * body needs and DocxWriter does not offer, by post-processing its package:
 * - a borderless block with the recipient on the left (where a window
 *   envelope shows it) and the information column on the right;
 * - page margins of 2.5 cm left and 2 cm right;
 * - a footer part with address, opening hours, web address and page number
 *   (a PAGE field, which DocxPrecheck allows; DocxLetterhead keeps footers).
 * No sender block in the body (the header names the sender) and no bank
 * line. Liberation Sans comes from DocxWriter's styles. Every package edit
 * must apply exactly once, otherwise the build throws; entry times are
 * fixed to 1980-01-01 00:00 independent of the timezone, so equal input
 * gives equal bytes. Product classes stay unchanged.
 */
final class DemoDocx {

  private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  private const FOOTER_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml';
  private const MARKER = 'DEMODOCX-ADDRESS-BLOCK-7f3c';
  private const FOOTER_PART = 'word/footer1.xml';
  private const FOOTER_ID = 'rIdDemoFooter';

  /**
   * Content width in twips: A4 (11906) minus 2.5 cm (1417) and 2 cm (1134).
   */
  private const CONTENT_WIDTH = 9355;

  /**
   * Builds one letter.
   *
   * @param array{recipient: list<string>, info: list<array{0: string, 1: string}>, subject: string, body: list<string|array<string, mixed>>, footer: list<string>} $letter
   *   Recipient lines, information rows (label, value), subject line,
   *   DocxWriter body blocks and footer lines.
   *
   * @return string
   *   DOCX bytes that pass DocxPrecheck::assertDocx().
   */
  public static function letter(array $letter): string {
    $blocks = array_merge(
      [self::MARKER, ''],
      [['text' => $letter['subject'], 'bold' => TRUE, 'size' => 24]],
      $letter['body'],
    );
    $bytes = self::rewrite(DocxWriter::build($blocks), self::addressBlock($letter['recipient'], $letter['info']), $letter['footer']);
    DocxPrecheck::assertDocx($bytes);
    return $bytes;
  }

  /**
   * Replaces the three header tags of an applied letterhead by plain text.
   *
   * For a handout that opens correctly in Word; a template never needs it,
   * because the render fills the tags.
   *
   * @param string $bytes
   *   DOCX bytes after DocxLetterhead::apply().
   * @param array<string, string> $values
   *   Text per tag: jurisdiction.name, organisation.name,
   *   organisation.anschrift.
   */
  public static function fillHeaderTags(string $bytes, array $values): string {
    $parts = self::read($bytes);
    $replaced = 0;
    foreach ($parts as $name => $xml) {
      if (!preg_match('#^word/beantragt-letterhead-\d+\.xml$#D', $name)) {
        continue;
      }
      foreach ($values as $tag => $text) {
        $needle = '<w:t>{' . $tag . '}</w:t>';
        $count = substr_count($xml, $needle);
        if ($count !== 1) {
          throw new RuntimeException(sprintf('Letterhead tag %s found %d times in %s.', $tag, $count, $name));
        }
        $xml = str_replace($needle, '<w:t xml:space="preserve">' . self::escape($text) . '</w:t>', $xml);
        $replaced++;
      }
      $parts[$name] = $xml;
    }
    if ($replaced !== count($values)) {
      throw new RuntimeException(sprintf('Expected one letterhead part with %d tags, replaced %d.', count($values), $replaced));
    }
    $result = self::write($parts);
    DocxPrecheck::assertDocx($result);
    return $result;
  }

  /**
   * Whether a package still carries the footer part and its reference.
   *
   * Used after DocxLetterhead::apply() to prove the footer survived.
   */
  public static function hasFooter(string $bytes): bool {
    $parts = self::read($bytes);
    return isset($parts[self::FOOTER_PART])
      && substr_count($parts['word/document.xml'] ?? '', 'w:footerReference') === 1
      && str_contains($parts['word/_rels/document.xml.rels'] ?? '', 'Target="footer1.xml"')
      && str_contains($parts['[Content_Types].xml'] ?? '', '/' . self::FOOTER_PART);
  }

  /**
   * Applies the layout edits to a DocxWriter package.
   *
   * @param string $bytes
   *   DocxWriter output with the address marker paragraph.
   * @param string $address_block
   *   WordprocessingML that replaces the marker.
   * @param list<string> $footer
   *   Footer lines.
   */
  private static function rewrite(string $bytes, string $address_block, array $footer): string {
    $parts = self::read($bytes);

    $marker = '<w:p><w:r><w:t xml:space="preserve">' . self::MARKER . '</w:t></w:r></w:p>';
    $parts['word/document.xml'] = self::replaceOnce($parts['word/document.xml'], $marker, $address_block, 'address marker');
    $parts['word/document.xml'] = self::replaceOnce(
      $parts['word/document.xml'],
      '<w:pgMar w:top="1417" w:right="1417" w:bottom="1134" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/>',
      '<w:pgMar w:top="1417" w:right="1134" w:bottom="1134" w:left="1417" w:header="708" w:footer="567" w:gutter="0"/>',
      'page margins',
    );
    // CT_SectPr: header and footer references come first.
    $parts['word/document.xml'] = self::replaceOnce(
      $parts['word/document.xml'],
      '<w:sectPr>',
      '<w:sectPr><w:footerReference w:type="default" r:id="' . self::FOOTER_ID . '"/>',
      'section properties',
    );
    $parts['word/_rels/document.xml.rels'] = self::replaceOnce(
      $parts['word/_rels/document.xml.rels'],
      '</Relationships>',
      '<Relationship Id="' . self::FOOTER_ID . '" Type="' . self::R . '/footer" Target="footer1.xml"/></Relationships>',
      'document relationships',
    );
    $parts['[Content_Types].xml'] = self::replaceOnce(
      $parts['[Content_Types].xml'],
      '</Types>',
      '<Override PartName="/' . self::FOOTER_PART . '" ContentType="' . self::FOOTER_TYPE . '"/></Types>',
      'content types',
    );
    if (isset($parts[self::FOOTER_PART])) {
      throw new RuntimeException('The package already has a footer part.');
    }
    $parts[self::FOOTER_PART] = self::footer($footer);
    return self::write($parts);
  }

  /**
   * Renders the borderless recipient and information block.
   *
   * @param list<string> $recipient
   *   Recipient lines.
   * @param list<array{0: string, 1: string}> $info
   *   Label and value per row.
   */
  private static function addressBlock(array $recipient, array $info): string {
    $left = 5155;
    $right = self::CONTENT_WIDTH - $left;
    $nil = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
      . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>';
    $recipient_xml = '';
    foreach ($recipient as $line) {
      $recipient_xml .= self::line($line, 22, FALSE);
    }
    $info_xml = '';
    foreach ($info as [$label, $value]) {
      $info_xml .= self::line($label, 16, TRUE) . self::line($value, 18, FALSE, 60);
    }
    return '<w:tbl><w:tblPr><w:tblW w:w="' . self::CONTENT_WIDTH . '" w:type="dxa"/><w:tblBorders>' . $nil . '</w:tblBorders>'
      . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar>'
      . '</w:tblPr><w:tblGrid><w:gridCol w:w="' . $left . '"/><w:gridCol w:w="' . $right . '"/></w:tblGrid>'
      . '<w:tr><w:trPr><w:cantSplit/></w:trPr>'
      . '<w:tc><w:tcPr><w:tcW w:w="' . $left . '" w:type="dxa"/></w:tcPr>' . $recipient_xml . '</w:tc>'
      . '<w:tc><w:tcPr><w:tcW w:w="' . $right . '" w:type="dxa"/></w:tcPr>' . $info_xml . '</w:tc>'
      . '</w:tr></w:tbl>';
  }

  /**
   * Renders the footer part: centred lines and the page number.
   *
   * @param list<string> $lines
   *   Footer lines.
   */
  private static function footer(array $lines): string {
    $run = '<w:rPr><w:color w:val="4D4D4D"/><w:sz w:val="16"/></w:rPr>';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '">';
    foreach ($lines as $line) {
      $xml .= '<w:p><w:pPr><w:spacing w:after="0"/><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:rPr><w:color w:val="4D4D4D"/><w:sz w:val="16"/></w:rPr><w:t xml:space="preserve">' . self::escape($line) . '</w:t></w:r></w:p>';
    }
    return $xml . '<w:p><w:pPr><w:spacing w:before="60" w:after="0"/><w:jc w:val="center"/></w:pPr>'
      . '<w:r>' . $run . '<w:t xml:space="preserve">Seite </w:t></w:r>'
      // A complex field keeps the run formatting of its result when the
      // converter updates it; a simple field falls back to the default size.
      . '<w:r>' . $run . '<w:fldChar w:fldCharType="begin"/></w:r>'
      . '<w:r>' . $run . '<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
      . '<w:r>' . $run . '<w:fldChar w:fldCharType="separate"/></w:r>'
      . '<w:r>' . $run . '<w:t>1</w:t></w:r>'
      . '<w:r>' . $run . '<w:fldChar w:fldCharType="end"/></w:r>'
      . '</w:p></w:ftr>';
  }

  /**
   * One compact paragraph.
   */
  private static function line(string $text, int $size, bool $bold, int $after = 0): string {
    return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/></w:pPr><w:r><w:rPr>' . ($bold ? '<w:b/>' : '')
      . '<w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . self::escape($text) . '</w:t></w:r></w:p>';
  }

  /**
   * Replaces a needle that must occur exactly once.
   */
  private static function replaceOnce(string $haystack, string $needle, string $replacement, string $what): string {
    $count = substr_count($haystack, $needle);
    if ($count !== 1) {
      throw new RuntimeException(sprintf('DemoDocx: %s found %d times, expected once.', $what, $count));
    }
    return str_replace($needle, $replacement, $haystack);
  }

  /**
   * Reads every entry of a package, in package order.
   *
   * @return array<string, string>
   *   Bytes per entry name.
   */
  private static function read(string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'demodocx');
    if ($path === FALSE) {
      throw new RuntimeException('No temporary file for the DOCX.');
    }
    try {
      file_put_contents($path, $bytes);
      $zip = new ZipArchive();
      if ($zip->open($path, ZipArchive::RDONLY) !== TRUE) {
        throw new RuntimeException('The DOCX could not be opened.');
      }
      $parts = [];
      for ($index = 0; $index < $zip->count(); $index++) {
        $name = $zip->getNameIndex($index);
        $content = is_string($name) ? $zip->getFromIndex($index) : FALSE;
        if (!is_string($name) || !is_string($content)) {
          throw new RuntimeException('A DOCX entry could not be read.');
        }
        $parts[$name] = $content;
      }
      $zip->close();
      return $parts;
    }
    finally {
      @unlink($path);
    }
  }

  /**
   * Writes entries into a new package with fixed entry times.
   *
   * @param array<string, string> $parts
   *   Bytes per entry name.
   */
  private static function write(array $parts): string {
    $path = tempnam(sys_get_temp_dir(), 'demodocx');
    if ($path === FALSE) {
      throw new RuntimeException('No temporary file for the DOCX.');
    }
    try {
      $zip = new ZipArchive();
      if ($zip->open($path, ZipArchive::OVERWRITE) !== TRUE) {
        throw new RuntimeException('The DOCX could not be created.');
      }
      foreach ($parts as $name => $content) {
        $zip->addFromString($name, $content);
        $zip->setMtimeName($name, 315532800);
      }
      $zip->close();
      return self::fixEntryTimes((string) file_get_contents($path));
    }
    finally {
      @unlink($path);
    }
  }

  /**
   * Sets every entry's DOS time and date to 1980-01-01 00:00.
   *
   * The zip library converts the fixed timestamp to local time, so the same
   * package would get other bytes, and the seed would release new template
   * versions, in a container with another timezone. The stored DOS fields are
   * rewritten in the local headers and in the central directory; nothing
   * else changes (the CRC covers the content only). The walk follows the
   * central directory, so a signature inside compressed data is never read.
   */
  private static function fixEntryTimes(string $bytes): string {
    $eocd = strrpos($bytes, "PK\x05\x06");
    if ($eocd === FALSE || strlen($bytes) < $eocd + 22) {
      throw new RuntimeException('DemoDocx: no end of central directory.');
    }
    $count = unpack('v', $bytes, $eocd + 10)[1];
    $offset = unpack('V', $bytes, $eocd + 16)[1];
    // DOS time 00:00:00 and date 1980-01-01 (day 1, month 1, year 0).
    $time_date = pack('vv', 0, 33);
    for ($index = 0; $index < $count; $index++) {
      if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
        throw new RuntimeException('DemoDocx: unexpected central directory entry.');
      }
      $local = unpack('V', $bytes, $offset + 42)[1];
      if (substr($bytes, $local, 4) !== "PK\x03\x04") {
        throw new RuntimeException('DemoDocx: unexpected local file header.');
      }
      $bytes = substr_replace($bytes, $time_date, $offset + 12, 4);
      $bytes = substr_replace($bytes, $time_date, $local + 10, 4);
      $lengths = unpack('vname/vextra/vcomment', $bytes, $offset + 28);
      $offset += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
    }
    return $bytes;
  }

  /**
   * Escapes text for WordprocessingML.
   */
  private static function escape(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

}
