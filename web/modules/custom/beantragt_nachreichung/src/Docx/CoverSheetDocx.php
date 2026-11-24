<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Docx;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Exception\DocgenApiException;

/**
 * Builds the standalone cover sheet DOCX: one page, body only.
 *
 * The same sheet goes with a request letter (a companion PDF on the letter's
 * document row) and comes out of the staff reprint: both render it through
 * DocumentService::printable(), which adds the tenant's letterhead. The
 * paragraphs and the QR image go before the body section properties of an
 * empty DocxWriter document; no section break, no footer. Every text is
 * literal (CoverSheetContent::literal()), so the sheet carries no
 * placeholder of its own.
 *
 * An odd-page section appended to the letter was the first plan; the spike
 * of 09.11.2026 found LibreOffice writing a damaged tagged PDF/A for the
 * blank page after a one-page letter, so that path was dropped (spike code
 * in scripts/spikes/).
 */
final class CoverSheetDocx {

  public const BOOKMARK = 'beantragt_deckblatt';
  public const EXTENT_EMU = 1080000;
  public const DOC_PR_ID = '2147483645';

  private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  private const P = 'http://schemas.openxmlformats.org/package/2006/relationships';
  private const C = 'http://schemas.openxmlformats.org/package/2006/content-types';
  private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/';

  /**
   * Returns the cover sheet as a DOCX of its own.
   */
  public static function build(CoverSheetContent $content): string {
    if (!str_starts_with($content->png, "\x89PNG\r\n\x1a\n")) {
      throw new \InvalidArgumentException('The cover sheet needs a PNG.');
    }
    $docx = DocxWriter::build([]);
    $path = tempnam(sys_get_temp_dir(), 'coversheet');
    if ($path === FALSE) {
      throw new \RuntimeException('No temporary file for the cover sheet.');
    }
    try {
      file_put_contents($path, $docx);
      $zip = new \ZipArchive();
      if ($zip->open($path) !== TRUE) {
        throw new DocgenApiException('docgen.docx_invalid');
      }
      try {
        self::insert($zip, $content);
      }
      finally {
        $zip->close();
      }
      $result = (string) file_get_contents($path);
    }
    finally {
      @unlink($path);
    }
    DocxPrecheck::assertDocx($result);
    return $result;
  }

  /**
   * Inserts paragraphs, image part, relationship and content type.
   */
  private static function insert(\ZipArchive $zip, CoverSheetContent $content): void {
    $document = self::xml((string) $zip->getFromName('word/document.xml'));
    $w = self::W;
    if ($document->documentElement?->namespaceURI !== $w || $document->getElementsByTagNameNS($w, 'body')->length !== 1) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'unsupported_word_namespace']);
    }
    $r = self::R;
    $body = $document->getElementsByTagNameNS($w, 'body')->item(0);
    $section = NULL;
    for ($node = $body->lastChild; $node !== NULL; $node = $node->previousSibling) {
      if ($node instanceof \DOMElement) {
        if ($node->namespaceURI === $w && $node->localName === 'sectPr') {
          $section = $node;
        }
        break;
      }
    }
    if (!$section instanceof \DOMElement) {
      $section = $document->createElementNS($w, 'w:sectPr');
      $body->appendChild($section);
    }

    // A fresh media name and relationship id never overwrite template parts.
    $suffix = 1;
    do {
      $media = 'word/media/beantragt-deckblatt-qr-' . $suffix++ . '.png';
    } while ($zip->locateName($media) !== FALSE);
    $rels_bytes = $zip->getFromName('word/_rels/document.xml.rels');
    $rels = self::xml($rels_bytes === FALSE ? '<Relationships xmlns="' . self::P . '"/>' : $rels_bytes);
    $ids = [];
    foreach ($rels->getElementsByTagNameNS(self::P, 'Relationship') as $node) {
      $ids[] = $node->getAttribute('Id');
    }
    $id = 'rIdBeantragtDeckblattQr';
    while (in_array($id, $ids, TRUE)) {
      $id .= 'x';
    }
    $relationship = $rels->createElementNS(self::P, 'Relationship');
    $relationship->setAttribute('Id', $id);
    $relationship->setAttribute('Type', $r . '/image');
    $relationship->setAttribute('Target', substr($media, strlen('word/')));
    $rels->documentElement->appendChild($relationship);

    $types = self::xml((string) $zip->getFromName('[Content_Types].xml'));
    $has_png = FALSE;
    foreach ($types->getElementsByTagNameNS(self::C, 'Default') as $node) {
      if (strtolower($node->getAttribute('Extension')) === 'png') {
        $has_png = TRUE;
      }
    }
    if (!$has_png) {
      $default = $types->createElementNS(self::C, 'Default');
      $default->setAttribute('Extension', 'png');
      $default->setAttribute('ContentType', 'image/png');
      $types->documentElement->insertBefore($default, $types->documentElement->firstChild);
    }

    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('w', $w);
    $bookmark_id = 0;
    foreach ($xpath->query('//w:bookmarkStart/@w:id') as $attribute) {
      $bookmark_id = max($bookmark_id, (int) $attribute->nodeValue);
    }
    $wrapper = self::xml(self::paragraphs($content, $id, $bookmark_id + 1));
    foreach (iterator_to_array($wrapper->documentElement->childNodes) as $paragraph) {
      if ($paragraph instanceof \DOMElement) {
        $body->insertBefore($document->importNode($paragraph, TRUE), $section);
      }
    }

    $parts = [
      '[Content_Types].xml' => $types->saveXML(),
      'word/document.xml' => $document->saveXML(),
      'word/_rels/document.xml.rels' => $rels->saveXML(),
      $media => $content->png,
    ];
    foreach ($parts as $name => $bytes) {
      $zip->addFromString($name, $bytes);
      $zip->setMtimeName($name, 315532800);
    }
  }

  /**
   * The cover paragraphs as a namespaced fragment (Transitional names).
   */
  private static function paragraphs(CoverSheetContent $content, string $relationship_id, int $bookmark_id): string {
    $text = static fn (string $value, string $run_properties = ''): string => '<w:r>'
      . ($run_properties !== '' ? '<w:rPr>' . $run_properties . '</w:rPr>' : '')
      . '<w:t xml:space="preserve">' . CoverSheetContent::literal($value) . '</w:t></w:r>';
    $paragraph = static fn (string $runs, string $paragraph_properties = ''): string => '<w:p>'
      . ($paragraph_properties !== '' ? '<w:pPr>' . $paragraph_properties . '</w:pPr>' : '')
      . $runs . '</w:p>';

    $xml = $paragraph('<w:bookmarkStart w:id="' . $bookmark_id . '" w:name="' . self::BOOKMARK . '"/>'
      . $text('Deckblatt für Ihre Unterlagen', '<w:b/><w:sz w:val="32"/>')
      . '<w:bookmarkEnd w:id="' . $bookmark_id . '"/>', '<w:keepNext/><w:spacing w:after="240"/>');
    $xml .= $paragraph($text('Legen Sie dieses Blatt obenauf, wenn Sie Unterlagen zu Ihrem Antrag schicken oder abgeben. Das gilt auch, wenn jemand anderes die Unterlagen für Sie schickt. Bitte nicht über den Code heften oder knicken. Sie dürfen dieses Blatt kopieren und für weitere Unterlagen verwenden.'));
    $xml .= $paragraph($text('Aktenzeichen: ' . $content->referenceNumber));
    $xml .= $paragraph($text('Antrag: ' . $content->formLabel));
    if ($content->lines !== []) {
      $xml .= $paragraph($text('Bitte legen Sie bei:'));
      foreach ($content->lines as $line) {
        $xml .= $paragraph($text('- ' . $line), '<w:ind w:left="360"/>');
      }
      $xml .= $paragraph($text('Einzelheiten stehen im Schreiben.'));
    }
    if ($content->deadline instanceof \DateTimeImmutable) {
      $xml .= $paragraph($text('Frist: ' . $content->deadline->format('d.m.Y')));
    }
    $extent = 'cx="' . self::EXTENT_EMU . '" cy="' . self::EXTENT_EMU . '"';
    $drawing = '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
      . '<wp:extent ' . $extent . '/>'
      . '<wp:docPr id="' . self::DOC_PR_ID . '" name="Deckblatt QR" descr="QR-Code für die Zuordnung"/>'
      . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
      . '<a:graphic><a:graphicData uri="' . self::DRAWINGML . 'picture"><pic:pic>'
      . '<pic:nvPicPr><pic:cNvPr id="0" name="Deckblatt QR" descr="QR-Code für die Zuordnung"/><pic:cNvPicPr/></pic:nvPicPr>'
      . '<pic:blipFill><a:blip r:embed="' . $relationship_id . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
      . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext ' . $extent . '/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
      . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    $xml .= $paragraph($drawing, '<w:keepNext/><w:spacing w:before="480" w:after="60"/><w:jc w:val="right"/>');
    $xml .= $paragraph($text($content->groupedCode, '<w:rFonts w:ascii="Liberation Mono" w:hAnsi="Liberation Mono" w:cs="Liberation Mono"/><w:sz w:val="22"/>'), '<w:keepNext/><w:spacing w:after="60"/><w:jc w:val="right"/>');
    $xml .= $paragraph($text('Der Code enthält keine persönlichen Daten.', '<w:sz w:val="18"/>'), '<w:jc w:val="right"/>');

    return '<w:body xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"'
      . ' xmlns:wp="' . self::DRAWINGML . 'wordprocessingDrawing"'
      . ' xmlns:a="' . self::DRAWINGML . 'main"'
      . ' xmlns:pic="' . self::DRAWINGML . 'picture">' . $xml . '</w:body>';
  }

  /**
   * Parses prechecked XML without network access or entity expansion.
   */
  private static function xml(string $xml): \DOMDocument {
    $document = new \DOMDocument();
    if ($xml === '' || !@$document->loadXML($xml, LIBXML_NONET)) {
      throw new DocgenApiException('docgen.docx_invalid');
    }
    return $document;
  }

}
