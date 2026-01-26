<?php
declare(strict_types=1);

namespace Portal\Models;

use PDO;
use DateTime;
use DateTimeZone;

/**
 * Event Model
 *
 * Handles database operations for calendar events including recurring events
 * and training nights.
 */
class Event
{
    private PDO $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Find an event by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT e.*, m.name as creator_name
            FROM events e
            LEFT JOIN members m ON e.created_by = m.id
            WHERE e.id = ?
        ');
        $stmt->execute([$id]);
        $event = $stmt->fetch();

        return $event ?: null;
    }

    /**
     * Find events within a date range for a brigade
     *
     * @param int $brigadeId Brigade ID
     * @param string $from Start date (Y-m-d)
     * @param string $to End date (Y-m-d)
     * @return array Events including expanded recurring events
     */
    public function findByDateRange(int $brigadeId, string $from, string $to): array
    {
        // Get non-recurring events in date range
        $stmt = $this->db->prepare('
            SELECT e.*, m.name as creator_name
            FROM events e
            LEFT JOIN members m ON e.created_by = m.id
            WHERE e.brigade_id = ?
              AND e.is_visible = 1
              AND e.recurrence_rule IS NULL
              AND DATE(e.start_time) >= ?
              AND DATE(e.start_time) <= ?
            ORDER BY e.start_time ASC
        ');
        $stmt->execute([$brigadeId, $from, $to]);
        $singleEvents = $stmt->fetchAll();

        // Get recurring events that could have instances in date range
        $stmt = $this->db->prepare('
            SELECT e.*, m.name as creator_name
            FROM events e
            LEFT JOIN members m ON e.created_by = m.id
            WHERE e.brigade_id = ?
              AND e.is_visible = 1
              AND e.recurrence_rule IS NOT NULL
              AND DATE(e.start_time) <= ?
            ORDER BY e.start_time ASC
        ');
        $stmt->execute([$brigadeId, $to]);
        $recurringEvents = $stmt->fetchAll();

        // Expand recurring events
        $expandedEvents = [];
        foreach ($recurringEvents as $event) {
            $instances = $this->expandRecurring($event, $from, $to);
            $expandedEvents = array_merge($expandedEvents, $instances);
        }

        // Combine and sort by start time
        $allEvents = array_merge($singleEvents, $expandedEvents);
        usort($allEvents, function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $allEvents;
    }

    /**
     * Find training nights within a date range
     *
     * @param int $brigadeId Brigade ID
     * @param string $from Start date (Y-m-d)
     * @param string $to End date (Y-m-d)
     * @return array Training night events
     */
    public function findTrainingNights(int $brigadeId, string $from, string $to): array
    {
        $stmt = $this->db->prepare('
            SELECT e.*, m.name as creator_name
            FROM events e
            LEFT JOIN members m ON e.created_by = m.id
            WHERE e.brigade_id = ?
              AND e.is_training = 1
              AND e.is_visible = 1
              AND DATE(e.start_time) >= ?
              AND DATE(e.start_time) <= ?
            ORDER BY e.start_time ASC
        ');
        $stmt->execute([$brigadeId, $from, $to]);

        return $stmt->fetchAll();
    }

    /**
     * Create a new event
     *
     * @param array $data Event data
     * @return int New event ID
     */
    /**
     * Valid event types with their display colors
     */
    public const EVENT_TYPES = [
        'training' => ['label' => 'Training', 'color' => '#D32F2F'],      // Red
        'meeting' => ['label' => 'Meeting', 'color' => '#1976D2'],        // Blue
        'social' => ['label' => 'Social', 'color' => '#388E3C'],          // Green
        'firewise' => ['label' => 'Firewise', 'color' => '#F57C00'],      // Orange
        'other' => ['label' => 'Other', 'color' => '#757575'],            // Grey
    ];

    public function create(array $data): int
    {
        // Determine event_type based on is_training flag if not explicitly set
        $eventType = $data['event_type'] ?? (($data['is_training'] ?? 0) ? 'training' : 'other');

        $stmt = $this->db->prepare('
            INSERT INTO events (
                brigade_id, title, description, location,
                start_time, end_time, all_day, recurrence_rule,
                is_training, event_type, is_visible, created_by
            ) VALUES (
                :brigade_id, :title, :description, :location,
                :start_time, :end_time, :all_day, :recurrence_rule,
                :is_training, :event_type, :is_visible, :created_by
            )
        ');

        $stmt->execute([
            'brigade_id' => $data['brigade_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'] ?? null,
            'all_day' => $data['all_day'] ?? 0,
            'recurrence_rule' => $data['recurrence_rule'] ?? null,
            'is_training' => $data['is_training'] ?? 0,
            'event_type' => $eventType,
            'is_visible' => $data['is_visible'] ?? 1,
            'created_by' => $data['created_by'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an existing event
     *
     * @param int $id Event ID
     * @param array $data Updated event data
     * @return bool Success
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = [
            'title', 'description', 'location', 'start_time', 'end_time',
            'all_day', 'recurrence_rule', 'is_training', 'is_visible', 'event_type'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $values[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values['id'] = $id;
        $sql = 'UPDATE events SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($values);
    }

    /**
     * Delete an event
     *
     * @param int $id Event ID
     * @return bool Success
     */
    public function delete(int $id): bool
    {
        // Also delete any exceptions
        $stmt = $this->db->prepare('DELETE FROM event_exceptions WHERE event_id = ?');
        $stmt->execute([$id]);

        $stmt = $this->db->prepare('DELETE FROM events WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /**
     * Add an exception to a recurring event
     *
     * @param int $eventId Event ID
     * @param array $data Exception data (exception_date, is_cancelled, replacement_date, notes)
     * @return int New exception ID
     */
    public function addException(int $eventId, array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO event_exceptions (event_id, exception_date, is_cancelled, replacement_date, notes)
            VALUES (:event_id, :exception_date, :is_cancelled, :replacement_date, :notes)
        ');

        $stmt->execute([
            'event_id' => $eventId,
            'exception_date' => $data['exception_date'],
            'is_cancelled' => $data['is_cancelled'] ?? 1,
            'replacement_date' => $data['replacement_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get exceptions for an event
     *
     * @param int $eventId Event ID
     * @return array Event exceptions
     */
    public function getExceptions(int $eventId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM event_exceptions
            WHERE event_id = ?
            ORDER BY exception_date ASC
        ');
        $stmt->execute([$eventId]);

        return $stmt->fetchAll();
    }

    /**
     * Expand a recurring event into individual instances within a date range
     *
     * Supports simple RRULE patterns:
     * - FREQ=WEEKLY;BYDAY=MO (Every Monday)
     * - FREQ=WEEKLY;INTERVAL=2;BYDAY=FR (Every other Friday)
     * - FREQ=MONTHLY;BYMONTHDAY=15 (15th of each month)
     *
     * @param array $event The recurring event
     * @param string $from Start date (Y-m-d)
     * @param string $to End date (Y-m-d)
     * @return array Expanded event instances
     */
    public function expandRecurring(array $event, string $from, string $to): array
    {
        if (empty($event['recurrence_rule'])) {
            return [$event];
        }

        $instances = [];
        $rule = $this->parseRRule($event['recurrence_rule']);

        if (empty($rule['FREQ'])) {
            return [];
        }

        // Get exceptions for this event
        $exceptions = $this->getExceptions((int)$event['id']);
        $exceptionDates = [];
        $replacements = [];
        foreach ($exceptions as $exc) {
            $exceptionDates[$exc['exception_date']] = $exc['is_cancelled'];
            if (!$exc['is_cancelled'] && $exc['replacement_date']) {
                $replacements[$exc['exception_date']] = $exc['replacement_date'];
            }
        }

        // Parse event start time
        $startDateTime = new DateTime($event['start_time'], new DateTimeZone('Pacific/Auckland'));
        $endDateTime = $event['end_time']
            ? new DateTime($event['end_time'], new DateTimeZone('Pacific/Auckland'))
            : null;
        $duration = $endDateTime ? $startDateTime->diff($endDateTime) : null;

        $fromDate = new DateTime($from, new DateTimeZone('Pacific/Auckland'));
        $toDate = new DateTime($to, new DateTimeZone('Pacific/Auckland'));
        $toDate->setTime(23, 59, 59);

        $interval = (int)($rule['INTERVAL'] ?? 1);
        $count = isset($rule['COUNT']) ? (int)$rule['COUNT'] : null;
        $until = isset($rule['UNTIL']) ? new DateTime($rule['UNTIL'], new DateTimeZone('Pacific/Auckland')) : null;

        $current = clone $startDateTime;
        $instanceCount = 0;
        $maxIterations = 1000; // Safety limit
        $iterations = 0;

        while ($iterations++ < $maxIterations) {
            // Check termination conditions
            if ($current > $toDate) {
                break;
            }
            if ($until !== null && $current > $until) {
                break;
            }
            if ($count !== null && $instanceCount >= $count) {
                break;
            }

            // Check if this date is in range
            $dateStr = $current->format('Y-m-d');

            if ($current >= $fromDate && $current <= $toDate) {
                // Check for exceptions
                if (isset($exceptionDates[$dateStr]) && $exceptionDates[$dateStr]) {
                    // Event is cancelled on this date - skip
                } elseif (isset($replacements[$dateStr])) {
                    // Event is moved to a different date
                    $replacementDate = new DateTime($replacements[$dateStr], new DateTimeZone('Pacific/Auckland'));
                    $replacementDate->setTime(
                        (int)$current->format('H'),
                        (int)$current->format('i'),
                        (int)$current->format('s')
                    );

                    $instance = $event;
                    $instance['start_time'] = $replacementDate->format('Y-m-d H:i:s');
                    if ($duration) {
                        $endInstance = clone $replacementDate;
                        $endInstance->add($duration);
                        $instance['end_time'] = $endInstance->format('Y-m-d H:i:s');
                    }
                    $instance['original_date'] = $dateStr;
                    $instance['is_moved'] = true;
                    $instance['instance_date'] = $replacementDate->format('Y-m-d');
                    $instances[] = $instance;
                } else {
                    // Normal instance
                    $instance = $event;
                    $instance['start_time'] = $current->format('Y-m-d H:i:s');
                    if ($duration) {
                        $endInstance = clone $current;
                        $endInstance->add($duration);
                        $instance['end_time'] = $endInstance->format('Y-m-d H:i:s');
                    }
                    $instance['instance_date'] = $dateStr;
                    $instances[] = $instance;
                }
            }

            $instanceCount++;

            // Advance to next occurrence based on frequency
            switch ($rule['FREQ']) {
                case 'DAILY':
                    $current->modify("+{$interval} days");
                    break;

                case 'WEEKLY':
                    if (isset($rule['BYDAY'])) {
                        // Move to next specified day
                        $current = $this->nextWeekday($current, $rule['BYDAY'], $interval);
                    } else {
                        $current->modify("+{$interval} weeks");
                    }
                    break;

                case 'MONTHLY':
                    if (isset($rule['BYMONTHDAY'])) {
                        $day = (int)$rule['BYMONTHDAY'];
                        $current->modify("+{$interval} months");
                        $current->setDate(
                            (int)$current->format('Y'),
                            (int)$current->format('m'),
                            min($day, (int)$current->format('t'))
                        );
                    } else {
                        $current->modify("+{$interval} months");
                    }
                    break;

                case 'YEARLY':
                    $current->modify("+{$interval} years");
                    break;

                default:
                    // Unknown frequency, stop
                    $iterations = $maxIterations;
            }
        }

        return $instances;
    }

    /**
     * Parse an RRULE string into components
     *
     * @param string $rule RRULE string (e.g., "FREQ=WEEKLY;BYDAY=MO")
     * @return array Parsed rule components
     */
    private function parseRRule(string $rule): array
    {
        // Remove RRULE: prefix if present
        $rule = preg_replace('/^RRULE:/i', '', $rule);

        $parts = explode(';', $rule);
        $parsed = [];

        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $parsed[strtoupper($key)] = $value;
            }
        }

        return $parsed;
    }

    /**
     * Find the next occurrence of a specified weekday
     *
     * @param DateTime $from Starting date
     * @param string $daySpec Day specification (e.g., "MO", "TU,TH")
     * @param int $weekInterval Week interval for recurrence
     * @return DateTime Next occurrence
     */
    private function nextWeekday(DateTime $from, string $daySpec, int $weekInterval = 1): DateTime
    {
        $dayMap = [
            'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3,
            'TH' => 4, 'FR' => 5, 'SA' => 6
        ];

        $days = array_map('trim', explode(',', $daySpec));
        $targetDays = [];
        foreach ($days as $day) {
            if (isset($dayMap[strtoupper($day)])) {
                $targetDays[] = $dayMap[strtoupper($day)];
            }
        }

        if (empty($targetDays)) {
            $from->modify('+1 week');
            return $from;
        }

        sort($targetDays);
        $currentDow = (int)$from->format('w');

        // Find next target day in current week
        foreach ($targetDays as $targetDay) {
            if ($targetDay > $currentDow) {
                $diff = $targetDay - $currentDow;
                $from->modify("+{$diff} days");
                return $from;
            }
        }

        // Move to first target day of next interval week
        $daysUntilNextWeek = 7 - $currentDow + $targetDays[0];
        $daysUntilNextWeek += ($weekInterval - 1) * 7;
        $from->modify("+{$daysUntilNextWeek} days");

        return $from;
    }

    /**
     * Create training night events for a date range
     *
     * @param int $brigadeId Brigade ID
     * @param array $trainingDates Array of training date data from HolidayService
     * @param int $createdBy Member ID who created the events
     * @return int Number of events created
     */
    public function createTrainingNights(int $brigadeId, array $trainingDates, int $createdBy): int
    {
        $count = 0;

        foreach ($trainingDates as $training) {
            // Check if event already exists for this date
            $stmt = $this->db->prepare('
                SELECT id FROM events
                WHERE brigade_id = ?
                  AND is_training = 1
                  AND DATE(start_time) = ?
            ');
            $stmt->execute([$brigadeId, $training['date']]);

            if ($stmt->fetch()) {
                continue; // Skip if already exists
            }

            $title = 'Training Night';
            if ($training['is_moved'] ?? false) {
                $title .= ' (Moved from ' . date('l', strtotime($training['original_date'])) . ')';
            }

            $startTime = $training['date'] . ' ' . $training['time'];
            $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' +2 hours'));

            $this->create([
                'brigade_id' => $brigadeId,
                'title' => $title,
                'description' => $training['is_moved']
                    ? 'Training moved due to public holiday on ' . $training['original_date']
                    : null,
                'location' => 'Puke Fire Station',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'all_day' => 0,
                'is_training' => 1,
                'is_visible' => 1,
                'created_by' => $createdBy,
            ]);

            $count++;
        }

        return $count;
    }
}
