<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;

/**
 * Validates incoming convert request data.
 *
 * This is a pure stateless service with no constructor dependencies.
 */
class InputValidator
{
    /**
     * Validates the request body and returns the sanitised URL string.
     *
     * Validation order:
     *  1. `url` key must be present and a non-empty string  → 400
     *  2. URL length must not exceed 2048 characters         → 422
     *  3. URL must pass filter_var(FILTER_VALIDATE_URL)      → 422
     *  4. Scheme must be 'http' or 'https'                   → 422
     *
     * @param array<string, mixed> $body Decoded JSON request body
     * @return string The validated URL
     * @throws ValidationException
     */
    public function validateConvertRequest(array $body): string
    {
        // 1. Required field check
        if (!isset($body['url']) || !is_string($body['url']) || $body['url'] === '') {
            throw new ValidationException(400, 'Missing required field: url');
        }

        $url = $body['url'];

        // 2. Length check
        if (strlen($url) > 2048) {
            throw new ValidationException(422, 'URL must not exceed 2048 characters');
        }

        // 3. Well-formed URL check
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException(422, 'Invalid URL format');
        }

        // 4. Scheme check
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ValidationException(422, 'URL scheme must be http or https');
        }

        return $url;
    }
}
