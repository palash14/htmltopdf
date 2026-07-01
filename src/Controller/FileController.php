<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\StorageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles GET /api/files/{filename} requests.
 *
 * Pipeline:
 *  1. Validate filename format via regex (no filesystem access on failure → 400)
 *  2. Look up the file in storage (null → 404)
 *  3. Check expiry (expired → 410)
 *  4. Stream the PDF bytes with correct headers (200)
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6
 */
class FileController
{
    /** Pattern that valid PDF filenames must match (hex string ≥32 chars + .pdf). */
    private const FILENAME_PATTERN = '/^[0-9a-f]{32,}\.pdf$/';

    public function __construct(
        private readonly StorageService $storageService,
    ) {}

    /**
     * Stream a stored PDF file to the client.
     *
     * @param ServerRequestInterface $request  PSR-7 server request
     * @param ResponseInterface      $response PSR-7 response
     * @param array<string, mixed>   $args     Route arguments; expects 'filename' key
     * @return ResponseInterface               200 with PDF bytes, or JSON error (400/404/410)
     */
    public function download(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args = [],
    ): ResponseInterface {
        // ------------------------------------------------------------------
        // Step 1: Extract and validate filename format
        // No filesystem access until the format is confirmed valid.
        // ------------------------------------------------------------------
        $filename = isset($args['filename']) ? (string) $args['filename'] : '';

        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return $this->jsonResponse($response, 400, 'Invalid filename format');
        }

        // ------------------------------------------------------------------
        // Step 2: Look up the file in storage
        // ------------------------------------------------------------------
        $stored = $this->storageService->find($filename);

        if ($stored === null) {
            return $this->jsonResponse($response, 404, 'File not found');
        }

        // ------------------------------------------------------------------
        // Step 3: Check expiry
        // ------------------------------------------------------------------
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($stored->expiresAt < $now) {
            return $this->jsonResponse($response, 410, 'File has expired');
        }

        // ------------------------------------------------------------------
        // Step 4: Stream PDF bytes with correct headers
        // ------------------------------------------------------------------
        $filesize = (int) filesize($stored->path);

        $response->getBody()->write((string) file_get_contents($stored->path));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) $filesize);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a JSON error response with the given status code and message.
     *
     * @param ResponseInterface $response Base response to build from
     * @param int               $status   HTTP status code (4xx/5xx)
     * @param string            $message  Human-readable error description
     * @return ResponseInterface
     */
    private function jsonResponse(
        ResponseInterface $response,
        int $status,
        string $message,
    ): ResponseInterface {
        $payload = json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);

        $response->getBody()->write((string) $payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
