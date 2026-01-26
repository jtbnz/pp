<?php
declare(strict_types=1);

/**
 * Puke Portal - Front Controller
 *
 * All requests are routed through this file.
 * The router dispatches to appropriate controllers based on URI.
 */

// Set timezone to Pacific/Auckland for all date/time operations
date_default_timezone_set('Pacific/Auckland');

// Load bootstrap (autoloader, config, database)
require_once __DIR__ . '/../src/bootstrap.php';

use Portal\Router;
use Portal\Models\Event;
use Portal\Models\Notice;
use Portal\Models\LeaveRequest;
use Portal\Models\Poll;

// Create router instance
$router = new Router();

// Define routes
// -------------

// Profile route - redirect to current user's member profile
$router->get('/profile', function() {
    $user = currentUser();
    if (!$user) {
        header('Location: ' . url('/auth/login'));
        exit;
    }
    header('Location: ' . url('/members/' . $user['id']));
    exit;
});

// Home page
$router->get('/', function() {
    global $db, $config;

    $user = currentUser();
    $nextTraining = null;
    $upcomingEvents = [];
    $recentNotices = [];
    $pendingLeave = [];

    if ($user) {
        $brigadeId = (int)$user['brigade_id'];

        // Get next training night
        $eventModel = new Event();
        $trainings = $eventModel->findTrainingNights($brigadeId, date('Y-m-d'), date('Y-m-d', strtotime('+3 months')));
        $nextTraining = !empty($trainings) ? $trainings[0] : null;

        // Get upcoming events (next 5)
        $upcomingEvents = $eventModel->findByDateRange($brigadeId, date('Y-m-d'), date('Y-m-d', strtotime('+1 month')));
        $upcomingEvents = array_slice($upcomingEvents, 0, 5);

        // Get recent notices
        $noticeModel = new Notice();
        $recentNotices = $noticeModel->findActive($brigadeId, 3);

        // Get pending leave requests for this member
        $leaveModel = new LeaveRequest();
        $pendingLeave = $leaveModel->findByMember((int)$user['id'], 'pending');

        // Get active polls and unvoted count
        $pollModel = new Poll();
        $activePolls = $pollModel->findActive($brigadeId);
        $unvotedPollCount = $pollModel->getUnvotedCount($brigadeId, (int)$user['id']);
    }

    // Pass data to template
    $homeData = [
        'nextTraining' => $nextTraining,
        'upcomingEvents' => $upcomingEvents,
        'recentNotices' => $recentNotices,
        'pendingLeave' => $pendingLeave,
        'activePolls' => $activePolls ?? [],
        'unvotedPollCount' => $unvotedPollCount ?? 0,
    ];

    extract($homeData);
    require __DIR__ . '/../templates/pages/home.php';
});

// Health check endpoint
$router->get('/health', function() {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'timestamp' => date('c'),
        'timezone' => date_default_timezone_get()
    ]);
});

// Dynamic manifest.json with correct base_path
$router->get('/manifest.json', function() {
    global $config;
    $basePath = $config['base_path'] ?? '';
    $appName = $config['app_name'] ?? 'Puke Fire Portal';

    header('Content-Type: application/manifest+json');
    echo json_encode([
        'name' => $appName,
        'short_name' => $appName,
        'description' => $appName . ' - Member portal for calendar, notices, and leave management',
        'start_url' => $basePath . '/',
        'display' => 'standalone',
        'theme_color' => $config['theme']['primary_color'] ?? '#D32F2F',
        'background_color' => '#ffffff',
        'orientation' => 'portrait-primary',
        'scope' => $basePath . '/',
        'lang' => 'en-NZ',
        'categories' => ['utilities', 'productivity'],
        'icons' => [
            ['src' => $basePath . '/assets/icons/icon-72.svg', 'sizes' => '72x72', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-96.svg', 'sizes' => '96x96', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-128.svg', 'sizes' => '128x128', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-144.svg', 'sizes' => '144x144', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-152.svg', 'sizes' => '152x152', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-192.svg', 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
            ['src' => $basePath . '/assets/icons/icon-384.svg', 'sizes' => '384x384', 'type' => 'image/svg+xml'],
            ['src' => $basePath . '/assets/icons/icon-512.svg', 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
        ],
        'shortcuts' => [
            ['name' => 'Calendar', 'short_name' => 'Calendar', 'description' => 'View the training calendar', 'url' => $basePath . '/calendar', 'icons' => [['src' => $basePath . '/assets/icons/icon-96.svg', 'sizes' => '96x96', 'type' => 'image/svg+xml']]],
            ['name' => 'Notices', 'short_name' => 'Notices', 'description' => 'View brigade notices', 'url' => $basePath . '/notices', 'icons' => [['src' => $basePath . '/assets/icons/icon-96.svg', 'sizes' => '96x96', 'type' => 'image/svg+xml']]],
            ['name' => 'Leave Requests', 'short_name' => 'Leave', 'description' => 'Manage leave requests', 'url' => $basePath . '/leave', 'icons' => [['src' => $basePath . '/assets/icons/icon-96.svg', 'sizes' => '96x96', 'type' => 'image/svg+xml']]],
        ]
    ], JSON_UNESCAPED_SLASHES);
});

// Auth routes (Phase 2)
$router->group('/auth', function(Router $router) {
    $router->get('/login', 'AuthController@loginForm');
    $router->post('/login', 'AuthController@login');
    $router->get('/verify/{token}', 'AuthController@verify');
    $router->get('/activate', 'AuthController@showActivate');
    $router->post('/activate', 'AuthController@activate');
    $router->get('/pin', 'AuthController@showPinLogin');
    $router->post('/pin', 'AuthController@pinLogin');
    $router->get('/magic-link', 'AuthController@requestMagicLink');
    $router->post('/logout', 'AuthController@logout');
    // Test-only route for automated testing (only works when APP_ENV=testing)
    $router->get('/test-login', 'AuthController@testLogin');
});

// Member routes (Phase 3) - Web interface
$router->group('/members', function(Router $router) {
    $router->get('/', 'MemberController@index');                           // Admin only (list members)
    $router->get('/{id}', 'MemberController@show');                        // View member profile
    $router->get('/{id}/edit', 'MemberController@edit');                   // Admin only (edit form)
    $router->put('/{id}', 'MemberController@update');                      // Admin only (update)
    $router->post('/{id}', 'MemberController@update');                     // For form submission with _method=PUT
    $router->delete('/{id}', 'MemberController@destroy');                  // Admin only (deactivate)
    $router->get('/{id}/service-periods', 'MemberController@servicePeriods');       // Admin only
    $router->post('/{id}/service-periods', 'MemberController@addServicePeriod');    // Admin only
    $router->put('/{id}/service-periods/{pid}', 'MemberController@updateServicePeriod');   // Admin only
    $router->post('/{id}/service-periods/{pid}', 'MemberController@updateServicePeriod'); // For form submission
    $router->delete('/{id}/service-periods/{pid}', 'MemberController@deleteServicePeriod'); // Admin only
}, ['middleware' => ['auth', 'csrf']]);

// Calendar routes (Phase 4) - Web interface
$router->group('/calendar', function(Router $router) {
    $router->get('/', 'CalendarController@index');
    $router->get('/create', 'CalendarController@create');        // Admin only
    $router->post('/', 'CalendarController@store');              // Admin only
    $router->get('/trainings', 'CalendarController@trainings');  // View all trainings
    $router->post('/trainings/generate', 'CalendarController@generateTrainings');  // Admin only
    $router->get('/{id}', 'CalendarController@show');
    $router->get('/{id}/edit', 'CalendarController@edit');       // Admin only
    $router->put('/{id}', 'CalendarController@update');          // Admin only
    $router->post('/{id}', 'CalendarController@update');         // For form submission
    $router->delete('/{id}', 'CalendarController@destroy');      // Admin only
    $router->get('/{id}/ics', 'CalendarController@downloadIcs');  // ICS export
}, ['middleware' => ['auth', 'csrf']]);

// Notice routes (Phase 5) - Web interface
$router->group('/notices', function(Router $router) {
    $router->get('/', 'NoticeController@index');
    $router->get('/create', 'NoticeController@create');        // Admin only (checked in controller)
    $router->post('/', 'NoticeController@store');              // Admin only
    $router->get('/{id}', 'NoticeController@show');
    $router->get('/{id}/edit', 'NoticeController@edit');       // Admin only
    $router->put('/{id}', 'NoticeController@update');          // Admin only
    $router->post('/{id}', 'NoticeController@update');         // For form submission with _method=PUT
    $router->delete('/{id}', 'NoticeController@destroy');      // Admin only
}, ['middleware' => ['auth', 'csrf']]);

// Leave routes (Phase 6) - Web interface
$router->group('/leave', function(Router $router) {
    $router->get('/', 'LeaveController@index');
    $router->post('/', 'LeaveController@store');
    $router->get('/pending', 'LeaveController@pending');         // Officers only

    // Extended leave routes (long-term leave with date ranges, requires CFO approval)
    $router->get('/extended', 'LeaveController@extendedForm');
    $router->post('/extended', 'LeaveController@storeExtended');
    $router->get('/extended/calculate', 'LeaveController@calculateTrainings');  // AJAX
    $router->get('/extended/{id}', 'LeaveController@showExtended');
    $router->post('/extended/{id}/approve', 'LeaveController@approveExtended');  // CFO only
    $router->post('/extended/{id}/deny', 'LeaveController@denyExtended');        // CFO only
    $router->post('/extended/{id}/cancel', 'LeaveController@cancelExtended');

    // Regular leave routes
    $router->get('/{id}', 'LeaveController@show');
    $router->put('/{id}/approve', 'LeaveController@approve');    // Officers only
    $router->post('/{id}/approve', 'LeaveController@approve');   // For form submission
    $router->put('/{id}/deny', 'LeaveController@deny');          // Officers only
    $router->post('/{id}/deny', 'LeaveController@deny');         // For form submission
    $router->delete('/{id}', 'LeaveController@destroy');
    $router->post('/{id}/cancel', 'LeaveController@destroy');    // For form submission (cancel)
}, ['middleware' => ['auth', 'csrf']]);

// Polls routes - Web interface (any authenticated user can create)
$router->group('/polls', function(Router $router) {
    $router->get('/', 'PollController@index');
    $router->get('/create', 'PollController@create');
    $router->post('/', 'PollController@store');
    $router->get('/{id}', 'PollController@show');
    $router->get('/{id}/edit', 'PollController@edit');
    $router->put('/{id}', 'PollController@update');
    $router->post('/{id}', 'PollController@update');  // For form submission with _method=PUT
    $router->delete('/{id}', 'PollController@destroy');
    $router->post('/{id}/close', 'PollController@close');
    $router->post('/{id}/vote', 'PollController@vote');
}, ['middleware' => ['auth', 'csrf']]);

// Session API routes (NO auth middleware - used to restore sessions from localStorage)
$router->group('/api/session', function(Router $router) {
    $router->post('/restore', 'Api/SessionApiController@restore');
    $router->get('/status', 'Api/SessionApiController@status');
});

// Push debug route (NO auth middleware - for debugging push issues)
$router->get('/api/push/debug', 'Api/PushApiController@debug');

// Webhook routes (NO auth middleware - validated by webhook secret)
$router->post('/api/webhook/attendance', 'Api/WebhookController@attendance');

// API routes (Protected - Phase 2+)
$router->group('/api', function(Router $router) {
    // Members (Phase 3)
    $router->get('/members', 'Api/MemberApiController@index');
    $router->post('/members', 'Api/MemberApiController@store');
    $router->get('/members/{id}', 'Api/MemberApiController@show');
    $router->put('/members/{id}', 'Api/MemberApiController@update');
    $router->delete('/members/{id}', 'Api/MemberApiController@destroy');
    $router->get('/members/{id}/service-periods', 'Api/MemberApiController@getServicePeriods');
    $router->post('/members/{id}/service-periods', 'Api/MemberApiController@addServicePeriod');
    $router->put('/members/{id}/service-periods/{pid}', 'Api/MemberApiController@updateServicePeriod');
    $router->delete('/members/{id}/service-periods/{pid}', 'Api/MemberApiController@deleteServicePeriod');

    // User Preferences (Issue #23 - color blindness mode)
    $router->put('/members/{id}/preferences', 'Api/MemberApiController@updatePreferences');

    // Member Attendance
    $router->get('/members/{id}/attendance', 'Api/MemberApiController@attendance');
    $router->get('/members/{id}/attendance/recent', 'Api/MemberApiController@attendanceRecent');
    $router->post('/attendance/sync', 'Api/MemberApiController@syncAttendance');

    // Events/Calendar (API)
    $router->get('/events', 'Api/CalendarApiController@index');
    $router->post('/events', 'Api/CalendarApiController@store');
    $router->get('/events/{id}', 'Api/CalendarApiController@show');
    $router->put('/events/{id}', 'Api/CalendarApiController@update');
    $router->delete('/events/{id}', 'Api/CalendarApiController@destroy');
    $router->get('/events/{id}/ics', 'Api/CalendarApiController@ics');

    // Training nights (API)
    $router->get('/trainings', 'Api/CalendarApiController@trainings');
    $router->post('/trainings/generate', 'Api/CalendarApiController@generateTrainings');

    // Notices (API)
    $router->get('/notices', 'Api/NoticeApiController@index');
    $router->post('/notices', 'Api/NoticeApiController@store');
    $router->get('/notices/{id}', 'Api/NoticeApiController@show');
    $router->put('/notices/{id}', 'Api/NoticeApiController@update');
    $router->delete('/notices/{id}', 'Api/NoticeApiController@destroy');

    // Leave requests (API)
    $router->get('/leave', 'Api/LeaveApiController@index');
    $router->post('/leave', 'Api/LeaveApiController@store');
    $router->get('/leave/{id}', 'Api/LeaveApiController@show');
    $router->put('/leave/{id}/approve', 'Api/LeaveApiController@approve');
    $router->put('/leave/{id}/deny', 'Api/LeaveApiController@deny');
    $router->delete('/leave/{id}', 'Api/LeaveApiController@destroy');

    // Push notifications (API)
    $router->get('/push/debug', 'Api/PushApiController@debug');
    $router->get('/push/key', 'Api/PushApiController@key');
    $router->get('/push/status', 'Api/PushApiController@status');
    $router->post('/push/subscribe', 'Api/PushApiController@subscribe');
    $router->post('/push/unsubscribe', 'Api/PushApiController@unsubscribe');
    $router->post('/push/test', 'Api/PushApiController@test');

    // Notifications (Issue #26 - Notification Center)
    $router->get('/notifications', 'Api/NotificationApiController@index');
    $router->get('/notifications/unread-count', 'Api/NotificationApiController@unreadCount');
    $router->patch('/notifications/{id}/read', 'Api/NotificationApiController@markRead');
    $router->post('/notifications/mark-all-read', 'Api/NotificationApiController@markAllRead');
    $router->delete('/notifications/{id}', 'Api/NotificationApiController@delete');
    $router->delete('/notifications/clear', 'Api/NotificationApiController@clear');
    $router->get('/notifications/preferences', 'Api/NotificationApiController@getPreferences');
    $router->put('/notifications/preferences', 'Api/NotificationApiController@updatePreferences');

    // Sync with DLB
    $router->get('/sync/status', 'SyncController@status');
    $router->post('/sync/members', 'SyncController@members');
    $router->post('/sync/musters', 'SyncController@musters');
    $router->post('/sync/import-members', 'SyncController@importMembers');
    $router->post('/sync/test-connection', 'SyncController@testConnection');
}, ['middleware' => ['auth']]);

// Admin routes (Protected - Phase 10)
$router->group('/admin', function(Router $router) {
    $router->get('/', 'AdminController@dashboard');
    $router->get('/members', 'AdminController@members');
    $router->get('/members/invite', 'AdminController@inviteForm');
    $router->post('/members/invite', 'AdminController@invite');
    $router->get('/members/{id}', 'AdminController@editMember');
    $router->put('/members/{id}', 'AdminController@updateMember');
    $router->post('/members/{id}', 'AdminController@updateMember');  // For form submission with _method=PUT
    $router->post('/members/{id}/send-login-link', 'AdminController@sendLoginLink');
    $router->get('/events', 'AdminController@events');
    $router->get('/events/create', 'AdminController@createEventForm');
    $router->post('/events', 'AdminController@createEvent');
    $router->get('/notices', 'AdminController@notices');
    $router->get('/notices/create', 'AdminController@createNoticeForm');
    $router->post('/notices', 'AdminController@createNotice');
    $router->get('/leave', 'AdminController@leaveRequests');
    $router->get('/polls', 'AdminController@polls');
    $router->get('/polls/create', 'AdminController@createPollForm');
    $router->post('/polls', 'AdminController@createPoll');
    $router->get('/polls/{id}', 'AdminController@editPoll');
    $router->put('/polls/{id}', 'AdminController@updatePoll');
    $router->post('/polls/{id}', 'AdminController@updatePoll');  // For form submission with _method=PUT
    $router->post('/polls/{id}/close', 'AdminController@closePoll');
    $router->delete('/polls/{id}', 'AdminController@deletePoll');
    $router->get('/settings', 'AdminController@settings');
    $router->put('/settings', 'AdminController@updateSettings');
    // Calendar import
    $router->get('/events/import', 'AdminController@importEventsForm');
    $router->post('/events/import', 'AdminController@importEvents');
    $router->post('/events/import/preview', 'AdminController@previewImportEvents');
    // View-as functionality (superadmin only)
    $router->post('/view-as', 'AdminController@startViewAs');
    $router->post('/view-as/stop', 'AdminController@stopViewAs');
}, ['middleware' => ['auth', 'admin']]);

// Dispatch the request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Support method override for HTML forms (PUT, DELETE, PATCH via POST with _method field)
if ($method === 'POST' && isset($_POST['_method'])) {
    $overrideMethod = strtoupper($_POST['_method']);
    if (in_array($overrideMethod, ['PUT', 'DELETE', 'PATCH'], true)) {
        $method = $overrideMethod;
    }
}

// Remove query string from URI
if (($pos = strpos($uri, '?')) !== false) {
    $uri = substr($uri, 0, $pos);
}

// Dispatch to router
$router->dispatch($method, $uri);
