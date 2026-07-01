<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Exception\ValidationException;
use App\Service\InputValidator;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for InputValidator.
 *
 * Feature: url-to-pdf-api
 *
 * Validates: Requirements 1.4, 5.1, 5.3, 5.4
 */
class InputValidatorPropertyTest extends TestCase
{
    use TestTrait;

    // -----------------------------------------------------------------------
    // Eris / PHPUnit 10 compatibility shim
    // -----------------------------------------------------------------------

    /**
     * PHPUnit 10 removed getAnnotations() / PHPUnit\Util\Test::parseTestMethodAnnotations().
     * Override Eris's getTestCaseAnnotations() to return an empty structure so
     * Eris uses its defaults (100 iterations, rand method, 50% ratio).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function getTestCaseAnnotations(): array
    {
        return ['method' => [], 'class' => []];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Generate a random ASCII-printable string of exactly $length characters.
     */
    private static function randomString(int $length): string
    {
        $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }

    /**
     * Return a shared InputValidator instance (no constructor deps).
     */
    private function makeValidator(): InputValidator
    {
        return new InputValidator();
    }

    // -----------------------------------------------------------------------
    // Property 1: Invalid URL inputs always return 422
    //
    // For any string submitted as the `url` field that is either not a
    // well-formed URL, exceeds 2048 characters, uses a non-http/https scheme,
    // or contains only whitespace — the API SHALL return HTTP 422.
    //
    // // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
    //
    // Validates: Requirements 1.4, 5.1, 5.3, 5.4
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 1a: Overlength URLs (> 2048 chars) always throw ValidationException(422)
     *
     * // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
     */
    public function testOverlengthUrlsThrowValidationException422(): void
    {
        $this->forAll(
            // Extra chars beyond the 2048 limit: 1–512 extra
            Generators::choose(1, 512)
        )
            ->withMaxSize(100)
            ->then(function (int $extra): void {
                $totalLength = 2048 + $extra;

                // Build a syntactically valid-looking http URL that exceeds 2048 chars.
                // "http://x/" is 9 chars; pad the path to reach exactly $totalLength.
                $prefix  = 'http://x/';
                $padLen  = $totalLength - strlen($prefix);
                $longUrl = $prefix . self::randomString($padLen);

                $this->assertGreaterThan(2048, strlen($longUrl),
                    'Generated URL must exceed 2048 chars');

                $thrown = null;
                try {
                    $this->makeValidator()->validateConvertRequest(['url' => $longUrl]);
                } catch (ValidationException $e) {
                    $thrown = $e;
                }

                $this->assertNotNull($thrown,
                    "ValidationException must be thrown for URL of length " . strlen($longUrl));
                $this->assertSame(422, $thrown->getStatusCode(),
                    "Overlength URL must produce status 422, got {$thrown->getStatusCode()}");
            });
    }

    /**
     * @test
     * Property 1b: Non-http/https scheme URLs always throw ValidationException(422)
     *
     * // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
     */
    public function testNonHttpSchemeUrlsThrowValidationException422(): void
    {
        // Representative set of forbidden schemes
        $forbiddenSchemes = [
            'ftp',
            'ftps',
            'file',
            'javascript',
            'data',
            'smtp',
            'ssh',
            'telnet',
            'ldap',
            'ws',
            'wss',
        ];

        $this->forAll(
            Generators::elements($forbiddenSchemes),
            // Random host/path suffix so each iteration varies
            Generators::choose(3, 20)
        )
            ->withMaxSize(100)
            ->then(function (string $scheme, int $hostLen): void {
                // Build a URL with the forbidden scheme that would otherwise
                // look syntactically valid (so scheme rejection is the reason
                // the 422 is thrown, not a parse failure).
                $host = self::randomString($hostLen) . '.example.com';
                $url  = "{$scheme}://{$host}/path";

                $thrown = null;
                try {
                    $this->makeValidator()->validateConvertRequest(['url' => $url]);
                } catch (ValidationException $e) {
                    $thrown = $e;
                }

                $this->assertNotNull($thrown,
                    "ValidationException must be thrown for scheme '{$scheme}'");
                $this->assertSame(422, $thrown->getStatusCode(),
                    "Non-http/https scheme '{$scheme}' must produce status 422, " .
                    "got {$thrown->getStatusCode()}");
            });
    }

    /**
     * @test
     * Property 1c: Malformed / unparseable strings always throw ValidationException (400 or 422)
     *
     * Random non-URL strings (plain numbers, random mixed chars, etc.) must
     * cause a ValidationException.  Missing/non-string `url` values → 400;
     * present-but-invalid strings → 422.
     *
     * // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
     */
    public function testMalformedStringsThrowValidationException(): void
    {
        // A representative pool of clearly-invalid URL strings
        $malformedValues = [
            'not-a-url',
            'just some words',
            '12345',
            '!!!@@###',
            'http',
            'http://',
            '//missing-scheme',
            'localhost',
            '192.168.1.1',
            'example.com',
            ':not/valid',
            'http:no-slashes.example.com',
        ];

        $this->forAll(
            Generators::elements($malformedValues)
        )
            ->withMaxSize(100)
            ->then(function (string $badValue): void {
                $thrown = null;
                try {
                    $this->makeValidator()->validateConvertRequest(['url' => $badValue]);
                } catch (ValidationException $e) {
                    $thrown = $e;
                }

                $this->assertNotNull($thrown,
                    "ValidationException must be thrown for malformed value: '{$badValue}'");

                // Present-but-invalid strings must be 422; missing/wrong-type are 400.
                // All values here are non-empty strings → must be 422.
                $this->assertSame(422, $thrown->getStatusCode(),
                    "Present-but-invalid URL '{$badValue}' must produce status 422, " .
                    "got {$thrown->getStatusCode()}");
            });
    }

    /**
     * @test
     * Property 1d: Whitespace-only URLs always throw ValidationException
     *
     * Strings consisting entirely of space, tab, or newline characters must be
     * rejected.  The validator treats them as empty/non-string → 400, or as
     * an invalid URL string → 422; either way a ValidationException is thrown.
     *
     * // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
     */
    public function testWhitespaceOnlyUrlsThrowValidationException(): void
    {
        // Whitespace character pool: space, tab, newline, carriage-return
        $whitespaceChars = [' ', "\t", "\n", "\r"];

        $this->forAll(
            // Length of the whitespace string: 1–50 chars
            Generators::choose(1, 50),
            // Which whitespace char to repeat (index into pool)
            Generators::choose(0, count($whitespaceChars) - 1)
        )
            ->withMaxSize(100)
            ->then(function (int $len, int $charIndex) use ($whitespaceChars): void {
                $wsChar      = $whitespaceChars[$charIndex];
                $whitespaceUrl = str_repeat($wsChar, $len);

                $thrown = null;
                try {
                    $this->makeValidator()->validateConvertRequest(['url' => $whitespaceUrl]);
                } catch (ValidationException $e) {
                    $thrown = $e;
                }

                $this->assertNotNull($thrown,
                    "ValidationException must be thrown for whitespace-only URL (char=" .
                    json_encode($wsChar) . ", len={$len})");
                $this->assertContains(
                    $thrown->getStatusCode(),
                    [400, 422],
                    "Whitespace-only URL must produce status 400 or 422"
                );
            });
    }

    /**
     * @test
     * Property 1e: Valid http/https URLs are accepted and returned as-is
     *
     * For any well-formed http or https URL within the 2048-char limit, the
     * validator must NOT throw and must return the URL string unchanged.
     *
     * // Feature: url-to-pdf-api, Property 1: Invalid URL inputs always return 422
     */
    public function testValidHttpHttpsUrlsAreAccepted(): void
    {
        // Representative valid URLs — each must pass without exception
        $validUrls = [
            'https://example.com',
            'http://example.com',
            'https://example.com/path',
            'http://example.com/path',
            'https://example.com/path?q=1',
            'http://example.com/path?q=1&b=2',
            'https://sub.example.com/deep/path',
            'http://test.org/page#anchor',
            'https://xn--nxasmq6b.com',           // IDN / punycode host
            'http://192.0.2.1/resource',           // non-private public IP
            'https://example.com:8443/secure',
            'http://example.com:8080/',
        ];

        $this->forAll(
            Generators::elements($validUrls)
        )
            ->withMaxSize(100)
            ->then(function (string $url): void {
                $this->assertLessThanOrEqual(2048, strlen($url),
                    'Test URL must not exceed 2048 chars');

                $thrown  = null;
                $result  = null;
                try {
                    $result = $this->makeValidator()->validateConvertRequest(['url' => $url]);
                } catch (ValidationException $e) {
                    $thrown = $e;
                }

                $this->assertNull($thrown,
                    "Valid URL '{$url}' must NOT throw ValidationException " .
                    "(threw with status " . ($thrown?->getStatusCode() ?? 'n/a') . ")");
                $this->assertIsString($result,
                    "validateConvertRequest must return a string for valid URL '{$url}'");
                $this->assertSame($url, $result,
                    "Returned URL must equal the input for '{$url}'");
            });
    }
}
