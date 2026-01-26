<?php
declare(strict_types=1);

namespace Portal\Services;

use PDO;

/**
 * Authentication Service
 *
 * Handles token generation, verification, session management, and PIN operations.
 */
class AuthService
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Generate a secure invite token
     *
     * @return string The raw token (to send in email)
     */
    public function generateInviteToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a token for secure storage
     *
     * @param string $token The raw token
     * @return string The hashed token
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Verify an invite/magic link token
     *
     * @param string $token The raw token from the URL
     * @return array|null Token data if valid, null otherwise
     */
    public function verifyToken(string $token): ?array
    {
        $tokenHash = $this->hashToken($token);
        $nowUtc = nowUtc();
        $debugEnabled = $this->config['auth']['debug'] ?? false;

        // First, try to find the token regardless of status for debug logging
        $stmt = $this->db->prepare('
            SELECT it.*, b.name as brigade_name, b.slug as brigade_slug
            FROM invite_tokens it
            JOIN brigades b ON b.id = it.brigade_id
            WHERE it.token_hash = ?
        ');
        $stmt->execute([$tokenHash]);
        $tokenRecord = $stmt->fetch();

        // Log debug info if enabled
        if ($debugEnabled) {
            $this->logAuthDebug('token_verify_attempt', [
                'token_found' => $tokenRecord ? true : false,
                'current_time_utc' => $nowUtc,
                'token_hash_prefix' => substr($tokenHash, 0, 16) . '...',
            ]);

            if ($tokenRecord) {
                $this->logAuthDebug('token_details', [
                    'token_id' => $tokenRecord['id'],
                    'email' => $tokenRecord['email'],
                    'expires_at' => $tokenRecord['expires_at'],
                    'used_at' => $tokenRecord['used_at'],
                    'created_at' => $tokenRecord['created_at'],
                    'is_expired' => $tokenRecord['expires_at'] <= $nowUtc,
                    'is_used' => $tokenRecord['used_at'] !== null,
                ]);
            }
        }

        // Check if token exists
        if (!$tokenRecord) {
            if ($debugEnabled) {
                $this->logAuthDebug('token_verify_failed', [
                    'reason' => 'token_not_found',
                    'message' => 'No token found with this hash',
                ]);
            }
            return null;
        }

        // Check if already used - but allow reuse within grace period for email filter prefetching
        // Corporate email filters (Microsoft Defender, Proofpoint, etc.) often pre-click links
        // to scan them, which would mark the token as used before the real user clicks
        $reusePeriodSeconds = $this->config['auth']['token_reuse_period_seconds'] ?? 300; // 5 minutes default
        if ($tokenRecord['used_at'] !== null) {
            $usedAtTimestamp = strtotime($tokenRecord['used_at']);
            $nowTimestamp = strtotime($nowUtc);
            $secondsSinceUsed = $nowTimestamp - $usedAtTimestamp;

            if ($secondsSinceUsed > $reusePeriodSeconds) {
                if ($debugEnabled) {
                    $this->logAuthDebug('token_verify_failed', [
                        'reason' => 'token_already_used',
                        'token_id' => $tokenRecord['id'],
                        'used_at' => $tokenRecord['used_at'],
                        'seconds_since_used' => $secondsSinceUsed,
                        'reuse_period_seconds' => $reusePeriodSeconds,
                        'message' => 'Token was already used and reuse period has expired',
                    ]);
                }
                return null;
            }

            // Token was used recently - allow reuse (likely email filter prefetch)
            if ($debugEnabled) {
                $this->logAuthDebug('token_reuse_allowed', [
                    'token_id' => $tokenRecord['id'],
                    'used_at' => $tokenRecord['used_at'],
                    'seconds_since_used' => $secondsSinceUsed,
                    'reuse_period_seconds' => $reusePeriodSeconds,
                    'message' => 'Token reuse allowed within grace period (possible email filter prefetch)',
                ]);
            }
        }

        // Check if expired
        if ($tokenRecord['expires_at'] <= $nowUtc) {
            if ($debugEnabled) {
                $this->logAuthDebug('token_verify_failed', [
                    'reason' => 'token_expired',
                    'token_id' => $tokenRecord['id'],
                    'expires_at' => $tokenRecord['expires_at'],
                    'current_time' => $nowUtc,
                    'expired_seconds_ago' => strtotime($nowUtc) - strtotime($tokenRecord['expires_at']),
                    'message' => 'Token has expired',
                ]);
            }
            return null;
        }

        if ($debugEnabled) {
            $this->logAuthDebug('token_verify_success', [
                'token_id' => $tokenRecord['id'],
                'email' => $tokenRecord['email'],
            ]);
        }

        return $tokenRecord;
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

    /**
     * Create a new session for a member
     *
     * @param int $memberId The member's ID
     * @return string The session ID
     */
    public function createSession(int $memberId): string
    {
        // Generate secure session ID
        $sessionId = bin2hex(random_bytes(64));

        // Session expires in 24 hours - store in UTC
        $sessionTimeout = $this->config['session']['timeout'] ?? 86400;
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $sessionTimeout);

        // Store session
        $stmt = $this->db->prepare('
            INSERT INTO sessions (id, member_id, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $memberId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt
        ]);

        // Update member's last login in UTC
        $nowUtc = nowUtc();
        $stmt = $this->db->prepare('UPDATE members SET last_login_at = ? WHERE id = ?');
        $stmt->execute([$nowUtc, $memberId]);

        return $sessionId;
    }

    /**
     * Verify a session and get member data
     *
     * @param string $sessionId The session ID
     * @return array|null Member data if valid, null otherwise
     */
    public function verifySession(string $sessionId): ?array
    {
        $nowUtc = nowUtc();

        $stmt = $this->db->prepare('
            SELECT s.*, m.id as member_id, m.email, m.name, m.role, m.status, m.brigade_id
            FROM sessions s
            JOIN members m ON m.id = s.member_id
            WHERE s.id = ?
              AND s.expires_at > ?
              AND m.status = "active"
        ');
        $stmt->execute([$sessionId, $nowUtc]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Extend a session's expiry time
     *
     * @param string $sessionId The session ID
     * @return bool Success
     */
    public function refreshSession(string $sessionId): bool
    {
        $sessionTimeout = $this->config['session']['timeout'] ?? 86400;
        // Store in UTC
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $sessionTimeout);

        $stmt = $this->db->prepare('UPDATE sessions SET expires_at = ? WHERE id = ?');
        return $stmt->execute([$expiresAt, $sessionId]);
    }

    /**
     * Destroy a session
     *
     * @param string $sessionId The session ID
     * @return bool Success
     */
    public function destroySession(string $sessionId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = ?');
        return $stmt->execute([$sessionId]);
    }

    /**
     * Clean up expired sessions
     *
     * @return int Number of sessions deleted
     */
    public function cleanupExpiredSessions(): int
    {
        $nowUtc = nowUtc();
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires_at < ?');
        $stmt->execute([$nowUtc]);
        return $stmt->rowCount();
    }

    /**
     * Verify a member's PIN
     *
     * @param int $memberId The member's ID
     * @param string $pin The PIN to verify
     * @return bool True if PIN is correct
     */
    public function verifyPin(int $memberId, string $pin): bool
    {
        $stmt = $this->db->prepare('SELECT pin_hash FROM members WHERE id = ? AND status = "active"');
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();

        if (!$result || !$result['pin_hash']) {
            return false;
        }

        return password_verify($pin, $result['pin_hash']);
    }

    /**
     * Hash a PIN for secure storage
     *
     * @param string $pin The raw PIN
     * @return string The hashed PIN
     */
    public function hashPin(string $pin): string
    {
        $options = $this->config['security']['password_hash_options'] ?? ['cost' => 12];
        $algo = $this->config['security']['password_hash_algo'] ?? PASSWORD_BCRYPT;

        return password_hash($pin, $algo, $options);
    }

    /**
     * Generate a new access token for long-term member access
     *
     * @return string The raw access token
     */
    public function generateAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check rate limiting for a key (e.g., login attempts)
     *
     * @param string $key The rate limit key
     * @return bool True if allowed, false if rate limited
     */
    public function checkRateLimit(string $key): bool
    {
        if (!($this->config['rate_limit']['enabled'] ?? true)) {
            return true;
        }

        $maxAttempts = $this->config['rate_limit']['max_attempts'] ?? 5;
        $decayMinutes = $this->config['rate_limit']['decay_minutes'] ?? 60;

        $stmt = $this->db->prepare('
            SELECT * FROM rate_limits
            WHERE key = ?
        ');
        $stmt->execute([$key]);
        $record = $stmt->fetch();

        if (!$record) {
            return true;
        }

        // Check if locked out
        if ($record['locked_until'] && strtotime($record['locked_until']) > time()) {
            return false;
        }

        // Check if within decay window and over limit
        $firstAttempt = strtotime($record['first_attempt_at']);
        $windowEnd = $firstAttempt + ($decayMinutes * 60);

        if (time() <= $windowEnd && $record['attempts'] >= $maxAttempts) {
            return false;
        }

        // Reset if outside decay window
        if (time() > $windowEnd) {
            $this->resetRateLimit($key);
        }

        return true;
    }

    /**
     * Record a rate limit attempt
     *
     * @param string $key The rate limit key
     * @return void
     */
    public function recordRateLimitAttempt(string $key): void
    {
        $maxAttempts = $this->config['rate_limit']['max_attempts'] ?? 5;
        $lockoutMinutes = $this->config['rate_limit']['lockout_minutes'] ?? 15;

        $stmt = $this->db->prepare('SELECT * FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);
        $record = $stmt->fetch();

        if (!$record) {
            // First attempt - store in UTC
            $nowUtc = nowUtc();
            $stmt = $this->db->prepare('
                INSERT INTO rate_limits (key, attempts, first_attempt_at)
                VALUES (?, 1, ?)
            ');
            $stmt->execute([$key, $nowUtc]);
        } else {
            // Increment attempts
            $newAttempts = $record['attempts'] + 1;
            $lockedUntil = null;

            // Lock if over limit - store in UTC
            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = gmdate('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            }

            $stmt = $this->db->prepare('
                UPDATE rate_limits
                SET attempts = ?, locked_until = ?
                WHERE key = ?
            ');
            $stmt->execute([$newAttempts, $lockedUntil, $key]);
        }
    }

    /**
     * Reset rate limit for a key
     *
     * @param string $key The rate limit key
     * @return void
     */
    public function resetRateLimit(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);
    }

    /**
     * Get remaining lockout time in seconds
     *
     * @param string $key The rate limit key
     * @return int Seconds remaining, or 0 if not locked
     */
    public function getRateLimitRemainingTime(string $key): int
    {
        $stmt = $this->db->prepare('SELECT locked_until FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);
        $record = $stmt->fetch();

        if (!$record || !$record['locked_until']) {
            return 0;
        }

        // locked_until is stored in UTC, compare with current UTC time
        $remaining = strtotime($record['locked_until'] . ' UTC') - time();
        return max(0, $remaining);
    }

    /**
     * Create an invite token for a new member
     *
     * @param int $brigadeId Brigade ID
     * @param string $email Email address
     * @param string $role Role to assign
     * @param int|null $createdBy Member ID who created the invite
     * @return string The raw token (to send in email)
     */
    public function createInviteToken(int $brigadeId, string $email, string $role = 'firefighter', ?int $createdBy = null): string
    {
        $token = $this->generateInviteToken();
        $tokenHash = $this->hashToken($token);

        $expiryDays = $this->config['auth']['invite_expiry_days'] ?? 7;
        // Store expiry in UTC
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($expiryDays * 86400));

        $stmt = $this->db->prepare('
            INSERT INTO invite_tokens (brigade_id, email, token_hash, role, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$brigadeId, $email, $tokenHash, $role, $expiresAt, $createdBy]);

        return $token;
    }

    /**
     * Mark an invite token as used
     *
     * @param int $tokenId The token record ID
     * @return bool Success
     */
    public function markTokenUsed(int $tokenId): bool
    {
        $nowUtc = nowUtc();
        $stmt = $this->db->prepare('UPDATE invite_tokens SET used_at = ? WHERE id = ?');
        return $stmt->execute([$nowUtc, $tokenId]);
    }

    /**
     * Log an audit event
     *
     * @param string $action The action performed
     * @param int|null $brigadeId Brigade ID
     * @param int|null $memberId Member ID who performed the action
     * @param string|null $entityType Type of entity affected
     * @param int|null $entityId ID of entity affected
     * @param array|null $details Additional details
     * @return void
     */
    public function logAudit(
        string $action,
        ?int $brigadeId = null,
        ?int $memberId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $details = null
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO audit_log (brigade_id, member_id, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $brigadeId,
            $memberId,
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}
