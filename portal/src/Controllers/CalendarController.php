<?php
declare(strict_types=1);

/**
 * Calendar Controller
 *
 * Handles web routes for the calendar system.
 */
class CalendarController
{
    private Event $eventModel;
    private HolidayService $holidayService;
    private IcsService $icsService;
    private Settings $settings;

    public function __construct()
    {
        require_once __DIR__ . '/../Models/Event.php';
        require_once __DIR__ . '/../Models/Settings.php';
        require_once __DIR__ . '/../Services/HolidayService.php';
        require_once __DIR__ . '/../Services/IcsService.php';

        $this->eventModel = new Event();
        $this->holidayService = new HolidayService();
        $this->icsService = new IcsService();
        $this->settings = new Settings();
    }

    /**
     * Display calendar view
     * GET /calendar
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get view mode from query string (default: month)
        $view = $_GET['view'] ?? 'month';
        if (!in_array($view, ['day', 'week', 'month'], true)) {
            $view = 'month';
        }

        // Get date from query string (default: today)
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // Calculate date range based on view
        $dateRange = $this->calculateDateRange($date, $view);

        // Get events for the range
        $events = $this->eventModel->findByDateRange(
            $brigadeId,
            $dateRange['from'],
            $dateRange['to']
        );

        // Get upcoming training nights for sidebar
        $upcomingTrainings = $this->eventModel->findTrainingNights(
            $brigadeId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+3 months'))
        );

        // Get public holidays if enabled
        $holidays = [];
        $showHolidays = $this->settings->get($brigadeId, 'calendar.show_holidays', '1');
        if ($showHolidays === '1' || $showHolidays === true) {
            $holidayRegion = $this->settings->get($brigadeId, 'calendar.holiday_region', 'auckland');
            $holidays = $this->holidayService->getHolidaysForDateRange(
                $dateRange['from'],
                $dateRange['to'],
                $holidayRegion
            );
        }

        render('pages/calendar/index', [
            'pageTitle' => 'Calendar',
            'view' => $view,
            'currentDate' => $date,
            'dateRange' => $dateRange,
            'events' => $events,
            'holidays' => $holidays,
            'upcomingTrainings' => array_slice($upcomingTrainings, 0, 5),
            'isAdmin' => hasRole('admin'),
            'extraScripts' => '<script src="' . url('/assets/js/calendar.js') . '"></script>',
        ]);
    }

    /**
     * Get events as JSON (API endpoint)
     * GET /calendar/events
     */
    public function events(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get date range from query string
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-t');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            jsonResponse(['error' => 'Invalid date format'], 400);
            return;
        }

        // Get events
        $events = $this->eventModel->findByDateRange($brigadeId, $from, $to);

        // Format for JSON response
        $formattedEvents = array_map(function ($event) {
            return [
                'id' => (int)$event['id'],
                'title' => $event['title'],
                'description' => $event['description'],
                'location' => $event['location'],
                'start' => $event['start_time'],
                'end' => $event['end_time'],
                'allDay' => (bool)$event['all_day'],
                'isTraining' => (bool)$event['is_training'],
                'isMoved' => $event['is_moved'] ?? false,
                'originalDate' => $event['original_date'] ?? null,
                'instanceDate' => $event['instance_date'] ?? date('Y-m-d', strtotime($event['start_time'])),
            ];
        }, $events);

        jsonResponse([
            'success' => true,
            'events' => $formattedEvents,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Display a single event
     * GET /calendar/events/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
                return;
            }
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $eventId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $event = $this->eventModel->findById($eventId);

        if (!$event || $event['brigade_id'] !== $brigadeId) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Event not found'], 404);
                return;
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Event not found']);
            return;
        }

        // Check visibility (unless admin)
        if (!hasRole('admin') && !$event['is_visible']) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Event not found'], 404);
                return;
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Event not found']);
            return;
        }

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'event' => [
                    'id' => (int)$event['id'],
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'location' => $event['location'],
                    'start' => $event['start_time'],
                    'end' => $event['end_time'],
                    'allDay' => (bool)$event['all_day'],
                    'isTraining' => (bool)$event['is_training'],
                    'recurrenceRule' => $event['recurrence_rule'],
                    'creatorName' => $event['creator_name'],
                ],
            ]);
            return;
        }

        render('pages/calendar/event', [
            'pageTitle' => $event['title'],
            'event' => $event,
            'isAdmin' => hasRole('admin'),
        ]);
    }

    /**
     * Store a new event
     * POST /calendar/events
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
                return;
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // For non-API requests, verify CSRF token
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        // Get input data
        $input = $this->isApiRequest() ? $this->getJsonInput() : $_POST;

        $data = [
            'brigade_id' => (int)$user['brigade_id'],
            'title' => trim($input['title'] ?? ''),
            'description' => trim($input['description'] ?? ''),
            'location' => trim($input['location'] ?? ''),
            'start_time' => $input['start_time'] ?? '',
            'end_time' => $input['end_time'] ?? null,
            'all_day' => !empty($input['all_day']) ? 1 : 0,
            'recurrence_rule' => $input['recurrence_rule'] ?? null,
            'is_training' => !empty($input['is_training']) ? 1 : 0,
            'is_visible' => !empty($input['is_visible']) || !isset($input['is_visible']) ? 1 : 0,
            'created_by' => (int)$user['id'],
        ];

        // Validate
        $errors = $this->validateEvent($data);

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 400);
                return;
            }
            render('pages/calendar/create', [
                'pageTitle' => 'Create Event',
                'event' => $data,
                'errors' => $errors,
            ]);
            return;
        }

        // Create the event
        $eventId = $this->eventModel->create($data);

        // Log the action
        $this->logAction('event.create', 'event', $eventId, $data);

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'id' => $eventId,
                'message' => 'Event created successfully',
            ], 201);
            return;
        }

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Event created successfully'];

        // Redirect to event detail
        header('Location: ' . url('/calendar/' . $eventId));
        exit;
    }

    /**
     * Update an event
     * PUT /calendar/events/{id}
     */
    public function update(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
                return;
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $eventId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $event = $this->eventModel->findById($eventId);

        if (!$event || $event['brigade_id'] !== $brigadeId) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Event not found'], 404);
                return;
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Event not found']);
            return;
        }

        // For non-API requests, verify CSRF token
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        // Get input data
        $input = $this->isApiRequest() ? $this->getJsonInput() : $_POST;

        $data = [];
        $allowedFields = ['title', 'description', 'location', 'start_time', 'end_time',
            'all_day', 'recurrence_rule', 'is_training', 'is_visible'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                if (in_array($field, ['all_day', 'is_training', 'is_visible'], true)) {
                    $data[$field] = !empty($input[$field]) ? 1 : 0;
                } else {
                    $data[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                }
            }
        }

        // Validate
        $errors = $this->validateEvent(array_merge($event, $data), true);

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 400);
                return;
            }
            render('pages/calendar/edit', [
                'pageTitle' => 'Edit Event',
                'event' => array_merge($event, $data),
                'errors' => $errors,
            ]);
            return;
        }

        // Update the event
        $this->eventModel->update($eventId, $data);

        // Log the action
        $this->logAction('event.update', 'event', $eventId, $data);

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'message' => 'Event updated successfully',
            ]);
            return;
        }

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Event updated successfully'];

        // Redirect to event detail
        header('Location: ' . url('/calendar/' . $eventId));
        exit;
    }

    /**
     * Delete an event
     * DELETE /calendar/events/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
                return;
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $eventId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $event = $this->eventModel->findById($eventId);

        if (!$event || $event['brigade_id'] !== $brigadeId) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Event not found'], 404);
                return;
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Event not found']);
            return;
        }

        // For non-API requests, verify CSRF token
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        // Delete the event
        $this->eventModel->delete($eventId);

        // Log the action
        $this->logAction('event.delete', 'event', $eventId, ['title' => $event['title']]);

        if ($this->isApiRequest()) {
            jsonResponse(['success' => true, 'message' => 'Event deleted']);
            return;
        }

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Event deleted successfully'];

        // Redirect to calendar
        header('Location: ' . url('/calendar'));
        exit;
    }

    /**
     * Download ICS file for an event
     * GET /calendar/events/{id}/ics
     */
    public function downloadIcs(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $eventId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $event = $this->eventModel->findById($eventId);

        if (!$event || $event['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            echo 'Event not found';
            return;
        }

        // Check visibility (unless admin)
        if (!hasRole('admin') && !$event['is_visible']) {
            http_response_code(404);
            echo 'Event not found';
            return;
        }

        // Generate ICS content
        $icsContent = $this->icsService->generateEvent($event);

        // Set headers and output
        $filename = $this->icsService->generateFilename($event);
        $this->icsService->setDownloadHeaders($filename);

        echo $icsContent;
        exit;
    }

    /**
     * List training nights
     * GET /calendar/trainings
     */
    public function trainings(): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
                return;
            }
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get date range from query string
        $from = $_GET['from'] ?? date('Y-m-d');
        $months = (int)($_GET['months'] ?? 12);
        $months = max(1, min(24, $months)); // Limit to 1-24 months

        // Generate training dates
        $trainingDates = $this->holidayService->generateTrainingDates($from, $months);

        // Get existing training events
        $to = date('Y-m-d', strtotime("+{$months} months", strtotime($from)));
        $existingTrainings = $this->eventModel->findTrainingNights($brigadeId, $from, $to);

        // Build lookup of existing trainings by date
        $existingByDate = [];
        foreach ($existingTrainings as $training) {
            $date = date('Y-m-d', strtotime($training['start_time']));
            $existingByDate[$date] = $training;
        }

        // Merge generated dates with existing events
        $mergedTrainings = [];
        foreach ($trainingDates as $training) {
            $date = $training['date'];
            if (isset($existingByDate[$date])) {
                $mergedTrainings[] = array_merge($training, [
                    'event_id' => $existingByDate[$date]['id'],
                    'event_title' => $existingByDate[$date]['title'],
                    'exists' => true,
                ]);
            } else {
                $training['exists'] = false;
                $mergedTrainings[] = $training;
            }
        }

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'trainings' => $mergedTrainings,
                'from' => $from,
                'months' => $months,
            ]);
            return;
        }

        render('pages/calendar/trainings', [
            'pageTitle' => 'Training Nights',
            'trainings' => $mergedTrainings,
            'from' => $from,
            'months' => $months,
            'isAdmin' => hasRole('admin'),
        ]);
    }

    /**
     * Generate training night events (admin only)
     * POST /calendar/trainings/generate
     */
    public function generateTrainings(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
                return;
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Verify CSRF token for non-API requests
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get input
        $input = $this->isApiRequest() ? $this->getJsonInput() : $_POST;
        $from = $input['from'] ?? date('Y-m-d');
        $months = (int)($input['months'] ?? 12);
        $months = max(1, min(24, $months));

        // Generate training dates
        $trainingDates = $this->holidayService->generateTrainingDates($from, $months);

        // Create training events
        $count = $this->eventModel->createTrainingNights($brigadeId, $trainingDates, (int)$user['id']);

        // Log the action
        $this->logAction('training.generate', 'event', 0, [
            'from' => $from,
            'months' => $months,
            'count' => $count,
        ]);

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'message' => "Generated {$count} training night events",
                'count' => $count,
            ]);
            return;
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => "Generated {$count} training night events",
        ];

        header('Location: ' . url('/calendar/trainings'));
        exit;
    }

    /**
     * Display create event form
     * GET /calendar/events/create
     */
    public function create(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        render('pages/calendar/create', [
            'pageTitle' => 'Create Event',
            'event' => [
                'title' => '',
                'description' => '',
                'location' => 'Puke Fire Station',
                'start_time' => '',
                'end_time' => '',
                'all_day' => 0,
                'is_training' => 0,
                'is_visible' => 1,
                'recurrence_rule' => '',
            ],
            'errors' => [],
        ]);
    }

    /**
     * Display edit event form
     * GET /calendar/events/{id}/edit
     */
    public function edit(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $eventId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $event = $this->eventModel->findById($eventId);

        if (!$event || $event['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Event not found']);
            return;
        }

        render('pages/calendar/edit', [
            'pageTitle' => 'Edit Event',
            'event' => $event,
            'errors' => [],
        ]);
    }

    /**
     * Calculate date range based on view mode
     *
     * @param string $date Center date
     * @param string $view View mode (day, week, month)
     * @return array Date range with 'from' and 'to' keys
     */
    private function calculateDateRange(string $date, string $view): array
    {
        $dt = new DateTime($date, new DateTimeZone('Pacific/Auckland'));

        switch ($view) {
            case 'day':
                return [
                    'from' => $dt->format('Y-m-d'),
                    'to' => $dt->format('Y-m-d'),
                ];

            case 'week':
                // Start week on Monday
                $dayOfWeek = (int)$dt->format('N');
                $daysFromMonday = $dayOfWeek - 1;
                $weekStart = clone $dt;
                $weekStart->modify("-{$daysFromMonday} days");
                $weekEnd = clone $weekStart;
                $weekEnd->modify('+6 days');

                return [
                    'from' => $weekStart->format('Y-m-d'),
                    'to' => $weekEnd->format('Y-m-d'),
                ];

            case 'month':
            default:
                $monthStart = new DateTime($dt->format('Y-m-01'), new DateTimeZone('Pacific/Auckland'));
                $monthEnd = new DateTime($dt->format('Y-m-t'), new DateTimeZone('Pacific/Auckland'));

                // Extend to include full weeks
                $startDow = (int)$monthStart->format('N');
                if ($startDow > 1) {
                    $monthStart->modify('-' . ($startDow - 1) . ' days');
                }

                $endDow = (int)$monthEnd->format('N');
                if ($endDow < 7) {
                    $monthEnd->modify('+' . (7 - $endDow) . ' days');
                }

                return [
                    'from' => $monthStart->format('Y-m-d'),
                    'to' => $monthEnd->format('Y-m-d'),
                ];
        }
    }

    /**
     * Validate event data
     *
     * @param array $data Event data
     * @param bool $isUpdate Whether this is an update (allows partial data)
     * @return array Validation errors
     */
    private function validateEvent(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Title validation
        if (!$isUpdate || isset($data['title'])) {
            if (empty($data['title'])) {
                $errors['title'] = 'Title is required';
            } elseif (strlen($data['title']) > 200) {
                $errors['title'] = 'Title must be 200 characters or less';
            }
        }

        // Start time validation
        if (!$isUpdate || isset($data['start_time'])) {
            if (empty($data['start_time'])) {
                $errors['start_time'] = 'Start time is required';
            } else {
                $startTime = strtotime($data['start_time']);
                if ($startTime === false) {
                    $errors['start_time'] = 'Invalid start time format';
                }
            }
        }

        // End time validation (if provided)
        if (!empty($data['end_time'])) {
            $endTime = strtotime($data['end_time']);
            if ($endTime === false) {
                $errors['end_time'] = 'Invalid end time format';
            } elseif (!empty($data['start_time']) && $endTime <= strtotime($data['start_time'])) {
                $errors['end_time'] = 'End time must be after start time';
            }
        }

        // Location validation
        if (!empty($data['location']) && strlen($data['location']) > 200) {
            $errors['location'] = 'Location must be 200 characters or less';
        }

        return $errors;
    }

    /**
     * Check if this is an API request
     */
    private function isApiRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Log an action to the audit log
     */
    private function logAction(string $action, string $entityType, int $entityId, array $details): void
    {
        global $db;

        $user = currentUser();

        try {
            $stmt = $db->prepare("
                INSERT INTO audit_log (brigade_id, member_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
            ");

            $stmt->execute([
                $user['brigade_id'] ?? null,
                $user['id'] ?? null,
                $action,
                $entityType,
                $entityId,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (PDOException $e) {
            // Log error but don't fail the request
            error_log('Failed to log action: ' . $e->getMessage());
        }
    }
}
