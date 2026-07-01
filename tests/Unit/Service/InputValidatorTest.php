<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Exception\ValidationException;
use App\Service\InputValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InputValidator::validateConvertRequest().
 *
 * Requirements: 1.3, 1.4, 5.1, 5.3, 5.4
 */
class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputValidator();
    }

    // -----------------------------------------------------------------------
    // 400 – missing / non-string / empty url field
    // -----------------------------------------------------------------------

    public function testMissingUrlKeyThrows400(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest([]);
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testNullUrlThrows400(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => null]);
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testEmptyStringUrlThrows400(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => '']);
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testIntegerUrlThrows400(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 123]);
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Length boundary tests — 2047 and 2048 chars are valid; 2049 throws 422
    // -----------------------------------------------------------------------

    public function testUrl2047CharsIsValid(): void
    {
        // Build a valid base URL and pad to exactly 2047 characters.
        $base = 'https://example.com/';
        $url  = $base . str_repeat('a', 2047 - strlen($base));
        self::assertSame(2047, strlen($url));

        $result = $this->validator->validateConvertRequest(['url' => $url]);
        self::assertSame($url, $result);
    }

    public function testUrl2048CharsIsValid(): void
    {
        // Build a valid base URL and pad to exactly 2048 characters.
        $base = 'https://example.com/';
        $url  = $base . str_repeat('a', 2048 - strlen($base));
        self::assertSame(2048, strlen($url));

        $result = $this->validator->validateConvertRequest(['url' => $url]);
        self::assertSame($url, $result);
    }

    public function testUrl2049CharsThrows422(): void
    {
        $this->expectException(ValidationException::class);

        $base = 'https://example.com/';
        $url  = $base . str_repeat('a', 2049 - strlen($base));
        self::assertSame(2049, strlen($url));

        try {
            $this->validator->validateConvertRequest(['url' => $url]);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Invalid scheme tests — all should throw 422
    // -----------------------------------------------------------------------

    public function testJavascriptSchemeThrows422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 'javascript:alert(1)']);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function testFtpSchemeThrows422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 'ftp://example.com']);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function testFileSchemeThrows422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 'file:///etc/passwd']);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Malformed URL tests
    // -----------------------------------------------------------------------

    public function testNotAUrlThrows422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 'not-a-url-at-all']);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function testUrlWithNoSchemeThrows422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->validator->validateConvertRequest(['url' => 'example.com/path']);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Valid URL tests — should return the URL string
    // -----------------------------------------------------------------------

    public function testValidHttpUrlReturnsUrl(): void
    {
        $url    = 'http://example.com';
        $result = $this->validator->validateConvertRequest(['url' => $url]);
        self::assertSame($url, $result);
    }

    public function testValidHttpsUrlWithPathAndQueryReturnsUrl(): void
    {
        $url    = 'https://example.com/path?q=1&x=2';
        $result = $this->validator->validateConvertRequest(['url' => $url]);
        self::assertSame($url, $result);
    }
}
