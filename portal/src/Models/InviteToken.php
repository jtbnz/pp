<?php
declare(strict_types=1);

/**
 * InviteToken Model
 *
 * Handles database operations for invite/magic link tokens.
 */
class InviteToken
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new invite token
     *
     * @param array $data Token data (brigade_id, email, token_hash, role, expires_at, created_by)
     * @return int The new token ID
     */
    public function create(array $data): int
    {
        $requiredFields = ['brigade_id', 'email', 'token_hash', 'expires_at'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $stmt = $this->db->prepare('
            INSERT INTO invite_tokens (brigade_id, email, token_hash, role, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $data['brigade_id'],
            strtolower(trim($data['email'])),
            $data['token_hash'],
            $data['role'] ?? 'firefighter',
            $data['expires_at'],
            $data['created_by'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find an invite token by its hashed value
     *
     * @param string $tokenHash The SHA256 hash of the token
     * @return array|null Token data or null if not found
     */
    public function findByToken(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare('
            SELECT it.*, b.name as brigade_name, b.slug as brigade_slug
            FROM invite_tokens it
            JOIN brigades b ON b.id = it.brigade_id
            WHERE it.token_hash = ?
        ');
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Find a valid (unused and not expired) token by its hash
     *
     * @param string $tokenHash The SHA256 hash of the token
     * @return array|null Token data or null if not valid
     */
    public function findValidToken(string $tokenHash): ?array
    {
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
     * Mark an invite token as used
     *
     * @param int $id The token record ID
     * @return bool Success
     */
    public function markUsed(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE invite_tokens SET used_at = datetime("now") WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /**
     * Find a token by ID
     *
     * @param int $id The token ID
     * @return array|null Token data or null if not found
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT it.*, b.name as brigade_name, b.slug as brigade_slug
            FROM invite_tokens it
            JOIN brigades b ON b.id = it.brigade_id
            WHERE it.id = ?
        ');
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Get all pending invites for a brigade
     *
     * @param int $brigadeId The brigade ID
     * @return array List of pending invites
     */
    public function getPendingByBrigade(int $brigadeId): array
    {
        $stmt = $this->db->prepare('
            SELECT it.*, m.name as created_by_name
            FROM invite_tokens it
            LEFT JOIN members m ON m.id = it.created_by
            WHERE it.brigade_id = ?
              AND it.used_at IS NULL
              AND it.expires_at > datetime("now")
            ORDER BY it.created_at DESC
        ');
        $stmt->execute([$brigadeId]);

        return $stmt->fetchAll();
    }

    /**
     * Delete expired tokens
     *
     * @return int Number of tokens deleted
     */
    public function deleteExpired(): int
    {
        $stmt = $this->db->prepare('
            DELETE FROM invite_tokens
            WHERE expires_at < datetime("now")
              OR used_at IS NOT NULL
        ');
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Check if an email has a pending invite for a brigade
     *
     * @param string $email The email address
     * @param int $brigadeId The brigade ID
     * @return bool True if a pending invite exists
     */
    public function hasPendingInvite(string $email, int $brigadeId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM invite_tokens
            WHERE LOWER(email) = LOWER(?)
              AND brigade_id = ?
              AND used_at IS NULL
              AND expires_at > datetime("now")
        ');
        $stmt->execute([$email, $brigadeId]);

        return (bool)$stmt->fetch();
    }

    /**
     * Revoke (delete) a pending invite by ID
     *
     * @param int $id The token ID
     * @return bool Success
     */
    public function revoke(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM invite_tokens WHERE id = ? AND used_at IS NULL');
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /**
     * Get all tokens for a specific email
     *
     * @param string $email The email address
     * @return array List of tokens
     */
    public function findByEmail(string $email): array
    {
        $stmt = $this->db->prepare('
            SELECT it.*, b.name as brigade_name
            FROM invite_tokens it
            JOIN brigades b ON b.id = it.brigade_id
            WHERE LOWER(it.email) = LOWER(?)
            ORDER BY it.created_at DESC
        ');
        $stmt->execute([$email]);

        return $stmt->fetchAll();
    }

    /**
     * Count pending invites for a brigade
     *
     * @param int $brigadeId The brigade ID
     * @return int Number of pending invites
     */
    public function countPendingByBrigade(int $brigadeId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM invite_tokens
            WHERE brigade_id = ?
              AND used_at IS NULL
              AND expires_at > datetime("now")
        ');
        $stmt->execute([$brigadeId]);

        return (int)$stmt->fetchColumn();
    }
}
