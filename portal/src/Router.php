<?php
declare(strict_types=1);

namespace Portal;

/**
 * Simple regex-based router with support for:
 * - Route groups with prefixes
 * - Middleware
 * - Named parameters (e.g., /users/{id})
 * - Closure and Controller@method handlers
 */
class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable|string, middleware: array}> */
    private array $routes = [];

    /** @var string Current group prefix */
    private string $groupPrefix = '';

    /** @var array Current group middleware */
    private array $groupMiddleware = [];

    /** @var array<string, callable> Registered middleware */
    private array $middleware = [];

    /**
     * Register a GET route
     */
    public function get(string $path, callable|string $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable|string $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, callable|string $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable|string $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $path, callable|string $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Add a route to the collection
     */
    private function addRoute(string $method, string $path, callable|string $handler): self
    {
        $fullPath = $this->groupPrefix . $path;

        // Normalize the path: remove trailing slash (except for root)
        if ($fullPath !== '/' && str_ends_with($fullPath, '/')) {
            $fullPath = rtrim($fullPath, '/');
        }

        // Convert path parameters to regex
        // {id} becomes (?P<id>[^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware
        ];

        return $this;
    }

    /**
     * Create a route group with shared prefix and/or middleware
     *
     * @param string $prefix URL prefix for all routes in group
     * @param callable $callback Function that defines routes in the group
     * @param array $options Options like 'middleware' => ['auth', 'admin']
     */
    public function group(string $prefix, callable $callback, array $options = []): self
    {
        // Save current state
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        // Apply group settings
        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $options['middleware'] ?? []);

        // Execute callback to define routes
        $callback($this);

        // Restore previous state
        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Register middleware by name
     */
    public function registerMiddleware(string $name, callable $handler): self
    {
        $this->middleware[$name] = $handler;
        return $this;
    }

    /**
     * Dispatch the request to the appropriate handler
     */
    public function dispatch(string $method, string $uri): void
    {
        global $config;

        // Handle OPTIONS requests for CORS
        if ($method === 'OPTIONS') {
            $this->handleOptions();
            return;
        }

        // Strip base path if configured (for subdirectory installations)
        $basePath = $config['base_path'] ?? '';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }

        // Normalize URI
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // Find matching route
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middleware
                foreach ($route['middleware'] as $middlewareName) {
                    if (!$this->runMiddleware($middlewareName, $params)) {
                        return; // Middleware rejected request
                    }
                }

                // Execute handler
                $this->executeHandler($route['handler'], $params);
                return;
            }
        }

        // No route found
        $this->notFound();
    }

    /**
     * Run a middleware by name
     *
     * @return bool True if request should continue, false to stop
     */
    private function runMiddleware(string $name, array $params): bool
    {
        // Built-in middleware
        switch ($name) {
            case 'auth':
                return $this->authMiddleware();

            case 'admin':
                return $this->adminMiddleware();

            case 'officer':
                return $this->officerMiddleware();

            case 'csrf':
                return $this->csrfMiddleware();

            default:
                // Check for registered middleware
                if (isset($this->middleware[$name])) {
                    return (bool)($this->middleware[$name])($params);
                }
                return true;
        }
    }

    /**
     * Built-in auth middleware - requires authenticated user
     */
    private function authMiddleware(): bool
    {
        global $config;

        $debugEnabled = $config['auth']['debug'] ?? false;
        $user = currentUser();

        if ($user === null) {
            // Log auth failure for debugging
            if ($debugEnabled) {
                $this->logAuthDebug('auth_middleware_failed', [
                    'reason' => 'no_user_returned',
                    'session_id' => session_id() ? substr(session_id(), 0, 16) . '...' : 'none',
                    'session_status' => session_status(),
                    'session_member_id' => $_SESSION['member_id'] ?? 'not_set',
                    'session_keys' => array_keys($_SESSION ?? []),
                    'has_session_cookie' => isset($_COOKIE['puke_portal_session']),
                    'cookie_value_prefix' => isset($_COOKIE['puke_portal_session']) ? substr($_COOKIE['puke_portal_session'], 0, 16) . '...' : 'none',
                    'all_cookies' => array_keys($_COOKIE ?? []),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'sec_fetch_mode' => $_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'not_set',
                    'sec_fetch_dest' => $_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'not_set',
                ]);
            }

            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            } else {
                header('Location: ' . url('/auth/login'));
                exit;
            }
            return false;
        }

        // Check if access has expired
        if (isset($user['access_expires']) && strtotime($user['access_expires']) < time()) {
            if ($debugEnabled) {
                $this->logAuthDebug('auth_middleware_failed', [
                    'reason' => 'access_expired',
                    'member_id' => $user['id'] ?? 'unknown',
                    'access_expires' => $user['access_expires'],
                ]);
            }

            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Access expired'], 401);
            } else {
                header('Location: ' . url('/auth/login?expired=1'));
                exit;
            }
            return false;
        }

        return true;
    }

    /**
     * Log authentication debug information
     */
    private function logAuthDebug(string $event, array $data): void
    {
        $logFile = __DIR__ . '/../data/logs/auth-debug.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        $logEntry = "[{$timestamp}] {$event}: {$dataJson}\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Built-in admin middleware - requires admin or superadmin role
     */
    private function adminMiddleware(): bool
    {
        if (!hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
            } else {
                http_response_code(403);
                render('pages/errors/403');
            }
            return false;
        }
        return true;
    }

    /**
     * Built-in officer middleware - requires officer or higher role
     */
    private function officerMiddleware(): bool
    {
        if (!hasRole('officer')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
            } else {
                http_response_code(403);
                render('pages/errors/403');
            }
            return false;
        }
        return true;
    }

    /**
     * Built-in CSRF middleware - validates CSRF token on POST/PUT/DELETE
     */
    private function csrfMiddleware(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

            if (!verifyCsrfToken($token)) {
                if ($this->isApiRequest()) {
                    jsonResponse(['error' => 'Invalid CSRF token'], 403);
                } else {
                    http_response_code(403);
                    echo 'Invalid CSRF token';
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a route handler
     */
    private function executeHandler(callable|string $handler, array $params): void
    {
        if (is_callable($handler)) {
            // Direct callable (closure)
            call_user_func_array($handler, $params);
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            // Controller@method format
            [$controllerPath, $methodName] = explode('@', $handler, 2);

            // Build fully qualified class name
            // Api/MemberApiController becomes Portal\Controllers\Api\MemberApiController
            $namespacePath = str_replace('/', '\\', $controllerPath);
            $fullyQualifiedClass = 'Portal\\Controllers\\' . $namespacePath;

            if (!class_exists($fullyQualifiedClass)) {
                $this->notFound("Controller class not found: {$fullyQualifiedClass}");
                return;
            }

            $controller = new $fullyQualifiedClass();

            if (!method_exists($controller, $methodName)) {
                $this->notFound("Method not found: {$fullyQualifiedClass}@{$methodName}");
                return;
            }

            // Call method with parameters
            call_user_func_array([$controller, $methodName], $params);
        } else {
            $this->notFound('Invalid handler');
        }
    }

    /**
     * Handle OPTIONS request for CORS preflight
     */
    private function handleOptions(): void
    {
        global $config;

        $allowedOrigins = $config['cors']['allowed_origins'] ?? ['*'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

        if ($allowedOrigins === ['*'] || in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }

    /**
     * Check if this is an API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    /**
     * Handle 404 Not Found
     */
    private function notFound(string $message = 'Not Found'): void
    {
        http_response_code(404);

        if ($this->isApiRequest()) {
            jsonResponse(['error' => $message], 404);
        } else {
            // Try to render 404 page
            $errorPage = __DIR__ . '/../templates/pages/errors/404.php';
            if (file_exists($errorPage)) {
                render('pages/errors/404', ['message' => $message]);
            } else {
                echo '<h1>404 Not Found</h1><p>' . htmlspecialchars($message) . '</p>';
            }
        }
    }

    /**
     * Get all registered routes (for debugging)
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
