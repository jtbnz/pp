<?php
declare(strict_types=1);

namespace Portal\Services;

use PDO;

/**
 * Holiday Service
 *
 * Handles public holiday management, caching, and training night date generation.
 * Training nights are every Monday at 19:00 NZST, moved to Tuesday if Monday
 * is an Auckland public holiday.
 */
class HolidayService
{
    private PDO $db;
    private string $apiUrl = 'https://date.nager.at/api/v3/PublicHolidays';
    private string $countryCode = 'NZ';

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Fetch public holidays for a year
     *
     * First checks local cache, then fetches from API if not cached.
     *
     * @param int $year Year to fetch holidays for
     * @return array Holidays with date and name
     */
    public function fetchHolidays(int $year): array
    {
        // Check cache first
        $cached = $this->getCachedHolidays($year);
        if (!empty($cached)) {
            return $cached;
        }

        // Fetch from API
        $holidays = $this->fetchFromApi($year);

        // If API fails, use fallback static holidays
        if (empty($holidays)) {
            $holidays = $this->getFallbackHolidays($year);
        }

        // Cache the holidays
        foreach ($holidays as $holiday) {
            $this->cacheHoliday($holiday['date'], $holiday['name'], $holiday['region'] ?? 'national', $year);
        }

        return $holidays;
    }

    /**
     * Get Auckland-specific holidays (national + Auckland Anniversary)
     *
     * @param int $year Year to get holidays for
     * @return array Auckland holidays
     */
    public function getAucklandHolidays(int $year): array
    {
        return $this->getHolidaysForRegion($year, 'auckland');
    }

    /**
     * Get holidays for a specific region (national + regional anniversary)
     *
     * @param int $year Year to get holidays for
     * @param string $region Region code (e.g., 'auckland', 'nelson', 'wellington')
     * @return array Holidays for that region
     */
    public function getHolidaysForRegion(int $year, string $region): array
    {
        $allHolidays = $this->fetchHolidays($year);

        // Filter to national holidays only first
        $holidays = array_filter($allHolidays, function ($holiday) {
            return ($holiday['region'] ?? 'national') === 'national';
        });

        // Add Auckland Anniversary if the region is Auckland (it's special-cased in fetchFromApi)
        if ($region === 'auckland') {
            foreach ($allHolidays as $holiday) {
                if (($holiday['region'] ?? '') === 'auckland') {
                    $holidays[] = $holiday;
                }
            }
        } else {
            // Add regional anniversary for other regions
            $regionalAnniversary = $this->getRegionalAnniversary($year, $region);
            if ($regionalAnniversary) {
                $holidays[] = $regionalAnniversary;
            }
        }

        return array_values($holidays);
    }

    /**
     * Check if a date is a public holiday
     *
     * @param string $date Date to check (Y-m-d format)
     * @param string $region Region to check (default: 'auckland')
     * @return bool True if public holiday
     */
    public function isPublicHoliday(string $date, string $region = 'auckland'): bool
    {
        $year = (int)date('Y', strtotime($date));
        $holidays = $this->getHolidaysForRegion($year, $region);

        foreach ($holidays as $holiday) {
            if ($holiday['date'] === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the next training date from a given date
     *
     * Training is on Monday at 19:00, moved to Tuesday if Monday is a holiday.
     *
     * @param string $fromDate Starting date (Y-m-d format)
     * @return string Next training date (Y-m-d format)
     */
    public function getNextTrainingDate(string $fromDate): string
    {
        $date = new DateTime($fromDate, new DateTimeZone('Pacific/Auckland'));

        // If current day is past training time, move to next day
        $currentHour = (int)$date->format('H');
        if ($currentHour >= 21) { // After 9 PM, training is over
            $date->modify('+1 day');
        }

        // Find next Monday
        $dayOfWeek = (int)$date->format('N'); // 1 = Monday
        if ($dayOfWeek === 1) {
            // Already Monday - check if training hasn't happened yet
            if ($currentHour < 19) {
                // Training is today
            } else {
                // Training already started/finished, get next Monday
                $date->modify('next Monday');
            }
        } else {
            $date->modify('next Monday');
        }

        $trainingDate = $date->format('Y-m-d');

        // Check if Monday is a holiday
        if ($this->isPublicHoliday($trainingDate)) {
            // Move to Tuesday
            $date->modify('+1 day');
            return $date->format('Y-m-d');
        }

        return $trainingDate;
    }

    /**
     * Generate training dates for a number of months from a start date
     *
     * @param string $fromDate Starting date (Y-m-d format)
     * @param int $months Number of months to generate
     * @return array Training dates with metadata
     */
    public function generateTrainingDates(string $fromDate, int $months = 12): array
    {
        $trainingDates = [];
        $timezone = new DateTimeZone('Pacific/Auckland');

        $startDate = new DateTime($fromDate, $timezone);
        $endDate = clone $startDate;
        $endDate->modify("+{$months} months");

        // Pre-fetch holidays for all relevant years
        $startYear = (int)$startDate->format('Y');
        $endYear = (int)$endDate->format('Y');
        for ($year = $startYear; $year <= $endYear; $year++) {
            $this->getAucklandHolidays($year);
        }

        // Find first Monday on or after start date
        $current = clone $startDate;
        $dayOfWeek = (int)$current->format('N');
        if ($dayOfWeek !== 1) {
            $current->modify('next Monday');
        }

        while ($current < $endDate) {
            $dateStr = $current->format('Y-m-d');
            $trainingTime = '19:00:00';

            $trainingInfo = [
                'date' => $dateStr,
                'original_date' => $dateStr,
                'time' => $trainingTime,
                'is_moved' => false,
                'day_name' => 'Monday',
            ];

            // Check if Monday is a public holiday
            if ($this->isPublicHoliday($dateStr)) {
                // Move to Tuesday
                $tuesday = clone $current;
                $tuesday->modify('+1 day');
                $tuesdayStr = $tuesday->format('Y-m-d');

                $trainingInfo['date'] = $tuesdayStr;
                $trainingInfo['is_moved'] = true;
                $trainingInfo['day_name'] = 'Tuesday';
                $trainingInfo['move_reason'] = $this->getHolidayName($dateStr);
            }

            $trainingDates[] = $trainingInfo;

            // Move to next Monday
            $current->modify('next Monday');
        }

        return $trainingDates;
    }

    /**
     * Get the name of a holiday on a specific date
     *
     * @param string $date Date to check (Y-m-d format)
     * @param string $region Region to check (default: 'auckland')
     * @return string|null Holiday name or null
     */
    public function getHolidayName(string $date, string $region = 'auckland'): ?string
    {
        $year = (int)date('Y', strtotime($date));
        $holidays = $this->getHolidaysForRegion($year, $region);

        foreach ($holidays as $holiday) {
            if ($holiday['date'] === $date) {
                return $holiday['name'];
            }
        }

        return null;
    }

    /**
     * Get cached holidays from database
     *
     * @param int $year Year to get holidays for
     * @return array Cached holidays
     */
    private function getCachedHolidays(int $year): array
    {
        $stmt = $this->db->prepare('
            SELECT date, name, region
            FROM public_holidays
            WHERE year = ?
            ORDER BY date ASC
        ');
        $stmt->execute([$year]);

        return $stmt->fetchAll();
    }

    /**
     * Cache a holiday in the database
     *
     * @param string $date Holiday date
     * @param string $name Holiday name
     * @param string $region Region (national or auckland)
     * @param int $year Year
     */
    private function cacheHoliday(string $date, string $name, string $region, int $year): void
    {
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO public_holidays (date, name, region, year)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$date, $name, $region, $year]);
    }

    /**
     * Regional anniversary day names to filter out from national holidays
     * These are region-specific and should not be treated as national holidays
     */
    private const REGIONAL_ANNIVERSARY_NAMES = [
        'Auckland Anniversary Day',
        'Auckland/Northland Anniversary Day',
        'Northland Anniversary Day',
        'Nelson Anniversary Day',
        'Wellington Anniversary Day',
        'Canterbury Anniversary Day',
        'Otago Anniversary Day',
        'Southland Anniversary Day',
        'Taranaki Anniversary Day',
        "Hawke's Bay Anniversary Day",
        'Marlborough Anniversary Day',
        'Westland Anniversary Day',
        'Chatham Islands Anniversary Day',
        // Common variations
        'Canterbury (South) Anniversary Day',
        'Canterbury (North and Central) Anniversary Day',
    ];

    /**
     * Fetch holidays from external API
     *
     * @param int $year Year to fetch
     * @return array Holidays from API
     */
    private function fetchFromApi(int $year): array
    {
        $url = "{$this->apiUrl}/{$year}/{$this->countryCode}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return [];
        }

        $holidays = [];
        foreach ($data as $holiday) {
            $name = $holiday['localName'] ?? $holiday['name'] ?? '';

            // Skip regional anniversary days - they will be added separately for the configured region
            if ($this->isRegionalAnniversary($name)) {
                continue;
            }

            $holidays[] = [
                'date' => $holiday['date'] ?? '',
                'name' => $name,
                'region' => 'national',
            ];
        }

        // Add Auckland Anniversary (closest Monday to January 29)
        $aucklandAnniversary = $this->calculateAucklandAnniversary($year);
        $holidays[] = [
            'date' => $aucklandAnniversary,
            'name' => 'Auckland Anniversary Day',
            'region' => 'auckland',
        ];

        return $holidays;
    }

    /**
     * Check if a holiday name is a regional anniversary day
     *
     * @param string $name Holiday name
     * @return bool True if it's a regional anniversary
     */
    private function isRegionalAnniversary(string $name): bool
    {
        foreach (self::REGIONAL_ANNIVERSARY_NAMES as $regionalName) {
            if (stripos($name, $regionalName) !== false || stripos($regionalName, $name) !== false) {
                return true;
            }
        }

        // Also check for generic "Anniversary Day" pattern
        if (preg_match('/anniversary\s*day/i', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Calculate Auckland Anniversary Day (Monday closest to January 29)
     *
     * @param int $year Year
     * @return string Date (Y-m-d format)
     */
    private function calculateAucklandAnniversary(int $year): string
    {
        $jan29 = new DateTime("{$year}-01-29", new DateTimeZone('Pacific/Auckland'));
        $dayOfWeek = (int)$jan29->format('N'); // 1=Monday, 7=Sunday

        if ($dayOfWeek === 1) {
            return $jan29->format('Y-m-d');
        }

        // Find closest Monday
        if ($dayOfWeek <= 4) {
            // Thursday or earlier - go back to previous Monday
            $jan29->modify('previous Monday');
        } else {
            // Friday or later - go forward to next Monday
            $jan29->modify('next Monday');
        }

        return $jan29->format('Y-m-d');
    }

    /**
     * Get fallback static holidays when API is unavailable
     *
     * @param int $year Year
     * @return array Static holidays
     */
    private function getFallbackHolidays(int $year): array
    {
        $holidays = [];

        // New Year's Day (and observed dates)
        $newYears = new DateTime("{$year}-01-01", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $newYears->format('Y-m-d'), 'name' => "New Year's Day", 'region' => 'national'];

        $newYears2 = new DateTime("{$year}-01-02", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $newYears2->format('Y-m-d'), 'name' => 'Day after New Year\'s Day', 'region' => 'national'];

        // Auckland Anniversary
        $holidays[] = [
            'date' => $this->calculateAucklandAnniversary($year),
            'name' => 'Auckland Anniversary Day',
            'region' => 'auckland',
        ];

        // Waitangi Day - February 6 (Mondayised if on weekend from 2016)
        $waitangi = new DateTime("{$year}-02-06", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $this->mondayise($waitangi), 'name' => 'Waitangi Day', 'region' => 'national'];

        // Good Friday & Easter Monday (calculated)
        $easter = $this->calculateEasterSunday($year);
        $goodFriday = clone $easter;
        $goodFriday->modify('-2 days');
        $holidays[] = ['date' => $goodFriday->format('Y-m-d'), 'name' => 'Good Friday', 'region' => 'national'];

        $easterMonday = clone $easter;
        $easterMonday->modify('+1 day');
        $holidays[] = ['date' => $easterMonday->format('Y-m-d'), 'name' => 'Easter Monday', 'region' => 'national'];

        // ANZAC Day - April 25 (Mondayised if on weekend from 2016)
        $anzac = new DateTime("{$year}-04-25", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $this->mondayise($anzac), 'name' => 'ANZAC Day', 'region' => 'national'];

        // King's Birthday - First Monday in June
        $kingsDay = new DateTime("first Monday of June {$year}", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $kingsDay->format('Y-m-d'), 'name' => "King's Birthday", 'region' => 'national'];

        // Matariki (variable - last Friday in June or early July)
        $matariki = $this->calculateMatariki($year);
        if ($matariki) {
            $holidays[] = ['date' => $matariki, 'name' => 'Matariki', 'region' => 'national'];
        }

        // Labour Day - Fourth Monday in October
        $labourDay = new DateTime("fourth Monday of October {$year}", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $labourDay->format('Y-m-d'), 'name' => 'Labour Day', 'region' => 'national'];

        // Christmas Day
        $christmas = new DateTime("{$year}-12-25", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $this->mondayise($christmas), 'name' => 'Christmas Day', 'region' => 'national'];

        // Boxing Day
        $boxingDay = new DateTime("{$year}-12-26", new DateTimeZone('Pacific/Auckland'));
        $holidays[] = ['date' => $this->mondayise($boxingDay, true), 'name' => 'Boxing Day', 'region' => 'national'];

        return $holidays;
    }

    /**
     * Calculate Easter Sunday using the Anonymous Gregorian algorithm
     *
     * @param int $year Year
     * @return DateTime Easter Sunday date
     */
    private function calculateEasterSunday(int $year): DateTime
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTime("{$year}-{$month}-{$day}", new DateTimeZone('Pacific/Auckland'));
    }

    /**
     * Mondayise a holiday if it falls on a weekend
     *
     * @param DateTime $date The holiday date
     * @param bool $isSecondHoliday If true, this is a second consecutive holiday (moves to Tuesday if both on weekend)
     * @return string Mondayised date (Y-m-d)
     */
    private function mondayise(DateTime $date, bool $isSecondHoliday = false): string
    {
        $dayOfWeek = (int)$date->format('N');

        if ($dayOfWeek === 6) { // Saturday
            $date->modify('+2 days'); // Move to Monday
        } elseif ($dayOfWeek === 7) { // Sunday
            $date->modify('+1 day'); // Move to Monday
            if ($isSecondHoliday) {
                $date->modify('+1 day'); // Move to Tuesday for second holiday
            }
        }

        return $date->format('Y-m-d');
    }

    /**
     * Calculate Matariki date
     *
     * Matariki dates are set by the NZ government for specific years.
     *
     * @param int $year Year
     * @return string|null Matariki date or null if not available
     */
    private function calculateMatariki(int $year): ?string
    {
        // Matariki dates as published by the NZ government
        $matarikiDates = [
            2022 => '2022-06-24',
            2023 => '2023-07-14',
            2024 => '2024-06-28',
            2025 => '2025-06-20',
            2026 => '2026-07-10',
            2027 => '2027-06-25',
            2028 => '2028-07-14',
            2029 => '2029-07-06',
            2030 => '2030-06-21',
            2031 => '2031-07-11',
            2032 => '2032-07-02',
            2033 => '2033-06-24',
            2034 => '2034-07-07',
            2035 => '2035-06-29',
            2036 => '2036-07-18',
            2037 => '2037-07-10',
            2038 => '2038-06-25',
            2039 => '2039-07-15',
            2040 => '2040-07-06',
            2041 => '2041-07-19',
            2042 => '2042-07-11',
            2043 => '2043-07-03',
            2044 => '2044-06-24',
            2045 => '2045-07-07',
            2046 => '2046-06-29',
            2047 => '2047-07-19',
            2048 => '2048-07-03',
            2049 => '2049-06-25',
            2050 => '2050-07-15',
            2051 => '2051-06-30',
            2052 => '2052-06-21',
        ];

        return $matarikiDates[$year] ?? null;
    }

    /**
     * Get holidays for a date range
     *
     * Returns holidays for all dates within the specified range,
     * filtering by region (national + selected province).
     *
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate End date (Y-m-d)
     * @param string $region Region to filter (default: 'auckland')
     * @return array Holidays indexed by date
     */
    public function getHolidaysForDateRange(string $fromDate, string $toDate, string $region = 'auckland'): array
    {
        // Get all years in the range
        $startYear = (int)date('Y', strtotime($fromDate));
        $endYear = (int)date('Y', strtotime($toDate));

        // Fetch holidays for all relevant years
        $allHolidays = [];
        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearHolidays = $this->fetchHolidays($year);
            foreach ($yearHolidays as $holiday) {
                // Include national holidays and holidays for the selected region
                $holidayRegion = $holiday['region'] ?? 'national';
                if ($holidayRegion === 'national' || $holidayRegion === $region) {
                    $allHolidays[] = $holiday;
                }
            }
        }

        // Filter to date range and index by date
        $holidaysByDate = [];
        $from = strtotime($fromDate);
        $to = strtotime($toDate);

        foreach ($allHolidays as $holiday) {
            $holidayTimestamp = strtotime($holiday['date']);
            if ($holidayTimestamp >= $from && $holidayTimestamp <= $to) {
                $holidaysByDate[$holiday['date']] = [
                    'name' => $holiday['name'],
                    'region' => $holiday['region'] ?? 'national',
                ];
            }
        }

        return $holidaysByDate;
    }

    /**
     * Get list of supported NZ regions for holidays
     *
     * @return array Region codes and names
     */
    public static function getSupportedRegions(): array
    {
        return [
            'auckland' => 'Auckland',
            'wellington' => 'Wellington',
            'canterbury' => 'Canterbury',
            'otago' => 'Otago',
            'southland' => 'Southland',
            'taranaki' => 'Taranaki',
            'hawkes-bay' => "Hawke's Bay",
            'marlborough' => 'Marlborough',
            'nelson' => 'Nelson',
            'westland' => 'Westland',
            'chatham-islands' => 'Chatham Islands',
        ];
    }

    /**
     * Calculate regional anniversary days for other provinces
     *
     * @param int $year Year
     * @param string $region Region code
     * @return array|null Holiday data or null
     */
    public function getRegionalAnniversary(int $year, string $region): ?array
    {
        // Regional anniversary days in NZ
        $anniversaries = [
            'wellington' => ['base_date' => '01-22', 'name' => 'Wellington Anniversary Day'],
            'canterbury' => ['base_date' => '11-16', 'name' => 'Canterbury Anniversary Day'],
            'otago' => ['base_date' => '03-23', 'name' => 'Otago Anniversary Day'],
            'southland' => ['base_date' => '01-17', 'name' => 'Southland Anniversary Day'],
            'taranaki' => ['base_date' => '03-31', 'name' => 'Taranaki Anniversary Day'],
            'hawkes-bay' => ['base_date' => '11-01', 'name' => "Hawke's Bay Anniversary Day"],
            'marlborough' => ['base_date' => '11-01', 'name' => 'Marlborough Anniversary Day'],
            'nelson' => ['base_date' => '02-01', 'name' => 'Nelson Anniversary Day'],
            'westland' => ['base_date' => '12-01', 'name' => 'Westland Anniversary Day'],
            'chatham-islands' => ['base_date' => '11-30', 'name' => 'Chatham Islands Anniversary Day'],
        ];

        if (!isset($anniversaries[$region])) {
            return null;
        }

        $anniversary = $anniversaries[$region];
        $baseDate = new DateTime("{$year}-{$anniversary['base_date']}", new DateTimeZone('Pacific/Auckland'));

        // Find closest Monday
        $dayOfWeek = (int)$baseDate->format('N');
        if ($dayOfWeek !== 1) {
            if ($dayOfWeek <= 4) {
                $baseDate->modify('previous Monday');
            } else {
                $baseDate->modify('next Monday');
            }
        }

        return [
            'date' => $baseDate->format('Y-m-d'),
            'name' => $anniversary['name'],
            'region' => $region,
        ];
    }

    /**
     * Clear holiday cache for a year
     *
     * @param int $year Year to clear cache for
     */
    public function clearCache(int $year): void
    {
        $stmt = $this->db->prepare('DELETE FROM public_holidays WHERE year = ?');
        $stmt->execute([$year]);
    }

    /**
     * Refresh holidays from API for a year
     *
     * @param int $year Year to refresh
     * @return array Refreshed holidays
     */
    public function refreshHolidays(int $year): array
    {
        $this->clearCache($year);
        return $this->fetchHolidays($year);
    }
}
