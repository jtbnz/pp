<?php
declare(strict_types=1);

require_once __DIR__ . '/../Exceptions/DlbApiException.php';

/**
 * DLB Client
 *
 * HTTP client for interacting with the DLB attendance system API.
 * Handles authentication, request formatting, and error handling.
 */
class DlbClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    /**
     * Create a new DLB API client
     *
     * @param string $baseUrl Base URL of the DLB API (e.g., https://kiaora.tech/dlb/puke)
     * @param string $token Bearer token for authentication
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(string $baseUrl, string $token, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
    }

    /**
     * Create a new muster for a specific date
     *
     * @param string $date Date in Y-m-d format
     * @param bool $visible Whether the muster should be visible in the dlb UI
     * @return array The created muster data
     * @throws DlbApiException
     */
    public function createMuster(string $date, bool $visible = false): array
    {
        $response = $this->request('POST', '/api/v1/musters', [
            'icad_number' => 'muster',
            'call_date' => $date,
            'call_time' => '19:00',
            'location' => 'Station',
            'call_type' => 'Training',
            'visible' => $visible
        ]);

        return $response['muster'] ?? $response;
    }

    /**
     * Set the visibility of a muster
     *
     * @param int $musterId Muster ID
     * @param bool $visible Whether to make the muster visible
     * @return bool True on success
     * @throws DlbApiException
     */
    public function setMusterVisibility(int $musterId, bool $visible): bool
    {
        $this->request('PUT', "/api/v1/musters/{$musterId}/visibility", [
            'visible' => $visible
        ]);

        return true;
    }

    /**
     * Set attendance status for a single member on a muster
     *
     * @param int $musterId Muster ID
     * @param int $memberId Member ID in dlb
     * @param string $status Status code: I (In Attendance), L (Leave), A (Absent)
     * @param string|null $notes Optional notes
     * @return bool True on success
     * @throws DlbApiException
     */
    public function setAttendanceStatus(int $musterId, int $memberId, string $status, ?string $notes = null): bool
    {
        $data = [
            'member_id' => $memberId,
            'status' => $status
        ];

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        $this->request('POST', "/api/v1/musters/{$musterId}/attendance", $data);

        return true;
    }

    /**
     * Bulk set attendance for multiple members on a muster
     *
     * @param int $musterId Muster ID
     * @param array $attendance Array of attendance records, each with member_id, status, and optional notes
     * @return array Results with created count, failed count, and individual results
     * @throws DlbApiException
     */
    public function bulkSetAttendance(int $musterId, array $attendance): array
    {
        return $this->request('POST', "/api/v1/musters/{$musterId}/attendance/bulk", [
            'attendance' => $attendance
        ]);
    }

    /**
     * Get all members from dlb
     *
     * @return array Array of member records
     * @throws DlbApiException
     */
    public function getMembers(): array
    {
        $response = $this->request('GET', '/api/v1/members');

        return $response['members'] ?? [];
    }

    /**
     * Get attendance records for a specific muster
     *
     * @param int $musterId Muster ID
     * @return array Muster data with attendance records and summary
     * @throws DlbApiException
     */
    public function getMusterAttendance(int $musterId): array
    {
        return $this->request('GET', "/api/v1/musters/{$musterId}/attendance");
    }

    /**
     * Find a muster by date
     *
     * @param string $date Date in Y-m-d format
     * @return array|null Muster data or null if not found
     * @throws DlbApiException
     */
    public function findMusterByDate(string $date): ?array
    {
        try {
            $response = $this->request('GET', '/api/v1/musters', [
                'from' => $date,
                'to' => $date,
                'status' => 'active'
            ]);

            $musters = $response['musters'] ?? [];

            // Return the first matching muster for this date
            foreach ($musters as $muster) {
                if (($muster['call_date'] ?? '') === $date) {
                    return $muster;
                }
            }

            return null;
        } catch (DlbApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * List musters within a date range
     *
     * @param string $from Start date (Y-m-d)
     * @param string $to End date (Y-m-d)
     * @param string|null $status Filter by status (active, submitted, etc.)
     * @return array Array of muster records
     * @throws DlbApiException
     */
    public function listMusters(string $from, string $to, ?string $status = null): array
    {
        $params = [
            'from' => $from,
            'to' => $to
        ];

        if ($status !== null) {
            $params['status'] = $status;
        }

        $response = $this->request('GET', '/api/v1/musters', $params);

        return $response['musters'] ?? [];
    }

    /**
     * Create a new member in dlb
     *
     * @param string $name Member name
     * @param string $rank Member rank (e.g., FF, QFF)
     * @param bool $isActive Whether the member is active
     * @return array Created member data
     * @throws DlbApiException
     */
    public function createMember(string $name, string $rank = 'FF', bool $isActive = true): array
    {
        return $this->request('POST', '/api/v1/members', [
            'name' => $name,
            'rank' => $rank,
            'is_active' => $isActive
        ]);
    }

    /**
     * Make an HTTP request to the DLB API
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (e.g., /api/v1/musters)
     * @param array $data Request data (query params for GET, body for POST/PUT)
     * @return array Parsed response data
     * @throws DlbApiException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        // For GET requests, append data as query string
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init();

        // Set common options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: PukePortal/1.0'
            ]
        ]);

        // Set method-specific options
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;

            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
        }

        // Execute request
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        // Handle curl errors
        if ($errno !== 0) {
            throw DlbApiException::fromCurlError($error, $errno);
        }

        // Parse response
        $response = null;
        if ($responseBody !== false && $responseBody !== '') {
            $response = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new DlbApiException(
                    'Invalid JSON response from DLB API: ' . json_last_error_msg(),
                    $httpCode,
                    ['raw_response' => substr($responseBody, 0, 1000)]
                );
            }
        }

        // Handle error responses
        if ($httpCode >= 400) {
            throw DlbApiException::fromResponse($httpCode, $response);
        }

        // Return parsed response or empty array for successful empty responses
        return $response ?? [];
    }

    /**
     * Test the API connection
     *
     * @return bool True if connection successful
     * @throws DlbApiException
     */
    public function testConnection(): bool
    {
        try {
            $this->getMembers();
            return true;
        } catch (DlbApiException $e) {
            // Re-throw auth errors, connection successful but unauthorized
            if ($e->isAuthError()) {
                throw $e;
            }
            // Other errors might indicate connection issues
            throw $e;
        }
    }

    /**
     * Get the configured base URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
