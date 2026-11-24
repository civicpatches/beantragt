<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

/**
 * Working days for printed deadlines (section 26 (3) sentence 1 SGB X).
 *
 * A deadline that ends on a Saturday, a Sunday or a public holiday ends on
 * the next working day. Nationwide holidays are fixed here (Easter by
 * Gauss's algorithm, no ext-calendar); state holidays come from the
 * jurisdiction's docgen.holidayRegion. Holidays that apply only in parts of
 * a state (Assumption in Bavaria, the municipality-specific peace festival,
 * Corpus Christi in parts of Saxony and Thuringia) count for the whole state: a
 * later printed deadline is always on the safe side for the person.
 */
final class WorkingDays {

  public const REGIONS = [
    'DE-BB', 'DE-BE', 'DE-BW', 'DE-BY', 'DE-HB', 'DE-HE', 'DE-HH', 'DE-MV',
    'DE-NI', 'DE-NW', 'DE-RP', 'DE-SH', 'DE-SL', 'DE-SN', 'DE-ST', 'DE-TH',
  ];

  /**
   * Fixed-date state holidays, "m-d" => regions.
   */
  private const FIXED_REGIONAL = [
    '01-06' => ['DE-BW', 'DE-BY', 'DE-ST'],
    '03-08' => ['DE-BE', 'DE-MV'],
    '08-08' => ['DE-BY'],
    '08-15' => ['DE-BY', 'DE-SL'],
    '09-20' => ['DE-TH'],
    '10-31' => ['DE-BB', 'DE-HB', 'DE-HH', 'DE-MV', 'DE-NI', 'DE-SN', 'DE-ST', 'DE-SH', 'DE-TH'],
    '11-01' => ['DE-BW', 'DE-BY', 'DE-NW', 'DE-RP', 'DE-SL'],
  ];

  /**
   * Corpus Christi (Easter + 60 days).
   */
  private const CORPUS_CHRISTI = ['DE-BW', 'DE-BY', 'DE-HE', 'DE-NW', 'DE-RP', 'DE-SL', 'DE-SN', 'DE-TH'];

  /**
   * Easter Sunday of a Gregorian year (Gauss, with Lichtenberg's correction).
   */
  public static function easterSunday(int $year): \DateTimeImmutable {
    $k = intdiv($year, 100);
    $m = 15 + intdiv(3 * $k + 3, 4) - intdiv(8 * $k + 13, 25);
    $s = 2 - intdiv(3 * $k + 3, 4);
    $a = $year % 19;
    $d = (19 * $a + $m) % 30;
    $r = intdiv($d + intdiv($a, 11), 29);
    $og = 21 + $d - $r;
    $sz = 7 - ($year + intdiv($year, 4) + $s) % 7;
    $oe = 7 - ($og - $sz) % 7;
    $day = $og + $oe;
    return (new \DateTimeImmutable(sprintf('%04d-03-01', $year), new \DateTimeZone('UTC')))->modify('+' . ($day - 1) . ' days');
  }

  /**
   * TRUE for a nationwide holiday or a holiday of the given state.
   */
  public static function isHoliday(\DateTimeImmutable $day, ?string $region): bool {
    if ($region !== NULL && !in_array($region, self::REGIONS, TRUE)) {
      throw new \InvalidArgumentException('Unknown holiday region.');
    }
    $year = (int) $day->format('Y');
    $date = $day->format('m-d');
    if (in_array($date, ['01-01', '05-01', '10-03', '12-25', '12-26'], TRUE)) {
      return TRUE;
    }
    $easter = self::easterSunday($year);
    $offset = static fn (int $days): string => $easter->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('m-d');
    if (in_array($date, [$offset(-2), $offset(1), $offset(39), $offset(50)], TRUE)) {
      return TRUE;
    }
    if ($region === NULL) {
      return FALSE;
    }
    if (in_array($region, self::FIXED_REGIONAL[$date] ?? [], TRUE)) {
      return TRUE;
    }
    if ($date === $offset(60) && in_array($region, self::CORPUS_CHRISTI, TRUE)) {
      return TRUE;
    }
    // Day of Repentance and Prayer: the Wednesday before 23 November.
    if ($region === 'DE-SN') {
      $before = new \DateTimeImmutable(sprintf('%04d-11-22', $year), new \DateTimeZone('UTC'));
      $repentance = $before->modify('-' . (((int) $before->format('N') - 3 + 7) % 7) . ' days');
      return $date === $repentance->format('m-d');
    }
    return FALSE;
  }

  /**
   * The day itself, or the next day that is no weekend day and no holiday.
   *
   * Time of day and timezone of the given day are kept.
   */
  public static function nextWorkingDay(\DateTimeImmutable $day, ?string $region): \DateTimeImmutable {
    while ((int) $day->format('N') >= 6 || self::isHoliday($day, $region)) {
      $day = $day->modify('+1 day');
    }
    return $day;
  }

}
