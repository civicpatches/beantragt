<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Service\WorkingDays;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A deadline ends on a working day (section 26 (3) SGB X).
 */
#[Group('beantragt_docgen')]
final class WorkingDaysTest extends UnitTestCase {

  /**
   * Gauss's Easter algorithm against known dates.
   */
  public function testEasterSunday(): void {
    foreach ([
      2024 => '2024-03-31',
      2025 => '2025-04-20',
      2026 => '2026-04-05',
      2027 => '2027-03-28',
      2028 => '2028-04-16',
      2038 => '2038-04-25',
    ] as $year => $expected) {
      $this->assertSame($expected, WorkingDays::easterSunday($year)->format('Y-m-d'), (string) $year);
    }
  }

  /**
   * Weekend, nationwide and state holidays move the end to the next workday.
   */
  #[DataProvider('cases')]
  public function testNextWorkingDay(string $day, ?string $region, string $expected): void {
    $zone = new \DateTimeZone('Europe/Berlin');
    $result = WorkingDays::nextWorkingDay(new \DateTimeImmutable($day . ' 10:00', $zone), $region);
    $this->assertSame($expected, $result->format('Y-m-d'));
    // The time of day and the zone stay as they were.
    $this->assertSame('10:00', $result->format('H:i'));
    $this->assertSame('Europe/Berlin', $result->getTimezone()->getName());
  }

  /**
   * Day, region, expected end.
   *
   * @return array<string, array{string, ?string, string}>
   *   Cases.
   */
  public static function cases(): array {
    return [
      'saturday' => ['2027-03-13', NULL, '2027-03-15'],
      'sunday' => ['2027-03-14', NULL, '2027-03-15'],
      'working day stays' => ['2027-03-15', NULL, '2027-03-15'],
      'good friday to tuesday after easter monday' => ['2027-03-26', NULL, '2027-03-30'],
      'whit monday' => ['2027-05-17', NULL, '2027-05-18'],
      'german unity day' => ['2028-10-03', NULL, '2028-10-04'],
      'christmas over the weekend' => ['2026-12-25', NULL, '2026-12-28'],
      'new year over the weekend' => ['2027-01-01', NULL, '2027-01-04'],
      'corpus christi in NW' => ['2027-05-27', 'DE-NW', '2027-05-28'],
      'corpus christi without region' => ['2027-05-27', NULL, '2027-05-27'],
      'all saints in BY' => ['2027-11-01', 'DE-BY', '2027-11-02'],
      'all saints not in NI' => ['2027-11-01', 'DE-NI', '2027-11-01'],
      'reformation day in NI' => ['2028-10-31', 'DE-NI', '2028-11-01'],
      'repentance day in SN' => ['2026-11-18', 'DE-SN', '2026-11-19'],
      'repentance day not in NW' => ['2026-11-18', 'DE-NW', '2026-11-18'],
      'world children day in TH' => ['2027-09-20', 'DE-TH', '2027-09-21'],
      'womens day in BE' => ['2027-03-08', 'DE-BE', '2027-03-09'],
      'assumption in BY statewide' => ['2028-08-15', 'DE-BY', '2028-08-16'],
      'epiphany in ST' => ['2027-01-06', 'DE-ST', '2027-01-07'],
      'augsburg peace festival in BY statewide' => ['2028-08-08', 'DE-BY', '2028-08-09'],
    ];
  }

  /**
   * Unknown regions are refused rather than silently ignored.
   */
  public function testUnknownRegionIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    WorkingDays::isHoliday(new \DateTimeImmutable('2027-05-27'), 'DE-XX');
  }

}
