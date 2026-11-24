<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Docx\DocxLetterhead;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves the issuing application's jurisdiction, never a template's logo.
 */
class LetterheadService {

  /**
   * Constructs the resolver.
   */
  public function __construct(private readonly ?JurisdictionConfigServiceInterface $config) {}

  /**
   * Inserts the issuing tenant's branding before rendering.
   */
  public function apply(ApplicationInterface $application, string $template): string {
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    if (!$jurisdiction instanceof GroupInterface || $jurisdiction->bundle() !== 'jur') {
      throw new DocgenApiException('docgen.letterhead_missing', 409);
    }
    // The branding API stores logoDataUrl and logoAlt in the theme section.
    $theme = ($this->config ?? throw new DocgenApiException('docgen.letterhead_missing', 409))->getSection($jurisdiction, 'theme');
    return DocxLetterhead::apply($template,
      is_string($theme['logoDataUrl'] ?? NULL) ? $theme['logoDataUrl'] : '',
      is_string($theme['logoAlt'] ?? NULL) && trim($theme['logoAlt']) !== '' ? $theme['logoAlt'] : (string) $jurisdiction->label(),
    );
  }

}
