<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use Portal\Models\Event;
use Portal\Services\HolidayService;
use Portal\Services\IcsService;

/**
 * Calendar API Controller
 *
 * Handles JSON API endpoints for calendar events.
 * All responses are JSON formatted.
 */
class CalendarApiController
{
    private Event $eventModel;
    private HolidayService $holidayService;
    private IcsService $icsService;

    public function __construct()
    {
        $this->eventModel = new Event();
        $this->holidayService = new HolidayService();
        $this->icsService = new IcsService();
    }

    /**
     * List events
     * GET /api/events
     *
     * Query parameters:
     * - from: Start date (default: first day of current month)
     * - to: End date (default: last day of current month)
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get date range from query params
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-t');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-t');
        }

        $events = $this->eventModel->findByDateRange($brigadeId, $from, $to);

        jsonResponse([
            'data' => $events,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'total' => count($events),
            ],
        ]);
    }

    /**
     * Get single event
     * GET /api/events/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $eventId = (int)$id;
        $event = $this->eventModel->findById($eventId);

        if (!$event) {
            jsonResponse(['error' => 'Event not found'], 404);
            return;
        }

        // Verify user has access to this event's brigade
        if ((int)$event['brigade_id'] !== (int)$user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        jsonResponse(['data' => $event]);
    }

    /**
     * Create new event
     * POST /api/events
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Only admins can create events
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Validate required fields
        $errors = [];
        if (empty($input['title'])) {
            $errors[] = 'Title is required';
        }
        if (empty($input['start_time'])) {
            $errors[] = 'Start time is required';
        }

        if (!empty($errors)) {
            jsonResponse(['errors' => $errors], 400);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $eventId = $this->eventModel->create([
            'brigade_id' => $brigadeId,
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'location' => $input['location'] ?? null,
            'start_time' => $input['start_time'],
            'end_time' => $input['end_time'] ?? null,
            'all_day' => $input['all_day'] ?? false,
            'recurrence_rule' => $input['recurrence_rule'] ?? null,
            'is_training' => $input['is_training'] ?? false,
            'created_by' => $memberId,
        ]);

        $event = $this->eventModel->findById($eventId);

        jsonResponse(['data' => $event], 201);
    }

    /**
     * Update event
     * PUT /api/events/{id}
     */
    public function update(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Only admins can update events
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $eventId = (int)$id;
        $event = $this->eventModel->findById($eventId);

        if (!$event) {
            jsonResponse(['error' => 'Event not found'], 404);
            return;
        }

        // Verify user has access to this event's brigade
        if ((int)$event['brigade_id'] !== (int)$user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $data = [];
        if (isset($input['title'])) {
            $data['title'] = $input['title'];
        }
        if (isset($input['description'])) {
            $data['description'] = $input['description'];
        }
        if (isset($input['location'])) {
            $data['location'] = $input['location'];
        }
        if (isset($input['start_time'])) {
            $data['start_time'] = $input['start_time'];
        }
        if (isset($input['end_time'])) {
            $data['end_time'] = $input['end_time'];
        }
        if (isset($input['all_day'])) {
            $data['all_day'] = $input['all_day'];
        }
        if (isset($input['is_training'])) {
            $data['is_training'] = $input['is_training'];
        }

        if (!empty($data)) {
            $this->eventModel->update($eventId, $data);
        }

        $event = $this->eventModel->findById($eventId);

        jsonResponse(['data' => $event]);
    }

    /**
     * Delete event
     * DELETE /api/events/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Only admins can delete events
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $eventId = (int)$id;
        $event = $this->eventModel->findById($eventId);

        if (!$event) {
            jsonResponse(['error' => 'Event not found'], 404);
            return;
        }

        // Verify user has access to this event's brigade
        if ((int)$event['brigade_id'] !== (int)$user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $this->eventModel->delete($eventId);

        jsonResponse(['message' => 'Event deleted']);
    }

    /**
     * Get ICS file for event
     * GET /api/events/{id}/ics
     */
    public function ics(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $eventId = (int)$id;
        $event = $this->eventModel->findById($eventId);

        if (!$event) {
            jsonResponse(['error' => 'Event not found'], 404);
            return;
        }

        // Verify user has access to this event's brigade
        if ((int)$event['brigade_id'] !== (int)$user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $ics = $this->icsService->generateEvent($event);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . $eventId . '.ics"');
        echo $ics;
        exit;
    }

    /**
     * List training nights
     * GET /api/trainings
     */
    public function trainings(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get date range from query params
        $from = $_GET['from'] ?? date('Y-m-d');
        $to = $_GET['to'] ?? date('Y-m-d', strtotime('+3 months'));

        $trainings = $this->eventModel->findTrainingNights($brigadeId, $from, $to);

        jsonResponse([
            'data' => $trainings,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'total' => count($trainings),
            ],
        ]);
    }

    /**
     * Generate training nights for the next 12 months
     * POST /api/trainings/generate
     */
    public function generateTrainings(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Only admins can generate training nights
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        // Get months from input or default to 12
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $months = (int)($input['months'] ?? 12);
        if ($months < 1 || $months > 24) {
            $months = 12;
        }

        // Generate training dates using holiday service
        $trainingDates = $this->holidayService->generateTrainingDates(date('Y-m-d'), $months);

        $created = 0;
        $skipped = 0;

        foreach ($trainingDates as $training) {
            // Check if training already exists for this date
            $startTime = $training['date'] . ' ' . $training['time'];
            $existing = $this->eventModel->findByDateRange($brigadeId, $training['date'], $training['date']);

            $alreadyExists = false;
            foreach ($existing as $event) {
                if ((bool)$event['is_training']) {
                    $alreadyExists = true;
                    break;
                }
            }

            if ($alreadyExists) {
                $skipped++;
                continue;
            }

            // Create training event
            $title = 'Training Night';
            if ($training['is_moved']) {
                $title .= ' (moved from Monday public holiday)';
            }

            $this->eventModel->create([
                'brigade_id' => $brigadeId,
                'title' => $title,
                'description' => 'Weekly training session',
                'start_time' => $startTime,
                'end_time' => $training['date'] . ' 21:00:00',
                'is_training' => true,
                'created_by' => $memberId,
            ]);

            $created++;
        }

        jsonResponse([
            'message' => "Generated training nights",
            'created' => $created,
            'skipped' => $skipped,
            'total_dates' => count($trainingDates),
        ]);
    }
}
