<?php
declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Services\DlbClient;
use Portal\Services\SyncService;
use Portal\Exceptions\DlbApiException;
use PDO;
use RuntimeException;

/**
 * Sync Controller
 *
 * Handles API endpoints for DLB synchronization operations.
 * All endpoints require authentication.
 */
class SyncController
{
    private PDO $db;
    private array $config;
    private ?SyncService $syncService = null;

    public function __construct()
    {
        global $db, $config;
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Get the SyncService instance
     *
     * @return SyncService
     * @throws RuntimeException If DLB is not configured
     */
    private function getSyncService(): SyncService
    {
        if ($this->syncService === null) {
            $dlbConfig = $this->config['dlb'] ?? [];

            if (empty($dlbConfig['enabled'])) {
                throw new RuntimeException('DLB integration is not enabled');
            }

            if (empty($dlbConfig['api_token'])) {
                throw new RuntimeException('DLB API token is not configured');
            }

            $client = new DlbClient(
                $dlbConfig['base_url'] ?? 'https://kiaora.tech/dlb/puke',
                $dlbConfig['api_token'],
                $dlbConfig['timeout'] ?? 30
            );

            $this->syncService = new SyncService($client, $this->db);
        }

        return $this->syncService;
    }

    /**
     * GET /api/sync/status
     *
     * Get the current sync status for all operations
     */
    public function status(): void
    {
        try {
            $syncService = $this->getSyncService();
            $status = $syncService->getSyncStatus();

            jsonResponse([
                'success' => true,
                'status' => $status,
                'dlb_enabled' => !empty($this->config['dlb']['enabled']),
                'dlb_base_url' => $this->config['dlb']['base_url'] ?? null
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'dlb_enabled' => false
            ]);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'error' => 'DLB API error',
                'details' => $e->getSummary(),
                'dlb_enabled' => true
            ], $e->isAuthError() ? 401 : 500);
        }
    }

    /**
     * POST /api/sync/members
     *
     * Manually trigger member synchronization from DLB
     * Requires admin role.
     */
    public function members(): void
    {
        // Check admin permission
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $syncService = $this->getSyncService();
            $results = $syncService->syncMembersToDlb();

            jsonResponse([
                'success' => true,
                'message' => 'Member sync completed',
                'results' => $results
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'error' => 'DLB API error',
                'details' => $e->getSummary()
            ], $e->getHttpCode() ?: 500);
        }
    }

    /**
     * POST /api/sync/musters
     *
     * Manually trigger muster generation in DLB
     * Creates invisible musters for future training nights.
     * Requires admin role.
     */
    public function musters(): void
    {
        // Check admin permission
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            // Get months parameter from request body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $months = (int)($input['months'] ?? $this->config['training']['generate_months_ahead'] ?? 12);

            // Limit to reasonable range
            $months = max(1, min($months, 24));

            $syncService = $this->getSyncService();
            $results = $syncService->createFutureMusters($months);

            jsonResponse([
                'success' => $results['failed'] === 0,
                'message' => 'Muster sync completed',
                'months_ahead' => $months,
                'results' => $results
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'error' => 'DLB API error',
                'details' => $e->getSummary()
            ], $e->getHttpCode() ?: 500);
        }
    }

    /**
     * Trigger reveal of today's muster
     *
     * This is typically called by cron, but can be triggered manually.
     * Requires admin role.
     */
    public function revealToday(): void
    {
        // Check admin permission
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $syncService = $this->getSyncService();
            $revealed = $syncService->revealTodaysMuster();

            jsonResponse([
                'success' => true,
                'revealed' => $revealed,
                'date' => date('Y-m-d'),
                'message' => $revealed
                    ? 'Today\'s muster has been revealed'
                    : 'No training scheduled for today'
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'error' => 'DLB API error',
                'details' => $e->getSummary()
            ], $e->getHttpCode() ?: 500);
        }
    }

    /**
     * POST /api/sync/import-members
     *
     * Import members from DLB into Portal.
     * Creates new members and links existing ones.
     * Requires admin role.
     */
    public function importMembers(): void
    {
        // Check admin permission
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $brigadeId = $_SESSION['brigade_id'] ?? 1;

            $syncService = $this->getSyncService();
            $results = $syncService->importMembersFromDlb($brigadeId);

            jsonResponse([
                'success' => empty($results['errors']),
                'message' => sprintf(
                    'Import completed: %d imported, %d linked, %d skipped',
                    $results['imported'],
                    $results['linked'],
                    $results['skipped']
                ),
                'results' => $results
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'error' => 'DLB API error',
                'details' => $e->getSummary()
            ], $e->getHttpCode() ?: 500);
        }
    }

    /**
     * Test DLB connection
     *
     * Requires admin role.
     */
    public function testConnection(): void
    {
        // Check admin permission
        if (!isAdmin()) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $syncService = $this->getSyncService();
            $client = $syncService->getDlbClient();
            $connected = $client->testConnection();

            jsonResponse([
                'success' => true,
                'connected' => $connected,
                'base_url' => $client->getBaseUrl()
            ]);

        } catch (RuntimeException $e) {
            jsonResponse([
                'success' => false,
                'connected' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (DlbApiException $e) {
            jsonResponse([
                'success' => false,
                'connected' => false,
                'error' => $e->getSummary(),
                'is_auth_error' => $e->isAuthError()
            ], $e->getHttpCode() ?: 500);
        }
    }
}
