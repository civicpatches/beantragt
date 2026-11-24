<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Docx;

use Drupal\beantragt_docgen\Exception\DocgenApiException;

/**
 * Replaces all section headers with a bounded, embedded tenant letterhead.
 */
final class DocxLetterhead {

  private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  private const P = 'http://schemas.openxmlformats.org/package/2006/relationships';
  private const C = 'http://schemas.openxmlformats.org/package/2006/content-types';
  private const HEADER = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';

  /**
   * Checks both the original template and the final package, without fetching.
   */
  public static function apply(string $bytes, string $data_url, string $alt): string {
    DocxPrecheck::assertDocx($bytes);
    if ($data_url === '') {
      throw new DocgenApiException('docgen.letterhead_missing', 409);
    }
    // No SVG interpreter in this path: neither external resources nor scripts
    // from branding can reach Word or the converter.
    if (str_starts_with($data_url, 'data:image/svg+xml')) {
      throw new DocgenApiException('docgen.letterhead_invalid', 422, ['reason' => 'svg_not_supported']);
    }
    if (strlen($data_url) > 1400000 || !preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#D', $data_url, $match)) {
      throw new DocgenApiException('docgen.letterhead_invalid', 422, ['reason' => 'image_format']);
    }
    $image = base64_decode($match[2], TRUE);
    $size = is_string($image) ? @getimagesizefromstring($image) : FALSE;
    if (!$size || $size['mime'] !== 'image/' . $match[1]
      || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096
      || $size[0] * $size[1] > 8000000) {
      throw new DocgenApiException('docgen.letterhead_invalid', 422, ['reason' => 'image_format']);
    }
    $path = tempnam(sys_get_temp_dir(), 'letterhead');
    if ($path === FALSE) {
      throw new DocgenApiException('docgen.internal_error', 500);
    }
    try {
      file_put_contents($path, $bytes);
      $zip = new \ZipArchive();
      if ($zip->open($path) !== TRUE) {
        throw new DocgenApiException('docgen.docx_invalid');
      }
      try {
        self::replace($zip, $image, $match[1], $size[0], $size[1], $alt);
      }
      finally {
        $zip->close();
      }
      $result = (string) file_get_contents($path);
      DocxPrecheck::assertDocx($result);
      return $result;
    }
    finally {
      @unlink($path);
    }
  }

  /**
   * Replaces headers, preserving unrelated parts, relationships and margins.
   */
  private static function replace(\ZipArchive $zip, string $image, string $format, int $width, int $height, string $alt): void {
    $document = self::xml((string) $zip->getFromName('word/document.xml'));
    $w = $document->documentElement?->namespaceURI;
    if (!in_array($w, [self::W, 'http://purl.oclc.org/ooxml/wordprocessingml/main'], TRUE)
      || $document->getElementsByTagNameNS($w, 'body')->length !== 1) {
      throw new DocgenApiException('docgen.docx_invalid', 422, ['reason' => 'unsupported_word_namespace']);
    }
    $r = $w === self::W ? self::R : 'http://purl.oclc.org/ooxml/officeDocument/relationships';
    $old_images = [];
    $types = self::xml((string) $zip->getFromName('[Content_Types].xml'));
    foreach (iterator_to_array($types->getElementsByTagNameNS(self::C, 'Override')) as $node) {
      if ($node->getAttribute('ContentType') === self::HEADER) {
        $part = ltrim($node->getAttribute('PartName'), '/');
        $old_rels = dirname($part) . '/_rels/' . basename($part) . '.rels';
        $old_bytes = $zip->getFromName($old_rels);
        if (is_string($old_bytes)) {
          $old_images += self::targets($old_bytes, dirname($part), TRUE);
        }
        $zip->deleteName($part);
        $zip->deleteName(dirname($part) . '/_rels/' . basename($part) . '.rels');
        $node->parentNode->removeChild($node);
      }
    }
    // A fresh name cannot overwrite an unrelated template part.
    $suffix = 1;
    do {
      $base = 'beantragt-letterhead-' . $suffix++;
      $part = 'word/' . $base . '.xml';
      $media = 'word/media/' . $base . '.' . $format;
      $rels_part = 'word/_rels/' . $base . '.xml.rels';
    } while ($zip->locateName($part) !== FALSE || $zip->locateName($media) !== FALSE || $zip->locateName($rels_part) !== FALSE);
    $rels_bytes = $zip->getFromName('word/_rels/document.xml.rels');
    $rels = self::xml($rels_bytes === FALSE ? '<Relationships xmlns="' . self::P . '"/>' : $rels_bytes);
    $ids = [];
    foreach (iterator_to_array($rels->getElementsByTagNameNS(self::P, 'Relationship')) as $node) {
      if (str_ends_with($node->getAttribute('Type'), '/header')) {
        $node->parentNode->removeChild($node);
      }
      else {
        $ids[] = $node->getAttribute('Id');
      }
    }
    $id = 'rIdBeantragtLetterhead';
    while (in_array($id, $ids, TRUE)) {
      $id .= 'x';
    }
    $relationship = $rels->createElementNS(self::P, 'Relationship');
    $relationship->setAttribute('Id', $id);
    $relationship->setAttribute('Type', $r . '/header');
    $relationship->setAttribute('Target', $base . '.xml');
    $rels->documentElement->appendChild($relationship);
    foreach (iterator_to_array($document->getElementsByTagNameNS($w, 'headerReference')) as $node) {
      $node->parentNode->removeChild($node);
    }
    // Historical section properties (CT_SectPrBase) cannot carry headers.
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('w', $w);
    $sections = iterator_to_array($xpath->query('//w:sectPr[not(ancestor::w:sectPrChange)]'));
    if ($sections === []) {
      $section = $document->createElementNS($w, 'w:sectPr');
      $document->getElementsByTagNameNS($w, 'body')->item(0)?->appendChild($section);
      $sections[] = $section;
    }
    foreach ($sections as $section) {
      foreach (['even', 'first', 'default'] as $type) {
        $reference = $document->createElementNS($w, 'w:headerReference');
        $reference->setAttributeNS($w, 'w:type', $type);
        $reference->setAttributeNS($r, 'r:id', $id);
        $section->insertBefore($reference, $section->firstChild);
      }
      $margin = $xpath->query('./w:pgMar', $section)->item(0);
      if (!$margin instanceof \DOMElement) {
        $margin = $document->createElementNS($w, 'w:pgMar');
        foreach (['left' => 1417, 'right' => 1417, 'bottom' => 1134, 'footer' => 708, 'gutter' => 0] as $key => $value) {
          $margin->setAttributeNS($w, 'w:' . $key, (string) $value);
        }
        // CT_SectPr puts pgMar after pgSz and before every later property.
        $before = NULL;
        foreach ($section->childNodes as $child) {
          if ($child instanceof \DOMElement && !in_array($child->localName, [
            'headerReference', 'footerReference', 'footnotePr', 'endnotePr', 'type', 'pgSz',
          ], TRUE)) {
            $before = $child;
            break;
          }
        }
        $section->insertBefore($margin, $before);
      }
      $margin->setAttributeNS($w, 'w:top', (string) max(3000, (int) $margin->getAttributeNS($w, 'top')));
      $margin->setAttributeNS($w, 'w:header', '450');
    }
    foreach ([$part => self::HEADER, $media => 'image/' . $format] as $name => $type) {
      $override = $types->createElementNS(self::C, 'Override');
      $override->setAttribute('PartName', '/' . $name);
      $override->setAttribute('ContentType', $type);
      $types->documentElement->appendChild($override);
    }
    $referenced = [];
    for ($index = 0; $index < $zip->count(); $index++) {
      $name = $zip->getNameIndex($index);
      if (!is_string($name) || !str_ends_with($name, '.rels')) {
        continue;
      }
      $raw = $zip->getFromName($name);
      if (is_string($raw)) {
        $directory = dirname(dirname($name));
        $referenced += self::targets($raw, $directory === '.' ? '' : $directory);
      }
    }
    foreach (array_diff_key($old_images, $referenced) as $image_part => $unused) {
      $zip->deleteName($image_part);
      foreach (iterator_to_array($types->getElementsByTagNameNS(self::C, 'Override')) as $node) {
        if ($node->getAttribute('PartName') === '/' . $image_part) {
          $node->parentNode->removeChild($node);
        }
      }
    }
    $cx = min(1584000, (int) round(650000 * $width / $height));
    $cy = (int) round($cx * $height / $width);
    $header = self::header($cx, $cy, $alt);
    if ($w !== self::W) {
      $header = str_replace([
        self::W, self::R, 'http://schemas.openxmlformats.org/drawingml/2006/',
      ], [$w, $r, 'http://purl.oclc.org/ooxml/drawingml/'], $header);
    }
    $parts = [
      '[Content_Types].xml' => $types->saveXML(),
      'word/document.xml' => $document->saveXML(),
      'word/_rels/document.xml.rels' => $rels->saveXML(),
      $part => $header,
      $media => $image,
      $rels_part => '<Relationships xmlns="' . self::P . '"><Relationship Id="logo" Type="' . $r . '/image" Target="media/' . $base . '.' . $format . '"/></Relationships>',
    ];
    foreach ($parts as $name => $content) {
      $zip->addFromString($name, $content);
      $zip->setMtimeName($name, 315532800);
    }
  }

  /**
   * Resolves internal relationship targets without leaving the package.
   */
  private static function targets(string $xml, string $directory, bool $images_only = FALSE): array {
    $targets = [];
    foreach (self::xml($xml)->getElementsByTagNameNS(self::P, 'Relationship') as $node) {
      if (strtolower($node->getAttribute('TargetMode')) === 'external'
        || ($images_only && !str_ends_with($node->getAttribute('Type'), '/image'))) {
        continue;
      }
      $target = rawurldecode($node->getAttribute('Target'));
      $path = str_starts_with($target, '/') ? $target : $directory . '/' . $target;
      $segments = [];
      foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
          array_pop($segments);
        }
        elseif ($segment !== '' && $segment !== '.') {
          $segments[] = $segment;
        }
      }
      $name = implode('/', $segments);
      // Never remove structural XML on the strength of a forged image link.
      if (!$images_only || preg_match('#^word/media/[^/]+\.(?:png|jpe?g|gif|bmp|tiff?|svg|emf|wmf)$#iD', $name)) {
        $targets[$name] = TRUE;
      }
    }
    return $targets;
  }

  /**
   * Makes a header with fixed placeholders, never template code from branding.
   */
  private static function header(int $cx, int $cy, string $alt): string {
    $alt = htmlspecialchars(mb_substr($alt, 0, 300), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $extent = 'cx="' . $cx . '" cy="' . $cy . '"';
    $xml = '<w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
      . '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr><w:r><w:drawing><wp:inline>'
      . '<wp:extent ' . $extent . '/><wp:docPr id="2147483646" name="Tenant logo" descr="' . $alt . '"/>'
      . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
      . '<pic:nvPicPr><pic:cNvPr id="0" name="Tenant logo"/><pic:cNvPicPr/></pic:nvPicPr>'
      . '<pic:blipFill><a:blip r:embed="logo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
      . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext ' . $extent . '/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
      . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    foreach (['jurisdiction.name', 'organisation.name', 'organisation.anschrift'] as $tag) {
      $xml .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t>{' . $tag . '}</w:t></w:r></w:p>';
    }
    return $xml . '</w:hdr>';
  }

  /**
   * Parses only prechecked UTF-8 XML, with no entity expansion or network.
   */
  private static function xml(string $xml): \DOMDocument {
    $document = new \DOMDocument();
    if (!@$document->loadXML($xml, LIBXML_NONET)) {
      throw new DocgenApiException('docgen.docx_invalid');
    }
    return $document;
  }

}
