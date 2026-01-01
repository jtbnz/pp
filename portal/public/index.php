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

// Create router instance
$router = new Router();

// Define routes
// -------------

// Home page
$router->get('/', function() {
    global $db, $config;
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
    $router->get('/{id}', 'LeaveController@show');
    $router->put('/{id}/approve', 'LeaveController@approve');    // Officers only
    $router->post('/{id}/approve', 'LeaveController@approve');   // For form submission
    $router->put('/{id}/deny', 'LeaveController@deny');          // Officers only
    $router->post('/{id}/deny', 'LeaveController@deny');         // For form submission
    $router->delete('/{id}', 'LeaveController@destroy');
}, ['middleware' => ['auth', 'csrf']]);

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
    $router->post('/push/subscribe', 'Api/PushApiController@subscribe');
    $router->delete('/push/unsubscribe', 'Api/PushApiController@unsubscribe');

    // Sync with DLB
    $router->get('/sync/status', 'SyncController@status');
    $router->post('/sync/members', 'SyncController@members');
    $router->post('/sync/musters', 'SyncController@musters');
}, ['middleware' => ['auth']]);

// Admin routes (Protected - Phase 10)
$router->group('/admin', function(Router $router) {
    $router->get('/', 'AdminController@dashboard');
    $router->get('/members', 'AdminController@members');
    $router->get('/members/invite', 'AdminController@inviteForm');
    $router->post('/members/invite', 'AdminController@invite');
    $router->get('/members/{id}', 'AdminController@editMember');
    $router->put('/members/{id}', 'AdminController@updateMember');
    $router->get('/events', 'AdminController@events');
    $router->get('/events/create', 'AdminController@createEventForm');
    $router->post('/events', 'AdminController@createEvent');
    $router->get('/notices', 'AdminController@notices');
    $router->get('/notices/create', 'AdminController@createNoticeForm');
    $router->post('/notices', 'AdminController@createNotice');
    $router->get('/leave', 'AdminController@leaveRequests');
    $router->get('/settings', 'AdminController@settings');
    $router->put('/settings', 'AdminController@updateSettings');
}, ['middleware' => ['auth', 'admin']]);

// Dispatch the request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string from URI
if (($pos = strpos($uri, '?')) !== false) {
    $uri = substr($uri, 0, $pos);
}

// Dispatch to router
$router->dispatch($method, $uri);
