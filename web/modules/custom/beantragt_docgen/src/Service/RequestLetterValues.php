<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigSectionValidatorInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * City-wide request-letter policy and an editorial boilerplate reference.
 */
final class RequestLetterValues implements JurisdictionConfigSectionValidatorInterface {

  /**
   * Constructs the values provider.
   */
  public function __construct(
    private readonly ?JurisdictionConfigServiceInterface $config,
    private readonly EntityTypeManagerInterface $manager,
    private readonly DateFormatterInterface $dates,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sectionName(): string {
    return 'docgen';
  }

  /**
   * {@inheritdoc}
   */
  public function validateSection(array $section): array {
    $errors = [];
    if (array_diff(array_keys($section), ['requestInfoDeadlineDays', 'requestInfoNoticeUuid', 'holidayRegion']) !== []) {
      $errors[] = 'Unknown docgen setting.';
    }
    if (isset($section['requestInfoDeadlineDays']) && (!is_int($section['requestInfoDeadlineDays']) || $section['requestInfoDeadlineDays'] < 1 || $section['requestInfoDeadlineDays'] > 365)) {
      $errors[] = 'docgen.requestInfoDeadlineDays must be an integer between 1 and 365.';
    }
    if (isset($section['requestInfoNoticeUuid']) && (!is_string($section['requestInfoNoticeUuid']) || !Uuid::isValid($section['requestInfoNoticeUuid']))) {
      $errors[] = 'docgen.requestInfoNoticeUuid must be a UUID.';
    }
    if (array_key_exists('holidayRegion', $section) && !in_array($section['holidayRegion'], WorkingDays::REGIONS, TRUE)) {
      $errors[] = 'docgen.holidayRegion must be a German state code like DE-NW.';
    }
    return $errors;
  }

  /**
   * Resolves a published city-wide remarks boilerplate and deadline policy.
   */
  public function values(ApplicationInterface $application): array {
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    if (!$jurisdiction instanceof GroupInterface) {
      return [];
    }
    $policy = $this->config?->getSection($jurisdiction, 'docgen') ?? [];
    $deadline = $this->deadline($application);
    $values = $deadline instanceof \DateTimeImmutable
      ? ['nachforderung.frist' => $this->dates->format($deadline->getTimestamp(), 'custom', 'd.m.Y')]
      : [];
    $uuid = $policy['requestInfoNoticeUuid'] ?? NULL;
    if (!is_string($uuid) || !$this->manager->hasDefinition('node')) {
      return $values;
    }
    $nodes = $this->manager->getStorage('node')->loadByProperties(['uuid' => $uuid, 'type' => 'boilerplate']);
    $node = reset($nodes);
    if (!$node instanceof NodeInterface || !$node->isPublished()
      || (int) $node->get('field_jurisdiction')->target_id !== (int) $jurisdiction->id()
      || !$node->get('field_organisation')->isEmpty()
      || $node->get('field_boilerplate_type')->value !== 'remarks') {
      return $values;
    }
    // Editorial HTML is never inserted as XML or executed as a text format.
    $html = (string) $node->get('body')->value;
    $html = preg_replace('#<br\s*/?>|</(?:p|div|li)>#i', "\n", $html) ?? '';
    $values['nachforderung.mitwirkungshinweis'] = trim(PlainTextOutput::renderFromHtml($html));
    return $values;
  }

  /**
   * Deadline printed when drafting the request letter.
   *
   * Request time plus requestInfoDeadlineDays calendar days in the
   * installation timezone, moved to the next working day of the
   * jurisdiction's holidayRegion (nationwide holidays only without one).
   * The letter, the request record and the cover sheet read it in the same
   * request, so they print the same day.
   *
   * @return \DateTimeImmutable|null
   *   The deadline, NULL without a jurisdiction.
   */
  public function deadline(ApplicationInterface $application): ?\DateTimeImmutable {
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $policy = $this->config?->getSection($jurisdiction, 'docgen') ?? [];
    $days = $policy['requestInfoDeadlineDays'] ?? 14;
    $days = is_int($days) && $days >= 1 && $days <= 365 ? $days : 14;
    $timezone = date_default_timezone_get();
    $end = (new \DateTimeImmutable('@' . self::deadlineTimestamp($this->time->getRequestTime(), $days, $timezone)))
      ->setTimezone(new \DateTimeZone($timezone));
    return WorkingDays::nextWorkingDay($end, $this->holidayRegion($application));
  }

  /**
   * The jurisdiction's state code for holidays, NULL when none is set.
   */
  public function holidayRegion(ApplicationInterface $application): ?string {
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $region = ($this->config?->getSection($jurisdiction, 'docgen') ?? [])['holidayRegion'] ?? NULL;
    return is_string($region) && in_array($region, WorkingDays::REGIONS, TRUE) ? $region : NULL;
  }

  /**
   * Adds calendar days in the formatter's timezone, including DST transitions.
   */
  public static function deadlineTimestamp(int $timestamp, int $days, string $timezone): int {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone($timezone))
      ->modify('+' . $days . ' days')->getTimestamp();
  }

}
