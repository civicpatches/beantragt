// Builds small DOCX files for tests. No binary fixtures in the repository:
// every test document is generated here so its content stays reviewable.

import PizZip from 'pizzip';

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

export const MAIN_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

function escapeXml(text) {
  return text.replace(/&/gu, '&amp;').replace(/</gu, '&lt;').replace(/>/gu, '&gt;');
}

export function paragraph(text) {
  return `<w:p><w:r><w:t xml:space="preserve">${escapeXml(text)}</w:t></w:r></w:p>`;
}

/**
 * @param {object} [options]
 * @param {string[]} [options.paragraphs] Plain paragraph texts (may contain {tags}).
 * @param {string} [options.bodyXml] Raw body XML appended after the paragraphs.
 * @param {string} [options.mainContentType]
 * @param {string[]} [options.documentRelationships] Raw <Relationship/> elements.
 * @param {Record<string, string|Buffer>} [options.extraEntries]
 * @param {string[]} [options.extraContentTypes] Raw <Default/>/<Override/> elements.
 * @param {string} [options.settingsXml] Raw word/settings.xml content.
 */
export function buildDocxZip(options = {}) {
  const {
    paragraphs = ['Hallo {name}'],
    bodyXml = '',
    mainContentType = MAIN_TYPE,
    documentRelationships = [],
    extraEntries = {},
    extraContentTypes = [],
    settingsXml = null,
  } = options;

  const zip = new PizZip();
  const overrides = [
    `<Override PartName="/word/document.xml" ContentType="${mainContentType}"/>`,
    '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>',
  ];
  const relationships = [
    `<Relationship Id="rIdStyles" Type="${R}/styles" Target="styles.xml"/>`,
    ...documentRelationships,
  ];
  if (settingsXml !== null) {
    overrides.push('<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>');
    relationships.push(`<Relationship Id="rIdSettings" Type="${R}/settings" Target="settings.xml"/>`);
    zip.file('word/settings.xml', settingsXml);
  }

  zip.file('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    + '<Default Extension="xml" ContentType="application/xml"/>'
    + overrides.join('')
    + extraContentTypes.join('')
    + '</Types>');
  zip.file('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + `<Relationship Id="rId1" Type="${R}/officeDocument" Target="word/document.xml"/>`
    + '</Relationships>');
  zip.file('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + relationships.join('')
    + '</Relationships>');
  zip.file('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + `<w:styles xmlns:w="${W}"><w:docDefaults><w:rPrDefault><w:rPr>`
    + '<w:rFonts w:ascii="Liberation Sans" w:hAnsi="Liberation Sans" w:cs="Liberation Sans"/>'
    + '<w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults></w:styles>');
  zip.file('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + `<w:document xmlns:w="${W}" xmlns:r="${R}"><w:body>`
    + paragraphs.map(paragraph).join('')
    + bodyXml
    + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
    + '<w:pgMar w:top="1417" w:right="1417" w:bottom="1134" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/>'
    + '</w:sectPr></w:body></w:document>');

  for (const [name, content] of Object.entries(extraEntries)) {
    zip.file(name, content);
  }
  return zip;
}

export function buildDocx(options = {}) {
  return buildDocxZip(options).generate({ type: 'nodebuffer', compression: 'DEFLATE' });
}

export const NAMESPACES = { W, R };
