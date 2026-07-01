<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Immutable value object representing a PDF file stored on disk.
 *
 * Created by StorageService::save() and returned by StorageService::find().
 */
final class StoredFile
{
    /**
     * @param string             $filename    Filename on disk (e.g. "a3f9...b2.pdf")
     * @param string             $path        Absolute filesystem path to the PDF
     * @param string             $downloadUrl Fully-qualified download URL
     * @param \DateTimeImmutable $createdAt   UTC timestamp when the file was stored
     * @param \DateTimeImmutable $expiresAt   UTC timestamp when the file is eligible for deletion
     */
    public function __construct(
        public readonly string             $filename,
        public readonly string             $path,
        public readonly string             $downloadUrl,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $expiresAt,
    ) {}
}
