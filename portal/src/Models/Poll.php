<?php
declare(strict_types=1);

/**
 * Poll Model
 *
 * Handles all database operations for polls, options, and votes.
 * Poll types: single (one choice), multi (multiple choices allowed)
 */
class Poll
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
    }

    /**
     * Find all active polls for a brigade
     * Auto-closes expired polls before returning results.
     *
     * @param int $brigadeId
     * @return array
     */
    public function findActive(int $brigadeId): array
    {
        // First, close any expired polls
        $this->closeExpired($brigadeId);

        $sql = "
            SELECT p.*, m.name as created_by_name
            FROM polls p
            LEFT JOIN members m ON p.created_by = m.id
            WHERE p.brigade_id = ?
                AND p.status = 'active'
            ORDER BY p.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        $polls = $stmt->fetchAll();

        // Add options and vote counts to each poll
        foreach ($polls as &$poll) {
            $poll['options'] = $this->getOptions((int)$poll['id']);
            $poll['total_votes'] = $this->getTotalVoters((int)$poll['id']);
        }

        return $polls;
    }

    /**
     * Find all polls for a brigade with optional filters
     *
     * @param int $brigadeId
     * @param array $filters Optional filters: status, limit, offset
     * @return array
     */
    public function findAll(int $brigadeId, array $filters = []): array
    {
        $sql = "
            SELECT p.*, m.name as created_by_name
            FROM polls p
            LEFT JOIN members m ON p.created_by = m.id
            WHERE p.brigade_id = ?
        ";

        $params = [$brigadeId];

        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND p.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY p.created_at DESC";

        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];

            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $polls = $stmt->fetchAll();

        // Add options and vote counts to each poll
        foreach ($polls as &$poll) {
            $poll['options'] = $this->getOptions((int)$poll['id']);
            $poll['total_votes'] = $this->getTotalVoters((int)$poll['id']);
        }

        return $polls;
    }

    /**
     * Find a poll by ID with all options and results
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT p.*, m.name as created_by_name
            FROM polls p
            LEFT JOIN members m ON p.created_by = m.id
            WHERE p.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        $poll = $stmt->fetch();
        if (!$poll) {
            return null;
        }

        // Add options with vote counts
        $poll['options'] = $this->getOptionsWithResults($id);
        $poll['total_votes'] = $this->getTotalVoters($id);

        return $poll;
    }

    /**
     * Count polls for a brigade with optional filters
     *
     * @param int $brigadeId
     * @param array $filters
     * @return int
     */
    public function count(int $brigadeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM polls WHERE brigade_id = ?";
        $params = [$brigadeId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a new poll with options
     *
     * @param array $data Poll data including 'options' array
     * @return int The new poll ID
     */
    public function create(array $data): int
    {
        $nowUtc = nowUtc();

        // Convert closes_at from local time to UTC if provided
        $closesAt = !empty($data['closes_at']) ? toUtc($data['closes_at']) : null;

        $sql = "
            INSERT INTO polls (brigade_id, title, description, type, status, closes_at, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['brigade_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['type'] ?? 'single',
            $closesAt,
            $data['created_by'],
            $nowUtc,
            $nowUtc,
        ]);

        $pollId = (int)$this->db->lastInsertId();

        // Add options
        if (!empty($data['options'])) {
            foreach ($data['options'] as $order => $optionText) {
                if (!empty(trim($optionText))) {
                    $this->addOption($pollId, trim($optionText), $order);
                }
            }
        }

        return $pollId;
    }

    /**
     * Update an existing poll
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = ['title', 'description', 'type', 'closes_at'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                // Convert closes_at from local time to UTC
                if ($field === 'closes_at' && $data[$field] !== null) {
                    $params[] = toUtc($data[$field]);
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;

        $sql = "UPDATE polls SET " . implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Close a poll
     *
     * @param int $id
     * @return bool
     */
    public function close(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE polls SET status = 'closed' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Delete a poll and all associated options/votes
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM polls WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Add an option to a poll
     *
     * @param int $pollId
     * @param string $text
     * @param int $order
     * @return int The new option ID
     */
    public function addOption(int $pollId, string $text, int $order = 0): int
    {
        $sql = "INSERT INTO poll_options (poll_id, text, display_order) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId, $text, $order]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get all options for a poll
     *
     * @param int $pollId
     * @return array
     */
    public function getOptions(int $pollId): array
    {
        $sql = "SELECT * FROM poll_options WHERE poll_id = ? ORDER BY display_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId]);

        return $stmt->fetchAll();
    }

    /**
     * Get options with vote counts and voter names
     *
     * @param int $pollId
     * @return array
     */
    public function getOptionsWithResults(int $pollId): array
    {
        $options = $this->getOptions($pollId);

        foreach ($options as &$option) {
            // Get vote count
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM poll_votes WHERE option_id = ?");
            $countStmt->execute([$option['id']]);
            $option['vote_count'] = (int)$countStmt->fetchColumn();

            // Get voters
            $votersStmt = $this->db->prepare("
                SELECT m.id, m.name
                FROM poll_votes pv
                JOIN members m ON pv.member_id = m.id
                WHERE pv.option_id = ?
                ORDER BY pv.voted_at ASC
            ");
            $votersStmt->execute([$option['id']]);
            $option['voters'] = $votersStmt->fetchAll();
        }

        return $options;
    }

    /**
     * Update options for a poll (replaces all existing options)
     *
     * @param int $pollId
     * @param array $options Array of option texts
     * @return bool
     */
    public function updateOptions(int $pollId, array $options): bool
    {
        // Delete existing options (votes will cascade delete)
        $this->db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$pollId]);

        // Add new options
        foreach ($options as $order => $optionText) {
            if (!empty(trim($optionText))) {
                $this->addOption($pollId, trim($optionText), $order);
            }
        }

        return true;
    }

    /**
     * Cast a vote for an option
     * For single-choice polls, clears any existing vote first.
     *
     * @param int $pollId
     * @param int $optionId
     * @param int $memberId
     * @return bool
     */
    public function vote(int $pollId, int $optionId, int $memberId): bool
    {
        // Get poll type
        $poll = $this->findById($pollId);
        if (!$poll || $poll['status'] !== 'active') {
            return false;
        }

        // For single-choice polls, remove any existing votes first
        if ($poll['type'] === 'single') {
            $this->clearVotes($pollId, $memberId);
        }

        // Check if already voted for this option
        $checkStmt = $this->db->prepare("
            SELECT 1 FROM poll_votes WHERE poll_id = ? AND option_id = ? AND member_id = ?
        ");
        $checkStmt->execute([$pollId, $optionId, $memberId]);
        if ($checkStmt->fetch()) {
            // Already voted for this option
            return true;
        }

        $nowUtc = nowUtc();
        $sql = "INSERT INTO poll_votes (poll_id, option_id, member_id, voted_at) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([$pollId, $optionId, $memberId, $nowUtc]);
    }

    /**
     * Remove a vote for an option
     *
     * @param int $pollId
     * @param int $optionId
     * @param int $memberId
     * @return bool
     */
    public function removeVote(int $pollId, int $optionId, int $memberId): bool
    {
        $sql = "DELETE FROM poll_votes WHERE poll_id = ? AND option_id = ? AND member_id = ?";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([$pollId, $optionId, $memberId]);
    }

    /**
     * Clear all votes for a member on a poll
     *
     * @param int $pollId
     * @param int $memberId
     * @return bool
     */
    public function clearVotes(int $pollId, int $memberId): bool
    {
        $sql = "DELETE FROM poll_votes WHERE poll_id = ? AND member_id = ?";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([$pollId, $memberId]);
    }

    /**
     * Get all votes for a poll grouped by option
     *
     * @param int $pollId
     * @return array
     */
    public function getVotes(int $pollId): array
    {
        $sql = "
            SELECT pv.*, m.name as member_name, po.text as option_text
            FROM poll_votes pv
            JOIN members m ON pv.member_id = m.id
            JOIN poll_options po ON pv.option_id = po.id
            WHERE pv.poll_id = ?
            ORDER BY po.display_order ASC, pv.voted_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId]);

        return $stmt->fetchAll();
    }

    /**
     * Get total number of unique voters for a poll
     *
     * @param int $pollId
     * @return int
     */
    public function getTotalVoters(int $pollId): int
    {
        $sql = "SELECT COUNT(DISTINCT member_id) FROM poll_votes WHERE poll_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a member has voted on a poll
     *
     * @param int $pollId
     * @param int $memberId
     * @return bool
     */
    public function hasVoted(int $pollId, int $memberId): bool
    {
        $sql = "SELECT 1 FROM poll_votes WHERE poll_id = ? AND member_id = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId, $memberId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Get the option IDs a member has voted for
     *
     * @param int $pollId
     * @param int $memberId
     * @return array Array of option IDs
     */
    public function getMemberVotes(int $pollId, int $memberId): array
    {
        $sql = "SELECT option_id FROM poll_votes WHERE poll_id = ? AND member_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pollId, $memberId]);

        return array_column($stmt->fetchAll(), 'option_id');
    }

    /**
     * Check if a poll belongs to a brigade
     *
     * @param int $id
     * @param int $brigadeId
     * @return bool
     */
    public function belongsToBrigade(int $id, int $brigadeId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM polls WHERE id = ? AND brigade_id = ?");
        $stmt->execute([$id, $brigadeId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Close all expired polls for a brigade
     *
     * @param int $brigadeId
     * @return int Number of polls closed
     */
    private function closeExpired(int $brigadeId): int
    {
        $nowUtc = nowUtc();

        $sql = "
            UPDATE polls
            SET status = 'closed'
            WHERE brigade_id = ?
                AND status = 'active'
                AND closes_at IS NOT NULL
                AND closes_at < ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $nowUtc]);

        return $stmt->rowCount();
    }

    /**
     * Get count of unvoted active polls for a member
     *
     * @param int $brigadeId
     * @param int $memberId
     * @return int
     */
    public function getUnvotedCount(int $brigadeId, int $memberId): int
    {
        // First close expired polls
        $this->closeExpired($brigadeId);

        $sql = "
            SELECT COUNT(*)
            FROM polls p
            WHERE p.brigade_id = ?
                AND p.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM poll_votes pv WHERE pv.poll_id = p.id AND pv.member_id = ?
                )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $memberId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Validate poll data
     *
     * @param array $data
     * @return array Validation errors (empty if valid)
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($data['title']) > 200) {
            $errors['title'] = 'Title must be 200 characters or less';
        }

        if (isset($data['type']) && !in_array($data['type'], ['single', 'multi'], true)) {
            $errors['type'] = 'Invalid poll type';
        }

        // Must have at least 2 options
        if (empty($data['options']) || !is_array($data['options'])) {
            $errors['options'] = 'At least 2 options are required';
        } else {
            $validOptions = array_filter($data['options'], fn($o) => !empty(trim($o)));
            if (count($validOptions) < 2) {
                $errors['options'] = 'At least 2 options are required';
            }
        }

        // Validate closes_at if provided
        if (!empty($data['closes_at']) && !strtotime($data['closes_at'])) {
            $errors['closes_at'] = 'Invalid date format';
        }

        return $errors;
    }
}
