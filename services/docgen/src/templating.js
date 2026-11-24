// Template inspection and rendering with the docxtemplater core (MIT).
//
// Only the built-in parser is used: a tag is a plain property lookup on the
// current scope (own properties only, "." is the scope itself). There is no
// expression language, so templates from outside cannot execute code. Do not
// add angular-expressions or paid docxtemplater modules here; the dependency
// check (scripts/check-dependency-tree.mjs) fails the build if they appear.
//
// Raw XML tags ({@tag}) are rejected: render data partly comes from citizens
// and must never be able to inject WordprocessingML.
//
// docxtemplater ships an InspectModule, but it requires lodash, which is not a
// dependency of docxtemplater. The small collector below reads the same
// post-parsed tree through the public module `set()` hook instead.

import Docxtemplater from 'docxtemplater';
import { tooLarge, unprocessable } from './errors.js';

const BASE_OPTIONS = Object.freeze({
  paragraphLoop: true,
  linebreaks: true,
  // docxtemplater logs template errors with stack and context to the console
  // by default; errors are returned to the caller instead.
  errorLogging: false,
});

class TagCollector {
  constructor() {
    this.name = 'BeantragtTagCollector';
    this.filePath = null;
    this.postparsed = new Map();
  }

  clone() {
    return new TagCollector();
  }

  set(options) {
    const inspect = options?.inspect;
    if (!inspect) {
      return;
    }
    if (inspect.filePath) {
      this.filePath = inspect.filePath;
    }
    if (inspect.postparsed && this.filePath) {
      this.postparsed.set(this.filePath, inspect.postparsed);
    }
  }

  allParts() {
    return [...this.postparsed.values()].flat();
  }
}

function templateErrors(error) {
  const list = error?.properties?.errors ?? [error];
  return list.map((item) => {
    const properties = item?.properties ?? {};
    return {
      id: String(properties.id ?? 'template_error'),
      message: String(properties.explanation ?? item?.message ?? 'Template error'),
      tag: properties.xtag === undefined ? null : String(properties.xtag),
      file: properties.file === undefined ? null : String(properties.file),
    };
  });
}

function isTemplateError(error) {
  return error?.properties !== undefined || error?.name === 'TemplateError';
}

function collectStructure(parts, scope, result) {
  for (const part of parts) {
    if (part.type !== 'placeholder') {
      continue;
    }
    const path = [...scope, part.value].join('.');
    if (part.module === 'loop') {
      result.loops.add(path);
      collectStructure(part.subparsed ?? [], [...scope, part.value], result);
    } else if (part.module === 'rawxml') {
      result.rawXml.add(path);
    } else {
      result.placeholders.add(path);
    }
  }
  return result;
}

function compile(zip, extraOptions = {}) {
  const collector = new TagCollector();
  const doc = new Docxtemplater(zip, { ...BASE_OPTIONS, ...extraOptions, modules: [collector] });
  const structure = collectStructure(collector.allParts(), [], {
    placeholders: new Set(),
    loops: new Set(),
    rawXml: new Set(),
  });
  return { doc, structure };
}

function rawXmlErrors(rawXml) {
  return [...rawXml].sort().map(tag => ({
    id: 'raw_xml_forbidden',
    message: 'Raw XML tags ({@...}) are not allowed in templates.',
    tag,
    file: null,
  }));
}

/**
 * Lists placeholders and loops of a template. Template syntax errors are
 * returned in `errors`, not thrown, because the catalog upload shows them to
 * the department that owns the template.
 *
 * @param {import('pizzip')} zip Sanitized template.
 */
export function inspectTemplate(zip) {
  let structure;
  try {
    ({ structure } = compile(zip));
  } catch (error) {
    if (!isTemplateError(error)) {
      throw error;
    }
    return { placeholders: [], loops: [], errors: templateErrors(error) };
  }
  return {
    placeholders: [...structure.placeholders].sort(),
    loops: [...structure.loops].sort(),
    errors: rawXmlErrors(structure.rawXml),
  };
}

/**
 * Renders a template with data. Missing values render as an empty string and
 * are reported in `missing` (dotted path including enclosing loops).
 *
 * @param {import('pizzip')} zip Sanitized template.
 * @param {object} data Render data (plain JSON object).
 * @param {number} maxBytes Maximum size of the rendered DOCX.
 * @returns {{ docx: Buffer, missing: string[] }}
 */
export function renderTemplate(zip, data, maxBytes) {
  const missing = new Set();
  let compiled;
  try {
    compiled = compile(zip, {
      nullGetter(part, scopeManager) {
        const scope = Array.isArray(scopeManager?.scopePath) ? scopeManager.scopePath : [];
        missing.add([...scope, part.value].join('.'));
        return '';
      },
    });
  } catch (error) {
    if (!isTemplateError(error)) {
      throw error;
    }
    throw unprocessable('template_invalid', 'The template could not be compiled.', { errors: templateErrors(error) });
  }
  if (compiled.structure.rawXml.size > 0) {
    throw unprocessable('template_invalid', 'The template contains raw XML tags.', { errors: rawXmlErrors(compiled.structure.rawXml) });
  }
  try {
    compiled.doc.render(data);
  } catch (error) {
    if (!isTemplateError(error)) {
      throw error;
    }
    throw unprocessable('template_invalid', 'The template could not be rendered.', { errors: templateErrors(error) });
  }
  const docx = compiled.doc.getZip().generate({ type: 'nodebuffer', compression: 'DEFLATE' });
  if (docx.length > maxBytes) {
    throw tooLarge('rendered_docx_too_large', 'The rendered DOCX exceeds the allowed size.', { maxDocxBytes: maxBytes });
  }
  return { docx, missing: [...missing].sort() };
}
