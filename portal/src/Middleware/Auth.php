<?php
declare(strict_types=1);

/**
 * Authentication Middleware
 *
 * Verifies user session is valid and refreshes on activity.
 * Handles expired sessions gracefully.
 */
class Auth
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Handle the middleware check
     *
     * @return bool True if authenticated, false otherwise
     */
    public function handle(): bool
    {
        // Check if session has member_id
        if (!isset($_SESSION['member_id'])) {
            return $this->handleUnauthenticated();
        }

        // Verify member still exists and is active
        $member = $this->getMember($_SESSION['member_id']);
        if (!$member) {
            $this->clearSession();
            return $this->handleUnauthenticated();
        }

        // Check if member's access has expired
        if ($member['access_expires'] && strtotime($member['access_expires']) < time()) {
            $this->clearSession();
            return $this->handleExpiredAccess();
        }

        // Refresh session timestamp on activity
        $this->refreshSession();

        return true;
    }

    /**
     * Get current authenticated member
     *
     * @param int $memberId The member ID
     * @return array|null Member data or null
     */
    private function getMember(int $memberId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT m.*, b.name as brigade_name, b.slug as brigade_slug
            FROM members m
            JOIN brigades b ON b.id = m.brigade_id
            WHERE m.id = ?
              AND m.status = "active"
        ');
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Handle unauthenticated request
     *
     * @return bool Always returns false
     */
    private function handleUnauthenticated(): bool
    {
        if ($this->isApiRequest()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        } else {
            // Store intended destination for redirect after login
            $this->storeIntendedUrl();
            header('Location: ' . url('/auth/login'));
            exit;
        }

        return false;
    }

    /**
     * Handle expired access
     *
     * @return bool Always returns false
     */
    private function handleExpiredAccess(): bool
    {
        if ($this->isApiRequest()) {
            $this->jsonResponse(['error' => 'Access expired. Please contact your administrator.'], 401);
        } else {
            header('Location: ' . url('/auth/login?expired=1'));
            exit;
        }

        return false;
    }

    /**
     * Clear the current session
     */
    private function clearSession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        session_start();
    }

    /**
     * Refresh session to extend expiry
     */
    private function refreshSession(): void
    {
        // Regenerate session ID periodically for security
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            // Regenerate every 30 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }

        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
    }

    /**
     * Store the current URL for redirect after login
     */
    private function storeIntendedUrl(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Don't store auth-related URLs
        if (strpos($uri, '/auth/') !== 0) {
            $_SESSION['intended_url'] = $uri;
        }
    }

    /**
     * Get the intended URL after login
     *
     * @return string The URL to redirect to
     */
    public static function getIntendedUrl(): string
    {
        $url = $_SESSION['intended_url'] ?? null;
        unset($_SESSION['intended_url']);

        // If no intended URL, redirect to home with base path
        if ($url === null) {
            return url('/');
        }

        return $url;
    }

    /**
     * Check if this is an API request
     *
     * @return bool True if API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    /**
     * Send JSON response and exit
     *
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Check if session is about to expire
     *
     * @param int $withinMinutes Check if expiring within this many minutes
     * @return bool True if session expiring soon
     */
    public function isSessionExpiringSoon(int $withinMinutes = 5): bool
    {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }

        $sessionTimeout = $this->config['session']['timeout'] ?? 86400;
        $expiresAt = $_SESSION['last_activity'] + $sessionTimeout;
        $warningTime = time() + ($withinMinutes * 60);

        return $expiresAt <= $warningTime;
    }

    /**
     * Get remaining session time in seconds
     *
     * @return int Seconds remaining, or 0 if expired/no session
     */
    public function getSessionRemainingTime(): int
    {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }

        $sessionTimeout = $this->config['session']['timeout'] ?? 86400;
        $expiresAt = $_SESSION['last_activity'] + $sessionTimeout;
        $remaining = $expiresAt - time();

        return max(0, $remaining);
    }
}
