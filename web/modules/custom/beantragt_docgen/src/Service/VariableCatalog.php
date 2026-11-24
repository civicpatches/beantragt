<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

/**
 * Placeholders a decision template may use for one form.
 *
 * The catalog is the contract between the form editor, the template upload
 * check and the render data: every tag listed here is exactly the key that
 * RenderDataBuilder sends to docgen /render. docxtemplater's built-in parser
 * looks a tag up as one own property of the current scope, so the render
 * data is a flat object with dotted keys ("vorgang.aktenzeichen",
 * "antrag.full_name"). Repeatable sections become loops: the loop tag is
 * "antrag.<section id>", the fields inside are written without prefix
 * ({#antrag.kinder}{name}{/antrag.kinder}). docgen /inspect reports a
 * placeholder inside a loop with the loop path in front
 * ("antrag.kinder.name").
 *
 * Note: the citizen form renderer does not store repeatable sections yet
 * (no runtime convention in field_data). The catalog marks them from the
 * schema flag; render data contains a loop only when field_data holds a list
 * of objects under the section id.
 */
final class VariableCatalog {

  /**
   * System tags supplied by case extensions, including the receipt override.
   */
  public const PROVIDED = [
    'vorgang.fehlende_nachweise', 'vorgang.eingang',
    'empfaenger.name', 'empfaenger.anschrift', 'empfaenger.anrede',
    'nachforderung.deckblatt_hinweis',
  ];

  /**
   * System placeholders, the same on every form.
   */
  public const SYSTEM = [
    'vorgang.aktenzeichen' => [
      'de' => 'Aktenzeichen',
      'en' => 'Reference number',
      'type' => 'text',
    ],
    'vorgang.eingang' => [
      'de' => 'Eingangsdatum des Antrags',
      'en' => 'Date the application was received',
      'type' => 'date',
    ],
    'vorgang.status' => [
      'de' => 'Bearbeitungsstand',
      'en' => 'Processing status',
      'type' => 'text',
    ],
    'vorgang.fehlende_nachweise' => [
      'de' => 'Fehlende Pflichtnachweise',
      'en' => 'Missing required proofs',
      'type' => 'textarea',
    ],
    'organisation.name' => [
      'de' => 'Name der Behörde oder Dienststelle',
      'en' => 'Name of the authority or unit',
      'type' => 'text',
    ],
    'organisation.anschrift' => [
      'de' => 'Anschrift der Behörde oder Dienststelle',
      'en' => 'Postal address of the authority',
      'type' => 'textarea',
    ],
    'bearbeitung.name' => [
      'de' => 'Name der Person, die den Entwurf erstellt',
      'en' => 'Name of the person creating the draft',
      'type' => 'text',
    ],
    'heute' => [
      'de' => 'Heutiges Datum',
      'en' => "Today's date",
      'type' => 'date',
    ],
    'jurisdiction.name' => [
      'de' => 'Name der Stadt oder Kommune',
      'en' => 'Jurisdiction name',
      'type' => 'text',
    ],
    'empfaenger.name' => [
      'de' => 'Name der empfangenden Person (Stapelmanifest)',
      'en' => 'Recipient name (batch manifest)',
      'type' => 'text',
    ],
    'empfaenger.anschrift' => [
      'de' => 'Empfängeranschrift (Stapelmanifest)',
      'en' => 'Recipient address (batch manifest)',
      'type' => 'textarea',
    ],
    'empfaenger.anrede' => [
      'de' => 'Persönliche oder neutrale Anrede',
      'en' => 'Personal or neutral salutation',
      'type' => 'text',
    ],
    'nachforderung.frist' => [
      'de' => 'Frist für die Nachreichung',
      'en' => 'Deadline for missing documents',
      'type' => 'date',
    ],
    'nachforderung.mitwirkungshinweis' => [
      'de' => 'Stadtweiter Hinweis zu §§ 60, 66 SGB I',
      'en' => 'City-wide notice under sections 60, 66 SGB I',
      'type' => 'textarea',
    ],
    'nachforderung.deckblatt_hinweis' => [
      'de' => 'Hinweis auf das beiliegende Deckblatt',
      'en' => 'Notice about the enclosed cover sheet',
      'type' => 'text',
    ],
  ];

  /**
   * Prefix of form field placeholders.
   */
  public const FORM_PREFIX = 'antrag.';

  /**
   * Builds the catalog for a decoded form schema.
   *
   * @param array<string, mixed> $schema
   *   Decoded FormSchema.
   * @param string[] $protected_ids
   *   Field IDs whose values are protected, see
   *   ApplicationDataProtectionService::protectedFieldIds().
   *
   * @return array<int, array<string, mixed>>
   *   Variables in display order: system first, then form sections.
   */
  public static function build(array $schema, array $protected_ids): array {
    $variables = [];
    foreach (self::SYSTEM as $tag => $definition) {
      $variables[] = [
        'tag' => $tag,
        'syntax' => '{' . $tag . '}',
        'group' => 'system',
        'label' => ['de' => $definition['de'], 'en' => $definition['en']],
        'type' => $definition['type'],
        'section' => NULL,
        'protected' => str_starts_with($tag, 'empfaenger.'),
        'repeatable' => FALSE,
      ];
    }
    $protected = array_fill_keys($protected_ids, TRUE);
    foreach (is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [] as $section) {
      if (!is_array($section) || !is_string($section['id'] ?? NULL) || $section['id'] === '') {
        continue;
      }
      $section_projection = [
        'id' => $section['id'],
        'title' => self::labels($section, 'title'),
      ];
      $fields = [];
      foreach (is_array($section['fields'] ?? NULL) ? $section['fields'] : [] as $field) {
        if (!is_array($field) || !is_string($field['id'] ?? NULL) || !self::isUsableId($field['id'])) {
          continue;
        }
        $fields[] = [
          'id' => $field['id'],
          'label' => self::labels($field, 'label'),
          'type' => is_string($field['type'] ?? NULL) ? $field['type'] : 'text',
          'protected' => isset($protected[$field['id']]),
          'privacyKind' => is_string($field['privacy']['kind'] ?? NULL) ? $field['privacy']['kind'] : NULL,
        ];
      }
      if (($section['repeatable'] ?? FALSE) === TRUE && self::isUsableId($section['id'])) {
        $loop = self::FORM_PREFIX . $section['id'];
        $children = [];
        foreach ($fields as $field) {
          $children[] = [
            'tag' => $loop . '.' . $field['id'],
            'syntax' => '{' . $field['id'] . '}',
            'label' => $field['label'],
            'type' => $field['type'],
            'protected' => $field['protected'],
            'privacyKind' => $field['privacyKind'],
          ];
        }
        $variables[] = [
          'tag' => $loop,
          'syntax' => '{#' . $loop . '}…{/' . $loop . '}',
          'group' => 'form',
          'label' => $section_projection['title'],
          'type' => 'list',
          'section' => $section_projection,
          'protected' => in_array(TRUE, array_column($fields, 'protected'), TRUE),
          'repeatable' => TRUE,
          'children' => $children,
        ];
        continue;
      }
      foreach ($fields as $field) {
        $tag = self::FORM_PREFIX . $field['id'];
        $variables[] = [
          'tag' => $tag,
          'syntax' => '{' . $tag . '}',
          'group' => 'form',
          'label' => $field['label'],
          'type' => $field['type'],
          'section' => $section_projection,
          'protected' => $field['protected'],
          'repeatable' => FALSE,
          'privacyKind' => $field['privacyKind'],
        ];
      }
    }
    return $variables;
  }

  /**
   * Compares an /inspect result with the catalog.
   *
   * @param array<string, mixed> $inspection
   *   Placeholders (dotted paths, loop path in front) and loops, as stored
   *   from docgen /inspect.
   * @param array<int, array<string, mixed>> $variables
   *   Catalog from build().
   *
   * @return array{known: string[], unknown: array<int, array{tag: string, suggestion: ?string}>, protected: string[], unused: string[], used: string[]}
   *   Comparison. "unused" lists form placeholders the template does not use.
   */
  public static function compare(array $inspection, array $variables): array {
    $top = [];
    $loops = [];
    $protected = [];
    foreach ($variables as $variable) {
      $top[$variable['tag']] = TRUE;
      if ($variable['protected'] === TRUE) {
        $protected[$variable['tag']] = TRUE;
      }
      if (($variable['repeatable'] ?? FALSE) === TRUE) {
        $loops[$variable['tag']] = [];
        foreach ($variable['children'] ?? [] as $child) {
          $loops[$variable['tag']][substr($child['tag'], strlen($variable['tag']) + 1)] = $child['tag'];
          if ($child['protected'] === TRUE) {
            $protected[$child['tag']] = TRUE;
          }
        }
      }
    }
    $template_loops = array_values(array_filter(is_array($inspection['loops'] ?? NULL) ? $inspection['loops'] : [], 'is_string'));
    // Longest loop path first, so nested loops resolve to the innermost.
    usort($template_loops, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $known = [];
    $unknown = [];
    $used = [];
    $tags = array_merge(array_values(array_filter(is_array($inspection['placeholders'] ?? NULL) ? $inspection['placeholders'] : [], 'is_string')), $template_loops);
    foreach (array_unique($tags) as $tag) {
      $resolved = self::resolve($tag, $template_loops, $top, $loops);
      if ($resolved !== NULL) {
        $known[] = $tag;
        $used[$resolved] = TRUE;
        continue;
      }
      $unknown[] = [
        'tag' => $tag,
        'suggestion' => self::suggest(self::innermost($tag, $template_loops), array_keys($top)),
      ];
    }
    $unused = [];
    foreach ($variables as $variable) {
      if ($variable['group'] === 'form' && !isset($used[$variable['tag']])) {
        $unused[] = $variable['tag'];
      }
    }
    $protected_used = array_values(array_filter(array_keys($used), static fn (string $tag): bool => isset($protected[$tag])));
    sort($known);
    sort($protected_used);
    return [
      'known' => $known,
      'unknown' => $unknown,
      'protected' => $protected_used,
      'unused' => $unused,
      'used' => array_keys($used),
    ];
  }

  /**
   * Resolves a reported tag to the catalog tag it reads, or NULL.
   *
   * A placeholder inside a loop is reported as "<loop>.<name>". docxtemplater
   * looks <name> up in the loop item first and then in the outer scopes, so
   * both a field of the repeatable section and any top-level tag are valid.
   * A loop over any other known tag is accepted as well (docxtemplater treats
   * it as a section). Conditions are not part of the MVP: yes/no values
   * render as "Ja"/"Nein", which are both non-empty.
   *
   * @param string $tag
   *   Placeholder or loop path reported by docgen.
   * @param string[] $template_loops
   *   Loop paths of the template, longest first.
   * @param array<string, true> $top
   *   Top-level catalog tags.
   * @param array<string, array<string, string>> $loops
   *   Repeatable loop tags with their child names.
   */
  private static function resolve(string $tag, array $template_loops, array $top, array $loops): ?string {
    if (isset($top[$tag])) {
      return $tag;
    }
    foreach ($template_loops as $loop) {
      if (!str_starts_with($tag, $loop . '.')) {
        continue;
      }
      $inner = substr($tag, strlen($loop) + 1);
      $catalog_loop = self::catalogLoop($loop, $template_loops);
      if ($catalog_loop !== NULL && isset($loops[$catalog_loop][$inner])) {
        return $loops[$catalog_loop][$inner];
      }
      // Outer scope lookup, possibly through several enclosing loops.
      return self::resolve($inner, $template_loops, $top, $loops);
    }
    return NULL;
  }

  /**
   * Returns the catalog loop tag a template loop path refers to.
   */
  private static function catalogLoop(string $loop, array $template_loops): ?string {
    foreach ($template_loops as $outer) {
      if ($outer !== $loop && str_starts_with($loop, $outer . '.')) {
        return self::catalogLoop(substr($loop, strlen($outer) + 1), $template_loops);
      }
    }
    return $loop;
  }

  /**
   * Strips enclosing loop paths for the suggestion.
   *
   * @param string $tag
   *   Placeholder path reported by docgen.
   * @param string[] $template_loops
   *   Loop paths of the template.
   */
  private static function innermost(string $tag, array $template_loops): string {
    foreach ($template_loops as $loop) {
      if (str_starts_with($tag, $loop . '.')) {
        return self::innermost(substr($tag, strlen($loop) + 1), $template_loops);
      }
    }
    return $tag;
  }

  /**
   * Suggests the most similar catalog tag for a typo, or NULL.
   *
   * @param string $tag
   *   The unknown tag.
   * @param string[] $candidates
   *   Catalog tags.
   */
  public static function suggest(string $tag, array $candidates): ?string {
    $best = NULL;
    $best_distance = PHP_INT_MAX;
    $needle = mb_strtolower($tag);
    foreach ($candidates as $candidate) {
      $haystack = mb_strtolower($candidate);
      if (strlen($needle) > 255 || strlen($haystack) > 255) {
        continue;
      }
      $distance = levenshtein($needle, $haystack);
      // A tag without prefix ("aktenzeichen") still points to its full tag.
      if (str_ends_with($haystack, '.' . $needle)) {
        $distance = min($distance, 1);
      }
      if ($distance < $best_distance) {
        $best = $candidate;
        $best_distance = $distance;
      }
    }
    if ($best === NULL) {
      return NULL;
    }
    return $best_distance <= max(2, intdiv(strlen($best), 3)) ? $best : NULL;
  }

  /**
   * Returns DE and EN labels of a schema node.
   *
   * @return array{de: string, en: string}
   *   Labels; the base label fills a missing translation.
   */
  private static function labels(array $node, string $key): array {
    $base = is_string($node[$key] ?? NULL) ? trim($node[$key]) : '';
    $i18n = is_array($node['i18n'] ?? NULL) ? $node['i18n'] : [];
    $de = is_string($i18n['de'][$key] ?? NULL) && trim($i18n['de'][$key]) !== '' ? trim($i18n['de'][$key]) : $base;
    $en = is_string($i18n['en'][$key] ?? NULL) && trim($i18n['en'][$key]) !== '' ? trim($i18n['en'][$key]) : $base;
    return ['de' => $de, 'en' => $en];
  }

  /**
   * IDs that can be a docxtemplater tag without escaping.
   */
  public static function isUsableId(string $id): bool {
    return $id !== '' && strlen($id) <= 128 && !preg_match('/[{}\s]/u', $id);
  }

}
