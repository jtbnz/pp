<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use PDO;

/**
 * Session API Controller
 *
 * Handles session restoration from remember tokens stored in localStorage.
 * This solves the iOS Safari -> PWA cookie jar isolation issue.
 */
class SessionApiController
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        global $db, $config;
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Restore session from remember token
     * POST /api/session/restore
     *
     * Request body:
     * {
     *   "token": "the-remember-token-from-localStorage"
     * }
     *
     * This endpoint does NOT require auth middleware because we're using it
     * to establish authentication from a localStorage token.
     */
    public function restore(): void
    {
        $debugEnabled = $this->config['auth']['debug'] ?? false;

        // Parse request body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['token'])) {
            jsonResponse(['error' => 'Missing token'], 400);
            return;
        }

        $token = $input['token'];
        $tokenHash = hash('sha256', $token);

        // Look up the token
        $stmt = $this->db->prepare('
            SELECT rt.*, m.status as member_status, m.name as member_name,
                   m.email as member_email, m.role as member_role, m.brigade_id
            FROM remember_tokens rt
            JOIN members m ON m.id = rt.member_id
            WHERE rt.token_hash = ?
              AND rt.expires_at > datetime("now")
              AND m.status = "active"
        ');
        $stmt->execute([$tokenHash]);
        $tokenRecord = $stmt->fetch();

        if (!$tokenRecord) {
            if ($debugEnabled) {
                $this->logAuthDebug('session_restore_failed', [
                    'reason' => 'invalid_or_expired_token',
                    'token_prefix' => substr($token, 0, 8) . '...',
                ]);
            }
            jsonResponse(['error' => 'Invalid or expired token', 'should_clear' => true], 401);
            return;
        }

        // Valid token - restore session
        $_SESSION['member_id'] = $tokenRecord['member_id'];
        $_SESSION['brigade_id'] = $tokenRecord['brigade_id'];
        $_SESSION['member_name'] = $tokenRecord['member_name'];
        $_SESSION['member_role'] = $tokenRecord['member_role'];
        $_SESSION['member_email'] = $tokenRecord['member_email'];
        $_SESSION['last_activity'] = time();
        $_SESSION['created'] = time();
        $_SESSION['restored_from_localstorage'] = true;

        // Update last used
        $stmt = $this->db->prepare('UPDATE remember_tokens SET last_used_at = datetime("now") WHERE id = ?');
        $stmt->execute([$tokenRecord['id']]);

        if ($debugEnabled) {
            $this->logAuthDebug('session_restore_success', [
                'member_id' => $tokenRecord['member_id'],
                'member_name' => $tokenRecord['member_name'],
                'token_id' => $tokenRecord['id'],
                'device_name' => $tokenRecord['device_name'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        }

        jsonResponse([
            'success' => true,
            'member' => [
                'id' => $tokenRecord['member_id'],
                'name' => $tokenRecord['member_name'],
                'role' => $tokenRecord['member_role'],
            ]
        ]);
    }

    /**
     * Check current session status
     * GET /api/session/status
     *
     * This endpoint does NOT require auth middleware.
     * Returns whether the user is currently authenticated.
     */
    public function status(): void
    {
        $user = currentUser();

        if ($user) {
            jsonResponse([
                'authenticated' => true,
                'member' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'role' => $user['role'],
                ]
            ]);
        } else {
            jsonResponse([
                'authenticated' => false,
                'member' => null
            ]);
        }
    }

    /**
     * Log authentication debug information
     */
    private function logAuthDebug(string $event, array $data): void
    {
        $logFile = __DIR__ . '/../../data/logs/auth-debug.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        $logEntry = "[{$timestamp}] {$event}: {$dataJson}\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
