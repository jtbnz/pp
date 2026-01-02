<?php
declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PDO;

/**
 * Base TestCase class for all tests
 */
abstract class TestCase extends BaseTestCase
{
    protected ?PDO $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->db = null;
        parent::tearDown();
    }

    /**
     * Initialize an in-memory SQLite database with the schema
     */
    protected function initializeTestDatabase(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Load and execute schema
        $schemaPath = __DIR__ . '/../data/schema.sql';
        if (file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            $this->db->exec($schema);
        }
    }

    /**
     * Create a test brigade
     */
    protected function createTestBrigade(array $overrides = []): int
    {
        $data = array_merge([
            'name' => 'Test Brigade',
            'slug' => 'test-brigade',
            'primary_color' => '#D32F2F',
            'accent_color' => '#1976D2',
            'timezone' => 'Pacific/Auckland'
        ], $overrides);

        $stmt = $this->db->prepare('
            INSERT INTO brigades (name, slug, primary_color, accent_color, timezone)
            VALUES (:name, :slug, :primary_color, :accent_color, :timezone)
        ');
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a test member
     */
    protected function createTestMember(int $brigadeId, array $overrides = []): int
    {
        $data = array_merge([
            'brigade_id' => $brigadeId,
            'email' => 'test' . uniqid() . '@example.com',
            'name' => 'Test User',
            'role' => 'firefighter',
            'status' => 'active',
            'access_expires' => date('Y-m-d H:i:s', strtotime('+5 years'))
        ], $overrides);

        $stmt = $this->db->prepare('
            INSERT INTO members (brigade_id, email, name, role, status, access_expires)
            VALUES (:brigade_id, :email, :name, :role, :status, :access_expires)
        ');
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a test event
     */
    protected function createTestEvent(int $brigadeId, array $overrides = []): int
    {
        $data = array_merge([
            'brigade_id' => $brigadeId,
            'title' => 'Test Event',
            'description' => 'Test event description',
            'start_time' => date('Y-m-d H:i:s', strtotime('+1 week')),
            'end_time' => date('Y-m-d H:i:s', strtotime('+1 week +2 hours')),
            'is_training' => 0
        ], $overrides);

        $stmt = $this->db->prepare('
            INSERT INTO events (brigade_id, title, description, start_time, end_time, is_training)
            VALUES (:brigade_id, :title, :description, :start_time, :end_time, :is_training)
        ');
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a test notice
     */
    protected function createTestNotice(int $brigadeId, int $authorId, array $overrides = []): int
    {
        $data = array_merge([
            'brigade_id' => $brigadeId,
            'title' => 'Test Notice',
            'content' => 'Test notice content',
            'type' => 'standard',
            'author_id' => $authorId
        ], $overrides);

        $stmt = $this->db->prepare('
            INSERT INTO notices (brigade_id, title, content, type, author_id)
            VALUES (:brigade_id, :title, :content, :type, :author_id)
        ');
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a test leave request
     */
    protected function createTestLeaveRequest(int $memberId, array $overrides = []): int
    {
        $data = array_merge([
            'member_id' => $memberId,
            'training_date' => date('Y-m-d', strtotime('next monday')),
            'reason' => 'Test reason',
            'status' => 'pending'
        ], $overrides);

        $stmt = $this->db->prepare('
            INSERT INTO leave_requests (member_id, training_date, reason, status)
            VALUES (:member_id, :training_date, :reason, :status)
        ');
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }
}
