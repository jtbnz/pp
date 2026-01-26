<?php
declare(strict_types=1);

namespace Portal\Middleware;

use PDO;

/**
 * Role Requirement Middleware
 *
 * Verifies user has the required role level to access a resource.
 * Uses role hierarchy: firefighter < officer < admin < superadmin
 */
class RequireRole
{
    private PDO $db;

    /**
     * Role hierarchy levels
     * Higher number = more permissions
     */
    private const ROLE_HIERARCHY = [
        'firefighter' => 1,
        'officer' => 2,
        'admin' => 3,
        'superadmin' => 4
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Handle the middleware check
     *
     * @param string $requiredRole The minimum role required
     * @return bool True if authorized, false otherwise
     */
    public function handle(string $requiredRole): bool
    {
        // First check if user is authenticated
        if (!isset($_SESSION['member_id'])) {
            return $this->handleUnauthorized('Authentication required');
        }

        // Get current user's role
        $member = $this->getMember($_SESSION['member_id']);
        if (!$member) {
            return $this->handleUnauthorized('User not found');
        }

        // Check role hierarchy
        $userLevel = self::ROLE_HIERARCHY[$member['role']] ?? 0;
        $requiredLevel = self::ROLE_HIERARCHY[$requiredRole] ?? 999;

        if ($userLevel < $requiredLevel) {
            return $this->handleForbidden($requiredRole);
        }

        return true;
    }

    /**
     * Check if user has at least the specified role
     *
     * @param string $role The role to check
     * @return bool True if user has the role or higher
     */
    public function hasRole(string $role): bool
    {
        if (!isset($_SESSION['member_id'])) {
            return false;
        }

        $member = $this->getMember($_SESSION['member_id']);
        if (!$member) {
            return false;
        }

        $userLevel = self::ROLE_HIERARCHY[$member['role']] ?? 0;
        $requiredLevel = self::ROLE_HIERARCHY[$role] ?? 999;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Check if user has exactly the specified role
     *
     * @param string $role The role to check
     * @return bool True if user has exactly this role
     */
    public function isRole(string $role): bool
    {
        if (!isset($_SESSION['member_id'])) {
            return false;
        }

        $member = $this->getMember($_SESSION['member_id']);
        if (!$member) {
            return false;
        }

        return $member['role'] === $role;
    }

    /**
     * Get current authenticated member
     *
     * @param int $memberId The member ID
     * @return array|null Member data or null
     */
    private function getMember(int $memberId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ? AND status = "active"');
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Handle unauthorized (not authenticated) request
     *
     * @param string $message Error message
     * @return bool Always returns false
     */
    private function handleUnauthorized(string $message): bool
    {
        if ($this->isApiRequest()) {
            $this->jsonResponse(['error' => $message], 401);
        } else {
            header('Location: /auth/login');
            exit;
        }

        return false;
    }

    /**
     * Handle forbidden (insufficient role) request
     *
     * @param string $requiredRole The role that was required
     * @return bool Always returns false
     */
    private function handleForbidden(string $requiredRole): bool
    {
        if ($this->isApiRequest()) {
            $this->jsonResponse([
                'error' => 'Forbidden',
                'message' => "This action requires {$requiredRole} role or higher"
            ], 403);
        } else {
            http_response_code(403);
            $this->renderForbiddenPage();
        }

        return false;
    }

    /**
     * Render the 403 forbidden page
     */
    private function renderForbiddenPage(): void
    {
        $errorPage = __DIR__ . '/../../templates/pages/errors/403.php';
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
        }
        exit;
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
     * Get display name for a role
     *
     * @param string $role The role
     * @return string The display name
     */
    public static function getRoleDisplayName(string $role): string
    {
        $displayNames = [
            'firefighter' => 'Firefighter',
            'officer' => 'Officer',
            'admin' => 'Administrator',
            'superadmin' => 'Super Administrator'
        ];

        return $displayNames[$role] ?? ucfirst($role);
    }

    /**
     * Get all valid roles
     *
     * @return array List of valid roles
     */
    public static function getValidRoles(): array
    {
        return array_keys(self::ROLE_HIERARCHY);
    }

    /**
     * Check if a role is valid
     *
     * @param string $role The role to check
     * @return bool True if valid
     */
    public static function isValidRole(string $role): bool
    {
        return isset(self::ROLE_HIERARCHY[$role]);
    }
}
