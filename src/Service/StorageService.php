<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Config;
use App\Model\StoredFile;

/**
 * Manages PDF file storage: saving, retrieving, and measuring storage usage.
 *
 * Each PDF is accompanied by a companion `.json` sidecar file that records
 * creation timestamp, expiry timestamp, and the (redacted) source URL.
 */
class StorageService
{
    /** Regex that valid filenames must match. */
    private const FILENAME_PATTERN = '/^[0-9a-f]{32,}\.pdf$/';

    /** ISO 8601 UTC format used for all sidecar timestamps. */
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly Config $config,
    ) {
        if (!is_writable($this->config->storageDir)) {
            throw new \RuntimeException(
                sprintf(
                    'Storage directory "%s" is not writable or does not exist.',
                    $this->config->storageDir
                )
            );
        }
    }

    /**
     * Generate a cryptographically random PDF filename.
     *
     * Uses random_bytes(20) = 160 bits of entropy (> 128-bit minimum),
     * encoded as 40 lowercase hexadecimal characters.
     *
     * @return string e.g. "a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9.pdf"
     */
    public function generateFilename(): string
    {
        return bin2hex(random_bytes(20)) . '.pdf';
    }

    /**
     * Move a rendered PDF into storage and write its sidecar metadata.
     *
     * Steps:
     *  1. Generate a unique filename.
     *  2. Move $sourcePath to the storage directory.
     *  3. Strip any `api_key` query parameter from $url before storing.
     *  4. Write a JSON sidecar with created_at, expires_at, and source_url.
     *  5. Return a populated StoredFile value object.
     *
     * @param string $sourcePath Absolute path of the temporary PDF to move.
     * @param string $url        The source URL that was converted (used for logging).
     * @return StoredFile
     * @throws \RuntimeException If the file cannot be moved or the sidecar cannot be written.
     */
    public function save(string $sourcePath, string $url): StoredFile
    {
        $filename  = $this->generateFilename();
        $destPath  = $this->config->storageDir . '/' . $filename;
        $sidecarPath = $this->buildSidecarPath($destPath);

        if (!rename($sourcePath, $destPath)) {
            throw new \RuntimeException(
                sprintf('Failed to move PDF from "%s" to "%s".', $sourcePath, $destPath)
            );
        }

        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $this->config->ttlSeconds . ' seconds');

        $sidecarData = [
            'created_at' => $now->format(self::DATETIME_FORMAT),
            'expires_at' => $expiresAt->format(self::DATETIME_FORMAT),
            'source_url' => $this->redactApiKey($url),
        ];

        $json = json_encode($sidecarData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($sidecarPath, $json) === false) {
            throw new \RuntimeException(
                sprintf('Failed to write sidecar file "%s".', $sidecarPath)
            );
        }

        return new StoredFile(
            filename:    $filename,
            path:        $destPath,
            downloadUrl: $this->config->baseUrl . '/api/files/' . $filename,
            createdAt:   $now,
            expiresAt:   $expiresAt,
        );
    }

    /**
     * Look up a stored PDF by its filename.
     *
     * Validates the filename format first (prevents filesystem traversal and
     * allows early rejection without hitting the disk for invalid inputs).
     *
     * @param string $filename The PDF filename (e.g. "a3f9...b2.pdf").
     * @return StoredFile|null  Returns null if the filename is invalid, the sidecar
     *                          is missing, or the JSON cannot be parsed.
     */
    public function find(string $filename): ?StoredFile
    {
        // Reject filenames that don't match the expected format.
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }

        $path        = $this->config->storageDir . '/' . $filename;
        $sidecarPath = $this->buildSidecarPath($path);

        if (!file_exists($sidecarPath)) {
            return null;
        }

        $raw = file_get_contents($sidecarPath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat(
            self::DATETIME_FORMAT,
            $data['created_at'] ?? '',
            new \DateTimeZone('UTC')
        );
        $expiresAt = \DateTimeImmutable::createFromFormat(
            self::DATETIME_FORMAT,
            $data['expires_at'] ?? '',
            new \DateTimeZone('UTC')
        );

        if ($createdAt === false || $expiresAt === false) {
            return null;
        }

        return new StoredFile(
            filename:    $filename,
            path:        $path,
            downloadUrl: $this->config->baseUrl . '/api/files/' . $filename,
            createdAt:   $createdAt,
            expiresAt:   $expiresAt,
        );
    }

    /**
     * Return the total size in bytes of all files currently in the storage directory.
     *
     * Counts every file (both .pdf and .json sidecar files).
     *
     * @return int Total bytes occupied in the storage directory.
     */
    public function storageSizeBytes(): int
    {
        $total = 0;
        $files = glob($this->config->storageDir . '/*');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                if ($size !== false) {
                    $total += $size;
                }
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the sidecar path from a PDF path by replacing the .pdf extension.
     */
    private function buildSidecarPath(string $pdfPath): string
    {
        return substr($pdfPath, 0, -4) . '.json';
    }

    /**
     * Remove the `api_key` query parameter from a URL before it is stored
     * or logged, to avoid persisting secrets on disk.
     *
     * @param string $url The original URL.
     * @return string     The URL with api_key stripped from the query string.
     */
    private function redactApiKey(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        if (empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);
        unset($params['api_key']);

        $newQuery = http_build_query($params);

        // Reconstruct the URL
        $result = '';

        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $result .= $parts['user'];
            if (isset($parts['pass'])) {
                $result .= ':' . $parts['pass'];
            }
            $result .= '@';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $result .= $parts['path'];
        }
        if ($newQuery !== '') {
            $result .= '?' . $newQuery;
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }
}
