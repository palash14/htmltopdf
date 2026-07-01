<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\StorageFullException;
use App\Model\Config;
use App\Service\ConcurrencyGuard;
use App\Service\InputValidator;
use App\Service\RendererService;
use App\Service\SsrfGuard;
use App\Service\StorageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles POST /api/convert requests.
 *
 * Pipeline:
 *  1. Parse JSON body
 *  2. Validate URL via InputValidator
 *  3. SSRF check via SsrfGuard
 *  4. Storage-full check (507 when at/over limit)
 *  5. Acquire concurrency slot (503 when full / timed out)
 *  6. Render URL to a temporary PDF file via RendererService
 *  7. Move the PDF to permanent storage via StorageService
 *  8. Release concurrency slot (always, in finally)
 *  9. Return 200 JSON with download_url and expires_at
 *
 * Requirements: 1.1, 1.2, 2.1, 2.2, 4.1, 4.2, 4.3, 8.5, 8.6
 */
class ConvertController
{
    public function __construct(
        private readonly InputValidator   $inputValidator,
        private readonly SsrfGuard        $ssrfGuard,
        private readonly StorageService   $storageService,
        private readonly RendererService  $rendererService,
        private readonly ConcurrencyGuard $concurrencyGuard,
        private readonly Config           $config,
    ) {}

    /**
     * Handle a convert request.
     *
     * @param ServerRequestInterface $request  PSR-7 server request
     * @param ResponseInterface      $response PSR-7 response (Slim passes a fresh one)
     * @param array<string, mixed>   $args     Route arguments (unused; required by Slim 4 signature)
     * @return ResponseInterface               200 JSON on success; exceptions propagate to error handler
     */
    public function handle(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args = [],  // @phpstan-ignore-line
    ): ResponseInterface {
        // ------------------------------------------------------------------
        // Step 1: Parse JSON body
        // ------------------------------------------------------------------
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            // Slim may not auto-decode if Content-Type is missing; fall back to
            // raw body parsing.
            $raw  = (string) $request->getBody();
            $body = (array) json_decode($raw, true);
        }

        // ------------------------------------------------------------------
        // Step 2: Validate URL (throws ValidationException on failure)
        // ------------------------------------------------------------------
        $url = $this->inputValidator->validateConvertRequest($body);

        // ------------------------------------------------------------------
        // Step 3: SSRF guard (throws SsrfException / ValidationException)
        // ------------------------------------------------------------------
        $this->ssrfGuard->check($url);

        // ------------------------------------------------------------------
        // Step 4: Storage-full check (throws StorageFullException → 507)
        // ------------------------------------------------------------------
        if (
            $this->config->maxStorageMb !== null
            && $this->storageService->storageSizeBytes() >= $this->config->maxStorageMb * 1024 * 1024
        ) {
            throw new StorageFullException('Storage capacity exceeded');
        }

        // ------------------------------------------------------------------
        // Step 5: Acquire a concurrency slot (throws ConcurrencyException → 503)
        // ------------------------------------------------------------------
        $this->concurrencyGuard->acquire();

        // ------------------------------------------------------------------
        // Steps 6–8: Render → Store → Release (release is always in finally)
        // ------------------------------------------------------------------
        try {
            // Generate a unique temporary output path for the rendered PDF.
            $tempPath = sys_get_temp_dir() . '/' . uniqid('pdf_', true) . '.pdf';

            // Step 6: Render (throws RendererTimeoutException → 504 or RendererException → 502)
            $this->rendererService->render($url, $tempPath);

            // Step 7: Save to permanent storage
            $stored = $this->storageService->save($tempPath, $url);
        } finally {
            // Step 8: Always release the concurrency slot
            $this->concurrencyGuard->release();
        }

        // ------------------------------------------------------------------
        // Step 9: Build and return 200 JSON response
        // ------------------------------------------------------------------
        $payload = json_encode([
            'download_url' => $stored->downloadUrl,
            'expires_at'   => $stored->expiresAt->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response->getBody()->write((string) $payload);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }
}
