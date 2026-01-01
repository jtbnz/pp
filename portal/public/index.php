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

    // Events/Calendar
    $router->get('/events', 'EventController@index');
    $router->post('/events', 'EventController@store');
    $router->get('/events/{id}', 'EventController@show');
    $router->put('/events/{id}', 'EventController@update');
    $router->delete('/events/{id}', 'EventController@destroy');
    $router->get('/events/{id}/ics', 'EventController@ics');

    // Training nights
    $router->get('/trainings', 'TrainingController@index');
    $router->post('/trainings/generate', 'TrainingController@generate');

    // Notices (API)
    $router->get('/notices', 'Api/NoticeApiController@index');
    $router->post('/notices', 'Api/NoticeApiController@store');
    $router->get('/notices/{id}', 'Api/NoticeApiController@show');
    $router->put('/notices/{id}', 'Api/NoticeApiController@update');
    $router->delete('/notices/{id}', 'Api/NoticeApiController@destroy');

    // Leave requests
    $router->get('/leave', 'LeaveController@index');
    $router->post('/leave', 'LeaveController@store');
    $router->get('/leave/{id}', 'LeaveController@show');
    $router->put('/leave/{id}/approve', 'LeaveController@approve');
    $router->put('/leave/{id}/deny', 'LeaveController@deny');
    $router->delete('/leave/{id}', 'LeaveController@destroy');

    // Push notifications
    $router->post('/push/subscribe', 'PushController@subscribe');
    $router->delete('/push/unsubscribe', 'PushController@unsubscribe');

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
