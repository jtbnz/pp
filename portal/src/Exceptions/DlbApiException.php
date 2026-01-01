<?php
declare(strict_types=1);

/**
 * DLB API Exception
 *
 * Custom exception for DLB API errors.
 * Stores HTTP status code and response body for debugging.
 */
class DlbApiException extends Exception
{
    private int $httpCode;
    private ?array $response;

    /**
     * Create a new DlbApiException
     *
     * @param string $message Error message
     * @param int $httpCode HTTP status code
     * @param array|null $response Response body from API
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        int $httpCode = 0,
        ?array $response = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
        $this->response = $response;
    }

    /**
     * Get the HTTP status code
     *
     * @return int
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Get the API response body
     *
     * @return array|null
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /**
     * Get the error code from the API response
     *
     * @return string|null
     */
    public function getApiErrorCode(): ?string
    {
        return $this->response['error']['code'] ?? null;
    }

    /**
     * Get the error message from the API response
     *
     * @return string|null
     */
    public function getApiErrorMessage(): ?string
    {
        return $this->response['error']['message'] ?? null;
    }

    /**
     * Check if the error is due to an invalid token
     *
     * @return bool
     */
    public function isAuthError(): bool
    {
        return $this->httpCode === 401 || $this->getApiErrorCode() === 'INVALID_TOKEN';
    }

    /**
     * Check if the error is due to rate limiting
     *
     * @return bool
     */
    public function isRateLimited(): bool
    {
        return $this->httpCode === 429 || $this->getApiErrorCode() === 'RATE_LIMITED';
    }

    /**
     * Check if the error is a permission error
     *
     * @return bool
     */
    public function isPermissionError(): bool
    {
        return $this->httpCode === 403 || $this->getApiErrorCode() === 'PERMISSION_DENIED';
    }

    /**
     * Check if the resource was not found
     *
     * @return bool
     */
    public function isNotFound(): bool
    {
        return $this->httpCode === 404 || $this->getApiErrorCode() === 'NOT_FOUND';
    }

    /**
     * Check if this is a conflict error (e.g., muster already submitted)
     *
     * @return bool
     */
    public function isConflict(): bool
    {
        return $this->httpCode === 409 || $this->getApiErrorCode() === 'MUSTER_SUBMITTED';
    }

    /**
     * Create exception from a curl error
     *
     * @param string $error Curl error message
     * @param int $errno Curl error number
     * @return self
     */
    public static function fromCurlError(string $error, int $errno): self
    {
        return new self(
            "DLB API connection failed: {$error}",
            0,
            ['error' => ['code' => 'CONNECTION_ERROR', 'message' => $error, 'errno' => $errno]]
        );
    }

    /**
     * Create exception from an HTTP response
     *
     * @param int $httpCode HTTP status code
     * @param array|null $response Parsed response body
     * @return self
     */
    public static function fromResponse(int $httpCode, ?array $response): self
    {
        $message = $response['error']['message'] ?? 'Unknown API error';
        $code = $response['error']['code'] ?? 'UNKNOWN_ERROR';

        return new self(
            "DLB API error ({$code}): {$message}",
            $httpCode,
            $response
        );
    }

    /**
     * Get a human-readable summary of the error
     *
     * @return string
     */
    public function getSummary(): string
    {
        $parts = ["HTTP {$this->httpCode}"];

        if ($code = $this->getApiErrorCode()) {
            $parts[] = $code;
        }

        $parts[] = $this->getMessage();

        return implode(' - ', $parts);
    }
}
