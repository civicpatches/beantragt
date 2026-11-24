// Input check and sanitizer for untrusted DOCX files (templates and drafts).
//
// Accepted is exactly a WordprocessingML document (.docx): a ZIP with
// [Content_Types].xml and word/document.xml whose main part has the .docx
// content type. Rejected are macro-enabled documents and templates
// (.docm/.dotm/.dotx), VBA projects, ActiveX controls, OLE objects and other
// embeddings, altChunks, DTDs and field codes that pull in outside content
// (DDE, INCLUDETEXT, INCLUDEPICTURE, LINK, IMPORT, DATABASE, RD). Every part
// whose bytes look like XML is checked, whatever its name, because Office
// loads parts by content type, not by file extension; UTF-16 XML parts are
// rejected. External relationships
// (TargetMode="External") are removed together with the attributes that point
// at them, so hyperlinks keep their text but lose the target.
//
// The sanitized document is rebuilt from the validated entries; the original
// archive bytes are never handed to another parser.

import { DOMParser, XMLSerializer } from '@xmldom/xmldom';
import PizZip from 'pizzip';
import { tooLarge, unprocessable } from './errors.js';
import { readZipSafely, xmlEncoding, isXmlPart } from './zip-guard.js';

export const DOCX_MAIN_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
const RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PACKAGE_RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

const FORBIDDEN_CONTENT_TYPE = /macroEnabled|vbaProject|vbaData|ms-office\.vba|activeX|oleObject|template\.main\+xml|wordprocessingml\.template/iu;
const FORBIDDEN_PART = /(^|\/)(vbaProject\.bin|vbaData\.xml|vbaProjectSignature\.bin)$|^word\/(activeX|embeddings)\//iu;
const FORBIDDEN_RELATIONSHIP_TYPE = /\/(aFChunk|oleObject|activeXControl|activeXControlBinary|control|vbaProject|package|attachedTemplate|subDocument|frame)$/iu;
// Word field types that load outside content. Matched on the field type (the
// first token of the field instruction), case-insensitively like Word does.
// DATABASE queries an outside data source, RD references another document.
const FORBIDDEN_FIELD_TYPES = new Set(['DDE', 'DDEAUTO', 'INCLUDETEXT', 'INCLUDEPICTURE', 'INCLUDE', 'LINK', 'IMPORT', 'DATABASE', 'RD']);
// Transitional and Strict WordprocessingML namespaces.
const WORD_NAMESPACES = new Set([
  'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
  'http://purl.oclc.org/ooxml/wordprocessingml/main',
]);
// Elements that are meaningless without the relationship they point at.
const DROP_ELEMENT_WHEN_ORPHANED = new Set(['attachedTemplate', 'subDoc']);

function rejected(code, message) {
  return unprocessable(code, message);
}

function parseXml(text, partName) {
  if (/<!DOCTYPE/iu.test(text)) {
    throw rejected('docx_forbidden_content', `XML part ${partName} declares a DTD.`);
  }
  try {
    return new DOMParser({
      onError: (level, message) => {
        if (level !== 'warning') {
          throw new Error(message);
        }
      },
    }).parseFromString(text, 'application/xml');
  } catch {
    throw rejected('invalid_docx', `XML part ${partName} is not well-formed.`);
  }
}

function owningPart(relsName) {
  // word/_rels/document.xml.rels -> word/document.xml; _rels/.rels -> null.
  const match = /^(.*?)(?:^|\/)_rels\/([^/]*)\.rels$/u.exec(relsName);
  if (!match || match[2] === '') {
    return null;
  }
  return match[1] ? `${match[1]}/${match[2]}` : match[2];
}

function checkContentTypes(entries) {
  const raw = entries.get('[Content_Types].xml');
  if (!raw) {
    throw rejected('invalid_docx', 'The archive has no [Content_Types].xml.');
  }
  const doc = parseXml(raw.toString('utf8'), '[Content_Types].xml');
  let mainType = null;
  for (const tag of ['Default', 'Override']) {
    const nodes = doc.getElementsByTagName(tag);
    for (let index = 0; index < nodes.length; index += 1) {
      const node = nodes.item(index);
      const contentType = node.getAttribute('ContentType') || '';
      if (FORBIDDEN_CONTENT_TYPE.test(contentType)) {
        throw rejected('docx_macro_or_template', 'Macro-enabled documents, templates and embedded objects are not accepted.');
      }
      if (tag === 'Override' && node.getAttribute('PartName') === '/word/document.xml') {
        mainType = contentType;
      }
    }
  }
  if (mainType !== DOCX_MAIN_CONTENT_TYPE) {
    throw rejected('invalid_docx', 'The main document part does not have the .docx content type.');
  }
}

function fieldType(instruction) {
  return instruction.trim().split(/\s+/u)[0].toUpperCase();
}

function wordAttribute(element, localName) {
  const attributes = element.attributes;
  for (let index = 0; index < attributes.length; index += 1) {
    const attribute = attributes.item(index);
    if (attribute.localName === localName && WORD_NAMESPACES.has(attribute.namespaceURI)) {
      return attribute.value;
    }
  }
  return '';
}

/**
 * Rejects fields that load outside content. Namespace-aware (any prefix),
 * covers simple fields (w:fldSimple/@w:instr) and complex fields
 * (w:fldChar begin/separate/end with w:instrText runs, also nested and split
 * across runs).
 */
function checkFieldCodes(name, text) {
  if (!/instr/iu.test(text)) {
    return;
  }
  const doc = parseXml(text, name);
  const instructions = [];
  const stack = [];
  const finish = (field) => {
    if (field && !field.done) {
      field.done = true;
      instructions.push(field.text);
    }
  };
  const elements = doc.getElementsByTagName('*');
  for (let index = 0; index < elements.length; index += 1) {
    const element = elements.item(index);
    if (!WORD_NAMESPACES.has(element.namespaceURI)) {
      continue;
    }
    switch (element.localName) {
      case 'fldSimple':
        instructions.push(wordAttribute(element, 'instr'));
        break;
      case 'fldChar': {
        const type = wordAttribute(element, 'fldCharType');
        if (type === 'begin') {
          stack.push({ text: '', done: false });
        } else if (type === 'separate') {
          finish(stack.at(-1));
        } else if (type === 'end') {
          finish(stack.pop());
        }
        break;
      }
      case 'instrText':
      case 'delInstrText': {
        const field = stack.at(-1);
        if (field && !field.done) {
          field.text += element.textContent;
        } else {
          instructions.push(element.textContent);
        }
        break;
      }
      default:
        break;
    }
  }
  stack.forEach(finish);
  if (instructions.some(instruction => FORBIDDEN_FIELD_TYPES.has(fieldType(instruction)))) {
    throw rejected('docx_forbidden_content', 'Field codes that load outside content (DDE, INCLUDE, LINK, DATABASE, RD) are not accepted.');
  }
}

function removeOrphanedReferences(doc, removedIds) {
  const elements = doc.getElementsByTagName('*');
  // Live collection: iterate over a static copy.
  const list = [];
  for (let index = 0; index < elements.length; index += 1) {
    list.push(elements.item(index));
  }
  let changed = false;
  for (const element of list) {
    const attributes = element.attributes;
    const orphaned = [];
    for (let index = 0; index < attributes.length; index += 1) {
      const attribute = attributes.item(index);
      if (attribute.namespaceURI === RELATIONSHIP_NS && removedIds.has(attribute.value)) {
        orphaned.push(attribute);
      }
    }
    if (orphaned.length === 0) {
      continue;
    }
    changed = true;
    if (WORD_NAMESPACES.has(element.namespaceURI) && DROP_ELEMENT_WHEN_ORPHANED.has(element.localName) && element.parentNode) {
      element.parentNode.removeChild(element);
      continue;
    }
    for (const attribute of orphaned) {
      element.removeAttributeNode(attribute);
    }
  }
  return changed;
}

/**
 * Validates and sanitizes an untrusted DOCX.
 *
 * @param {Buffer} buffer DOCX bytes as received.
 * @param {object} limits Service limits (see config.js).
 * @returns {{ zip: PizZip, removedExternalRelationships: number, toBuffer: () => Buffer }}
 */
export function guardDocx(buffer, limits) {
  if (buffer.length > limits.maxDocxBytes) {
    throw tooLarge('docx_too_large', 'The DOCX exceeds the allowed size.', { maxDocxBytes: limits.maxDocxBytes });
  }

  const entries = readZipSafely(buffer, limits);
  if (!entries.has('word/document.xml')) {
    throw rejected('invalid_docx', 'The archive has no word/document.xml.');
  }

  for (const name of entries.keys()) {
    if (FORBIDDEN_PART.test(name)) {
      throw rejected('docx_macro_or_template', 'Macros, ActiveX controls and embedded objects are not accepted.');
    }
  }

  checkContentTypes(entries);

  const texts = new Map();
  for (const [name, content] of entries) {
    if (isXmlPart(name, content)) {
      if (xmlEncoding(content) === 'utf16') {
        throw rejected('invalid_docx', `XML part ${name} is not UTF-8 encoded.`);
      }
      // The DOM parser rejects a leading BOM; the part bytes stay unchanged.
      const text = content.toString('utf8').replace(/^\uFEFF/u, '');
      if (/<!DOCTYPE/iu.test(text)) {
        throw rejected('docx_forbidden_content', `XML part ${name} declares a DTD.`);
      }
      checkFieldCodes(name, text);
      texts.set(name, text);
    }
  }

  const serializer = new XMLSerializer();
  const replaced = new Map();
  let removedExternalRelationships = 0;

  for (const [name, text] of texts) {
    if (!name.endsWith('.rels')) {
      continue;
    }
    const doc = parseXml(text, name);
    const relationships = doc.getElementsByTagNameNS(PACKAGE_RELATIONSHIP_NS, 'Relationship');
    const removedIds = new Set();
    const toRemove = [];
    for (let index = 0; index < relationships.length; index += 1) {
      const relationship = relationships.item(index);
      const type = relationship.getAttribute('Type') || '';
      if (/\/aFChunk$/iu.test(type)) {
        throw rejected('docx_forbidden_content', 'altChunk parts (embedded foreign documents) are not accepted.');
      }
      if ((relationship.getAttribute('TargetMode') || '').toLowerCase() === 'external') {
        toRemove.push(relationship);
        removedIds.add(relationship.getAttribute('Id'));
        continue;
      }
      if (FORBIDDEN_RELATIONSHIP_TYPE.test(type)) {
        throw rejected('docx_macro_or_template', 'Macros, ActiveX controls and embedded objects are not accepted.');
      }
    }
    if (toRemove.length === 0) {
      continue;
    }
    for (const relationship of toRemove) {
      relationship.parentNode.removeChild(relationship);
    }
    removedExternalRelationships += toRemove.length;
    replaced.set(name, serializer.serializeToString(doc));

    const owner = owningPart(name);
    if (owner && texts.has(owner)) {
      const ownerDoc = parseXml(replaced.get(owner) ?? texts.get(owner), owner);
      if (removeOrphanedReferences(ownerDoc, removedIds)) {
        replaced.set(owner, serializer.serializeToString(ownerDoc));
      }
    }
  }

  const zip = new PizZip();
  for (const [name, content] of entries) {
    const bytes = replaced.has(name) ? Buffer.from(replaced.get(name), 'utf8') : content;
    zip.file(name, bytes, { binary: true, createFolders: false });
  }

  return {
    zip,
    removedExternalRelationships,
    toBuffer: () => zip.generate({ type: 'nodebuffer', compression: 'DEFLATE' }),
  };
}
