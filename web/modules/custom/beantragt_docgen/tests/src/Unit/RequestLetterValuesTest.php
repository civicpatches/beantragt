<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Service\RequestLetterValues;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deadline configuration and the city-wide editorial notice boundary.
 */
#[Group('beantragt_docgen')]
final class RequestLetterValuesTest extends UnitTestCase {

  /**
   * Default and custom deadlines; unpublished or foreign notices stay missing.
   */
  public function testPolicyAndNoticeScope(): void {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn(10);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('get')->willReturn($this->field(['entity' => $jurisdiction]));
    foreach ([
      [[], TRUE, 10, TRUE, 14, TRUE],
      [['requestInfoDeadlineDays' => 21], TRUE, 10, TRUE, 21, TRUE],
      [['requestInfoDeadlineDays' => 0], TRUE, 10, TRUE, 14, TRUE],
      [[], FALSE, 10, TRUE, 14, FALSE],
      [[], TRUE, 11, TRUE, 14, FALSE],
      [[], TRUE, 10, FALSE, 14, FALSE],
    ] as [$policy, $published, $city, $global, $days, $visible]) {
      $config = $this->createMock(JurisdictionConfigServiceInterface::class);
      $config->method('getSection')->willReturn($policy + ['requestInfoNoticeUuid' => '00000000-0000-4000-8000-000000000001']);
      $node = $this->createMock(NodeInterface::class);
      $node->method('isPublished')->willReturn($published);
      $fields = [
        'field_jurisdiction' => $this->field(['target_id' => $city]),
        'field_organisation' => $this->field([], $global),
        'field_boilerplate_type' => $this->field(['value' => 'remarks']),
        'body' => $this->field(['value' => '<p>§§ 60, 66 SGB I &amp; Mitwirkung</p><p>Fiktiver Hinweis.</p>']),
      ];
      $node->method('get')->willReturnCallback(static fn(string $name) => $fields[$name]);
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('loadByProperties')->willReturn([$node]);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->method('hasDefinition')->willReturn(TRUE);
      $manager->method('getStorage')->willReturn($storage);
      $time = $this->createMock(TimeInterface::class);
      $time->method('getRequestTime')->willReturn(1793962800);
      $dates = $this->createMock(DateFormatterInterface::class);
      $dates->expects($this->once())->method('format')->with(1793962800 + $days * 86400, 'custom', 'd.m.Y')->willReturn('20.11.2026');
      $service = new RequestLetterValues($config, $manager, $dates, $time);
      $values = $service->values($application);
      $this->assertSame('20.11.2026', $values['nachforderung.frist']);
      if ($visible) {
        $this->assertSame("§§ 60, 66 SGB I & Mitwirkung\nFiktiver Hinweis.", $values['nachforderung.mitwirkungshinweis']);
      }
      else {
        $this->assertArrayNotHasKey('nachforderung.mitwirkungshinweis', $values);
      }
      $this->assertSame([], $service->validateSection(['requestInfoDeadlineDays' => 14]));
      $this->assertNotEmpty($service->validateSection(['requestInfoDeadlineDays' => 0]));
      $this->assertNotEmpty($service->validateSection(['requestInfoNoticeUuid' => 'wrong']));
    }
  }

  /**
   * A 14-day deadline stays on the intended date across both DST transitions.
   */
  public function testDeadlineUsesCalendarDays(): void {
    $zone = new \DateTimeZone('Europe/Berlin');
    foreach ([
      ['2026-10-20 00:30:00', '2026-11-03 00:30:00'],
      ['2026-03-20 23:30:00', '2026-04-03 23:30:00'],
    ] as [$start, $expected]) {
      $timestamp = RequestLetterValues::deadlineTimestamp((new \DateTimeImmutable($start, $zone))->getTimestamp(), 14, 'Europe/Berlin');
      $this->assertSame($expected, (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d H:i:s'));
    }
  }

  /**
   * The holiday region is a closed list of the 16 German state codes.
   */
  public function testHolidayRegionIsClosedEnum(): void {
    $service = new RequestLetterValues(NULL, $this->createMock(EntityTypeManagerInterface::class), $this->createMock(DateFormatterInterface::class), $this->createMock(TimeInterface::class));
    foreach (['BB', 'BE', 'BW', 'BY', 'HB', 'HE', 'HH', 'MV', 'NI', 'NW', 'RP', 'SH', 'SL', 'SN', 'ST', 'TH'] as $state) {
      $this->assertSame([], $service->validateSection(['holidayRegion' => 'DE-' . $state]), $state);
    }
    foreach (['DE-XX', 'NW', 'de-nw', 'DE-NW ', 'AT-9', 1, NULL, ['DE-NW']] as $wrong) {
      $this->assertSame(['docgen.holidayRegion must be a German state code like DE-NW.'], $service->validateSection(['holidayRegion' => $wrong]), var_export($wrong, TRUE));
    }
  }

  /**
   * Thursday plus 15 days ends on Good Friday 2027: printed Tuesday 30.03.
   */
  public function testDeadlineEndsOnTheNextWorkingDay(): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');
    try {
      $jurisdiction = $this->createMock(GroupInterface::class);
      $application = $this->createMock(ApplicationInterface::class);
      $application->method('get')->willReturn($this->field(['entity' => $jurisdiction]));
      foreach ([NULL, 'DE-NW'] as $region) {
        $config = $this->createMock(JurisdictionConfigServiceInterface::class);
        $config->method('getSection')->willReturn(['requestInfoDeadlineDays' => 15] + ($region !== NULL ? ['holidayRegion' => $region] : []));
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(FALSE);
        $time = $this->createMock(TimeInterface::class);
        // Thursday 11.03.2027 10:00 Europe/Berlin.
        $time->method('getRequestTime')->willReturn(1804755600);
        $dates = $this->createMock(DateFormatterInterface::class);
        $dates->method('format')->willReturnCallback(static fn (int $timestamp, string $type, string $format): string => date($format, $timestamp));
        $service = new RequestLetterValues($config, $manager, $dates, $time);
        $this->assertSame($region, $service->holidayRegion($application));
        $this->assertSame('2027-03-30', $service->deadline($application)?->format('Y-m-d'));
        $this->assertSame('30.03.2027', $service->values($application)['nachforderung.frist']);
      }
      $orphan = $this->createMock(ApplicationInterface::class);
      $orphan->method('get')->willReturn($this->field(['entity' => NULL]));
      $this->assertNull($service->deadline($orphan));
      $this->assertNull($service->holidayRegion($orphan));
    }
    finally {
      date_default_timezone_set($previous);
    }
  }

  /**
   * Field list double with magic values.
   */
  private function field(array $values, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('__get')->willReturnCallback(static fn(string $key) => $values[$key] ?? NULL);
    return $field;
  }

}
