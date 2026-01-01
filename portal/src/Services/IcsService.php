<?php
declare(strict_types=1);

/**
 * ICS Service
 *
 * Generates ICS (iCalendar) files for events.
 * RFC 5545 compliant.
 */
class IcsService
{
    private const CRLF = "\r\n";

    /**
     * Generate an ICS file for a single event
     *
     * @param array $event Event data
     * @return string ICS content
     */
    public function generateEvent(array $event): string
    {
        $calendar = $this->calendarHeader();
        $calendar .= $this->eventToVEvent($event);
        $calendar .= $this->calendarFooter();

        return $calendar;
    }

    /**
     * Generate an ICS calendar file for multiple events
     *
     * @param array $events Array of events
     * @return string ICS content
     */
    public function generateCalendar(array $events): string
    {
        $calendar = $this->calendarHeader();

        foreach ($events as $event) {
            $calendar .= $this->eventToVEvent($event);
        }

        $calendar .= $this->calendarFooter();

        return $calendar;
    }

    /**
     * Generate calendar header
     *
     * @return string ICS header
     */
    private function calendarHeader(): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Puke Portal//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Puke Volunteer Fire Brigade',
            'X-WR-TIMEZONE:Pacific/Auckland',
        ];

        // Add timezone component
        $lines = array_merge($lines, $this->timezoneComponent());

        return implode(self::CRLF, $lines) . self::CRLF;
    }

    /**
     * Generate calendar footer
     *
     * @return string ICS footer
     */
    private function calendarFooter(): string
    {
        return 'END:VCALENDAR' . self::CRLF;
    }

    /**
     * Generate timezone component for Pacific/Auckland
     *
     * @return array Timezone lines
     */
    private function timezoneComponent(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:Pacific/Auckland',
            'BEGIN:STANDARD',
            'DTSTART:19700405T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1SU',
            'TZOFFSETFROM:+1300',
            'TZOFFSETTO:+1200',
            'TZNAME:NZST',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:19700927T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=9;BYDAY=-1SU',
            'TZOFFSETFROM:+1200',
            'TZOFFSETTO:+1300',
            'TZNAME:NZDT',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ];
    }

    /**
     * Convert an event array to a VEVENT component
     *
     * @param array $event Event data
     * @return string VEVENT component
     */
    private function eventToVEvent(array $event): string
    {
        $lines = ['BEGIN:VEVENT'];

        // UID - unique identifier
        $uid = $this->generateUid($event);
        $lines[] = 'UID:' . $uid;

        // Timestamps
        $dtstamp = gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTAMP:' . $dtstamp;

        // Created/Updated timestamps
        if (!empty($event['created_at'])) {
            $lines[] = 'CREATED:' . $this->formatDateTime($event['created_at'], true);
        }
        if (!empty($event['updated_at'])) {
            $lines[] = 'LAST-MODIFIED:' . $this->formatDateTime($event['updated_at'], true);
        }

        // Start time
        if (!empty($event['all_day']) && $event['all_day']) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $this->formatDate($event['start_time']);
            if (!empty($event['end_time'])) {
                $lines[] = 'DTEND;VALUE=DATE:' . $this->formatDate($event['end_time']);
            }
        } else {
            $lines[] = 'DTSTART;TZID=Pacific/Auckland:' . $this->formatDateTime($event['start_time']);
            if (!empty($event['end_time'])) {
                $lines[] = 'DTEND;TZID=Pacific/Auckland:' . $this->formatDateTime($event['end_time']);
            }
        }

        // Title
        $lines[] = 'SUMMARY:' . $this->escapeText($event['title'] ?? 'Event');

        // Description
        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . $this->escapeText($event['description']);
        }

        // Location
        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . $this->escapeText($event['location']);
        }

        // Recurrence rule (if any)
        if (!empty($event['recurrence_rule'])) {
            $rrule = $event['recurrence_rule'];
            // Ensure proper format
            if (!str_starts_with(strtoupper($rrule), 'RRULE:')) {
                $rrule = 'RRULE:' . $rrule;
            }
            $lines[] = $rrule;
        }

        // Categories
        $categories = [];
        if (!empty($event['is_training'])) {
            $categories[] = 'Training';
        }
        if (!empty($categories)) {
            $lines[] = 'CATEGORIES:' . implode(',', $categories);
        }

        // Status
        $lines[] = 'STATUS:CONFIRMED';

        // Transparency
        $lines[] = 'TRANSP:OPAQUE';

        // Organizer (if we have creator info)
        if (!empty($event['creator_name'])) {
            $lines[] = 'ORGANIZER;CN=' . $this->escapeText($event['creator_name']) . ':mailto:noreply@pukefire.nz';
        }

        $lines[] = 'END:VEVENT';

        return implode(self::CRLF, array_map([$this, 'foldLine'], $lines)) . self::CRLF;
    }

    /**
     * Generate a unique identifier for an event
     *
     * @param array $event Event data
     * @return string UID
     */
    private function generateUid(array $event): string
    {
        $id = $event['id'] ?? uniqid();
        $date = $event['instance_date'] ?? date('Ymd', strtotime($event['start_time'] ?? 'now'));

        return $id . '-' . $date . '@pukeportal.nz';
    }

    /**
     * Format a datetime for ICS (local time with timezone)
     *
     * @param string $datetime Datetime string
     * @param bool $utc Whether to convert to UTC
     * @return string Formatted datetime
     */
    private function formatDateTime(string $datetime, bool $utc = false): string
    {
        $dt = new DateTime($datetime, new DateTimeZone('Pacific/Auckland'));

        if ($utc) {
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Ymd\THis\Z');
        }

        return $dt->format('Ymd\THis');
    }

    /**
     * Format a date for ICS (all-day events)
     *
     * @param string $datetime Datetime string
     * @return string Formatted date
     */
    private function formatDate(string $datetime): string
    {
        $dt = new DateTime($datetime, new DateTimeZone('Pacific/Auckland'));
        return $dt->format('Ymd');
    }

    /**
     * Escape special characters in text for ICS
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    private function escapeText(string $text): string
    {
        // Replace special characters per RFC 5545
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);

        return $text;
    }

    /**
     * Fold long lines per RFC 5545 (max 75 octets)
     *
     * @param string $line Line to fold
     * @return string Folded line
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $result = '';
        $lineLength = 0;

        // Use mb_str_split for multi-byte safety
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = str_split($line);
        }

        foreach ($chars as $char) {
            $charLen = strlen($char);

            // If adding this char would exceed 75 chars (or 74 for continuation lines)
            if ($lineLength + $charLen > 75) {
                $result .= self::CRLF . ' ';
                $lineLength = 1; // Space counts as 1
            }

            $result .= $char;
            $lineLength += $charLen;
        }

        return $result;
    }

    /**
     * Set appropriate HTTP headers for ICS download
     *
     * @param string $filename Filename for download
     */
    public function setDownloadHeaders(string $filename = 'event.ics'): void
    {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Generate a filename for an event
     *
     * @param array $event Event data
     * @return string Filename
     */
    public function generateFilename(array $event): string
    {
        $title = $event['title'] ?? 'event';
        // Sanitize for filename
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');

        if (empty($filename)) {
            $filename = 'event';
        }

        $date = date('Y-m-d', strtotime($event['start_time'] ?? 'now'));

        return strtolower($filename) . '_' . $date . '.ics';
    }
}
