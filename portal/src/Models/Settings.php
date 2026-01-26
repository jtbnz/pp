<?php
declare(strict_types=1);

namespace Portal\Models;

use PDO;
use RuntimeException;
use Exception;

/**
 * Settings Model
 *
 * Handles brigade-specific settings stored as key-value pairs.
 * Settings can store various configuration options like training times,
 * notification preferences, integration settings, etc.
 */
class Settings
{
    private PDO $db;

    /** @var array<int, array<string, mixed>> Cache of loaded settings by brigade */
    private static array $cache = [];

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
     * Get a single setting value
     *
     * @param int $brigadeId Brigade ID
     * @param string $key Setting key
     * @param mixed $default Default value if setting not found
     * @return mixed Setting value or default
     */
    public function get(int $brigadeId, string $key, mixed $default = null): mixed
    {
        // Check cache first
        if (isset(self::$cache[$brigadeId][$key])) {
            return self::$cache[$brigadeId][$key];
        }

        $sql = "SELECT value, type FROM settings WHERE brigade_id = ? AND key = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $key]);

        $result = $stmt->fetch();

        if (!$result) {
            return $default;
        }

        $value = $this->castValue($result['value'], $result['type']);

        // Cache the result
        if (!isset(self::$cache[$brigadeId])) {
            self::$cache[$brigadeId] = [];
        }
        self::$cache[$brigadeId][$key] = $value;

        return $value;
    }

    /**
     * Set a setting value
     *
     * @param int $brigadeId Brigade ID
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success
     */
    public function set(int $brigadeId, string $key, mixed $value): bool
    {
        $type = $this->detectType($value);
        $stringValue = $this->serializeValue($value, $type);

        // Use INSERT OR REPLACE for SQLite
        $sql = "
            INSERT OR REPLACE INTO settings (brigade_id, key, value, type, updated_at)
            VALUES (?, ?, ?, ?, datetime('now', 'localtime'))
        ";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$brigadeId, $key, $stringValue, $type]);

        // Update cache
        if ($result) {
            if (!isset(self::$cache[$brigadeId])) {
                self::$cache[$brigadeId] = [];
            }
            self::$cache[$brigadeId][$key] = $value;
        }

        return $result;
    }

    /**
     * Get all settings for a brigade
     *
     * @param int $brigadeId Brigade ID
     * @return array Associative array of all settings
     */
    public function getAll(int $brigadeId): array
    {
        $sql = "SELECT key, value, type FROM settings WHERE brigade_id = ? ORDER BY key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        $results = $stmt->fetchAll();
        $settings = [];

        foreach ($results as $row) {
            $settings[$row['key']] = $this->castValue($row['value'], $row['type']);
        }

        // Update cache
        self::$cache[$brigadeId] = $settings;

        return $settings;
    }

    /**
     * Set multiple settings at once
     *
     * @param int $brigadeId Brigade ID
     * @param array $settings Associative array of key => value pairs
     * @return bool Success
     */
    public function setMultiple(int $brigadeId, array $settings): bool
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                if (!$this->set($brigadeId, $key, $value)) {
                    throw new RuntimeException("Failed to set setting: {$key}");
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Delete a setting
     *
     * @param int $brigadeId Brigade ID
     * @param string $key Setting key
     * @return bool Success
     */
    public function delete(int $brigadeId, string $key): bool
    {
        $sql = "DELETE FROM settings WHERE brigade_id = ? AND key = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$brigadeId, $key]);

        // Clear from cache
        if (isset(self::$cache[$brigadeId][$key])) {
            unset(self::$cache[$brigadeId][$key]);
        }

        return $result;
    }

    /**
     * Check if a setting exists
     *
     * @param int $brigadeId Brigade ID
     * @param string $key Setting key
     * @return bool True if setting exists
     */
    public function exists(int $brigadeId, string $key): bool
    {
        $sql = "SELECT 1 FROM settings WHERE brigade_id = ? AND key = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $key]);

        return $stmt->fetch() !== false;
    }

    /**
     * Get settings matching a key prefix
     *
     * @param int $brigadeId Brigade ID
     * @param string $prefix Key prefix (e.g., 'training.' for all training settings)
     * @return array Matching settings
     */
    public function getByPrefix(int $brigadeId, string $prefix): array
    {
        $sql = "SELECT key, value, type FROM settings WHERE brigade_id = ? AND key LIKE ? ORDER BY key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $prefix . '%']);

        $results = $stmt->fetchAll();
        $settings = [];

        foreach ($results as $row) {
            $settings[$row['key']] = $this->castValue($row['value'], $row['type']);
        }

        return $settings;
    }

    /**
     * Clear the settings cache
     *
     * @param int|null $brigadeId Specific brigade ID or null for all
     */
    public function clearCache(?int $brigadeId = null): void
    {
        if ($brigadeId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$brigadeId]);
        }
    }

    /**
     * Detect the type of a value
     *
     * @param mixed $value Value to check
     * @return string Type identifier
     */
    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            is_null($value) => 'null',
            default => 'string'
        };
    }

    /**
     * Serialize a value for storage
     *
     * @param mixed $value Value to serialize
     * @param string $type Value type
     * @return string Serialized value
     */
    private function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer', 'float' => (string)$value,
            'json' => json_encode($value),
            'null' => '',
            default => (string)$value
        };
    }

    /**
     * Cast a stored value back to its original type
     *
     * @param string $value Stored value
     * @param string $type Value type
     * @return mixed Typed value
     */
    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => $value === '1',
            'integer' => (int)$value,
            'float' => (float)$value,
            'json' => json_decode($value, true),
            'null' => null,
            default => $value
        };
    }

    /**
     * Get default settings for a new brigade
     *
     * @return array Default settings
     */
    public static function getDefaults(): array
    {
        return [
            // Training settings
            'training.day' => 'monday',
            'training.time' => '19:00',
            'training.duration_hours' => 2,
            'training.location' => 'Fire Station',
            'training.move_on_holiday' => true,
            'training.holiday_move_to' => 'tuesday',

            // Notification settings
            'notifications.leave_request' => true,
            'notifications.leave_approved' => true,
            'notifications.leave_denied' => true,
            'notifications.new_notice' => true,
            'notifications.urgent_notice' => true,
            'notifications.training_reminder' => true,
            'notifications.training_reminder_hours' => 24,

            // Leave settings
            'leave.max_advance_trainings' => 3,
            'leave.require_approval' => true,
            'leave.auto_approve_officers' => false,

            // Display settings
            'display.show_ranks' => true,
            'display.calendar_start_day' => 0, // 0 = Sunday, 1 = Monday

            // Calendar/Holiday settings
            'calendar.holiday_region' => 'auckland', // NZ region for holiday filtering
            'calendar.show_holidays' => true,

            // DLB Integration
            'dlb.enabled' => false,
            'dlb.auto_sync' => false,
            'dlb.sync_interval_hours' => 24,
        ];
    }

    /**
     * Initialize default settings for a brigade
     *
     * @param int $brigadeId Brigade ID
     * @return bool Success
     */
    public function initializeDefaults(int $brigadeId): bool
    {
        $defaults = self::getDefaults();

        foreach ($defaults as $key => $value) {
            // Only set if not already exists
            if (!$this->exists($brigadeId, $key)) {
                $this->set($brigadeId, $key, $value);
            }
        }

        return true;
    }
}
