<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_nachreichung\Code\CoverCode;
use Drupal\beantragt_nachreichung\Code\CoverCodeHasher;
use Drupal\beantragt_nachreichung\Docx\CoverSheetContent;
use Drupal\beantragt_nachreichung\Docx\CoverSheetDocx;
use Drupal\beantragt_nachreichung\Qr\QrPng;
use Drupal\Core\Entity\EntityInterface;

/**
 * Issues a new identifier and builds its standalone cover sheet DOCX.
 *
 * The same builder for the sheet that goes with a request letter and for the
 * staff reprint. The identifier leaves this class only inside the PNG and
 * the DOCX; callers store its hash.
 */
class CoverSheetBuilder {

  /**
   * Constructs the builder.
   */
  public function __construct(private readonly CoverCodeHasher $hasher) {}

  /**
   * Issues an identifier and builds the sheet.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param array<int, array<string, mixed>> $requested
   *   Requested proofs of the request record ([] for none).
   * @param \DateTimeImmutable|null $deadline
   *   Deadline of the request record, NULL without one.
   *
   * @return array{docx: string, codeHash: string, keyVersion: int}
   *   The DOCX and what is stored about the identifier: only its keyed hash
   *   (no unkeyed derivative such as a hash of the QR image).
   *
   * @throws \Throwable
   *   Key check, QR or DOCX failure; callers map it to their own code.
   */
  public function issue(ApplicationInterface $application, array $requested, ?\DateTimeImmutable $deadline): array {
    $version = $this->hasher->currentVersion();
    $this->hasher->guard($version);
    $code = CoverCode::generate();
    $docx = CoverSheetDocx::build(new CoverSheetContent(
      (string) ($application->getReferenceNumber() ?? ''),
      $this->formLabel($application),
      CoverSheetContent::proofLines($requested),
      $deadline,
      CoverCode::group($code),
      QrPng::render(CoverCode::payload($code)),
    ));
    return [
      'docx' => $docx,
      'codeHash' => $this->hasher->hash($code, $version),
      'keyVersion' => $version,
    ];
  }

  /**
   * Label of the application's form.
   */
  private function formLabel(ApplicationInterface $application): string {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return '';
    }
    $form = $application->get('field_schema')->entity;
    return $form instanceof EntityInterface ? (string) $form->label() : '';
  }

}
