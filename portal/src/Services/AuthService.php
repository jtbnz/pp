<?php
declare(strict_types=1);

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

        $stmt = $this->db->prepare('
            SELECT it.*, b.name as brigade_name, b.slug as brigade_slug
            FROM invite_tokens it
            JOIN brigades b ON b.id = it.brigade_id
            WHERE it.token_hash = ?
              AND it.used_at IS NULL
              AND it.expires_at > datetime("now")
        ');
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch();

        return $result ?: null;
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

        // Session expires in 24 hours
        $sessionTimeout = $this->config['session']['timeout'] ?? 86400;
        $expiresAt = date('Y-m-d H:i:s', time() + $sessionTimeout);

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

        // Update member's last login
        $stmt = $this->db->prepare('UPDATE members SET last_login_at = datetime("now") WHERE id = ?');
        $stmt->execute([$memberId]);

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
        $stmt = $this->db->prepare('
            SELECT s.*, m.id as member_id, m.email, m.name, m.role, m.status, m.brigade_id
            FROM sessions s
            JOIN members m ON m.id = s.member_id
            WHERE s.id = ?
              AND s.expires_at > datetime("now")
              AND m.status = "active"
        ');
        $stmt->execute([$sessionId]);
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
        $expiresAt = date('Y-m-d H:i:s', time() + $sessionTimeout);

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
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires_at < datetime("now")');
        $stmt->execute();
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
            // First attempt
            $stmt = $this->db->prepare('
                INSERT INTO rate_limits (key, attempts, first_attempt_at)
                VALUES (?, 1, datetime("now"))
            ');
            $stmt->execute([$key]);
        } else {
            // Increment attempts
            $newAttempts = $record['attempts'] + 1;
            $lockedUntil = null;

            // Lock if over limit
            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
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

        $remaining = strtotime($record['locked_until']) - time();
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
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryDays * 86400));

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
        $stmt = $this->db->prepare('UPDATE invite_tokens SET used_at = datetime("now") WHERE id = ?');
        return $stmt->execute([$tokenId]);
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
