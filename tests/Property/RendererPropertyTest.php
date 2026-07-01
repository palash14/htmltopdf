<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Exception\RendererException;
use App\Exception\RendererTimeoutException;
use App\Model\Config;
use App\Service\RendererService;
use Eris\Generators;
use Eris\TestTrait;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status

/**
 * Property-based tests for RendererService error-status mapping.
 *
 * Feature: url-to-pdf-api
 *
 * Covers:
 *   Property 3 — Renderer exit code determines error status
 *
 * Validates: Requirements 2.4, 2.5
 */

// ---------------------------------------------------------------------------
// Test-only subclass: overrides isExecutable() and buildCommand() so the test
// suite works without a real wkhtmltopdf binary installed.
// ---------------------------------------------------------------------------

/**
 * Subclass of RendererService used exclusively in property tests.
 *
 * - Overrides isExecutable() → always returns true (no real binary needed).
 * - Overrides buildCommand() → returns a PHP one-liner that exits with the
 *   configured exit code, optionally writing to stderr first.
 */
class PropertyTestRendererService extends RendererService
{
    public function __construct(
        Config          $config,
        LoggerInterface $logger,
        private int     $exitCode,
        private string  $stderrMessage = '',
    ) {
        parent::__construct($config, $logger);
    }

    protected function isExecutable(string $path): bool
    {
        return true;
    }

    protected function buildCommand(string $url, string $outputPath): array
    {
        if ($this->stderrMessage !== '') {
            // Write to stderr then exit with the configured code.
            $escaped = addslashes($this->stderrMessage);
            return [
                PHP_BINARY,
                '-r',
                "fwrite(STDERR, \"{$escaped}\"); exit({$this->exitCode});",
            ];
        }

        return [PHP_BINARY, '-r', "exit({$this->exitCode});"];
    }
}

/**
 * Subclass for the timeout scenario: runs a script that sleeps for 10 seconds,
 * which will always exceed the 1-second timeout configured in the test.
 */
class TimeoutTestRendererService extends RendererService
{
    public function __construct(Config $config, LoggerInterface $logger)
    {
        parent::__construct($config, $logger);
    }

    protected function isExecutable(string $path): bool
    {
        return true;
    }

    protected function buildCommand(string $url, string $outputPath): array
    {
        // Sleep for 10 seconds — the 1-second timeout will fire first.
        return [PHP_BINARY, '-r', 'sleep(10);'];
    }
}

// ---------------------------------------------------------------------------

class RendererPropertyTest extends TestCase
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
    // Setup / teardown
    // -----------------------------------------------------------------------

    /** @var string[] Temp directories created during a test; cleaned up in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a fresh temporary directory and register it for cleanup.
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/renderer_prop_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        if ($items !== false) {
            foreach ($items as $item) {
                is_dir($item) ? $this->removeDir($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build a Config with a 30-second timeout and minimal required defaults.
     */
    private function makeConfig(string $storageDir, int $timeoutSeconds = 30): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/fake/path',
            apiKeys: ['k'],
            storageDir: $storageDir,
            baseUrl: 'https://example.com',
            renderTimeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * Build a silent logger (all records discarded).
     */
    private function makeLogger(): LoggerInterface
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        return $logger;
    }

    // -----------------------------------------------------------------------
    // Property 3a: Any non-zero exit code maps to HTTP 502
    //
    // For any non-zero exit code (1–255) returned by the wkhtmltopdf process,
    // the API SHALL return HTTP 502.
    //
    // // Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status
    //
    // Validates: Requirement 2.4
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 3a: Non-zero exit code always throws RendererException with status 502
     *
     * // Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status
     *
     * Validates: Requirements 2.4
     */
    public function testNonZeroExitCodeThrowsRendererException502(): void
    {
        $this->forAll(
            Generators::choose(1, 255)  // any non-zero exit code
        )
            ->withMaxSize(100)
            ->then(function (int $exitCode): void {
                $tmpDir  = $this->makeTempDir();
                $config  = $this->makeConfig($tmpDir, 30);
                $logger  = $this->makeLogger();

                $service    = new PropertyTestRendererService($config, $logger, $exitCode);
                $outputPath = $tmpDir . '/output_' . bin2hex(random_bytes(4)) . '.pdf';

                $thrown = null;
                try {
                    $service->render('https://example.com', $outputPath);
                } catch (RendererException $e) {
                    // Catch RendererException but NOT RendererTimeoutException
                    // (which is a separate subclass); we want the non-zero exit path.
                    if (!($e instanceof RendererTimeoutException)) {
                        $thrown = $e;
                    } else {
                        throw $e; // unexpected timeout — re-throw to fail the test
                    }
                }

                $this->assertNotNull(
                    $thrown,
                    "RendererException must be thrown for exit code {$exitCode}"
                );
                $this->assertSame(
                    502,
                    $thrown->getStatusCode(),
                    "Non-zero exit code {$exitCode} must produce status 502, " .
                    "got {$thrown->getStatusCode()}"
                );
            });
    }

    /**
     * @test
     * Property 3a (with stderr): Non-zero exit code with stderr output still maps to 502
     *
     * Confirms the status is 502 regardless of whether the process writes to
     * stderr, which exercises the error-logging branch in RendererService.
     *
     * // Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status
     *
     * Validates: Requirements 2.4
     */
    public function testNonZeroExitCodeWithStderrThrowsRendererException502(): void
    {
        $this->forAll(
            Generators::choose(1, 255),         // any non-zero exit code
            Generators::choose(1, 100)          // stderr message length
        )
            ->withMaxSize(100)
            ->then(function (int $exitCode, int $msgLen): void {
                $tmpDir  = $this->makeTempDir();
                $config  = $this->makeConfig($tmpDir, 30);
                $logger  = $this->makeLogger();

                // Build a simple stderr message of the requested length
                $stderrMsg = str_repeat('e', $msgLen);

                $service    = new PropertyTestRendererService($config, $logger, $exitCode, $stderrMsg);
                $outputPath = $tmpDir . '/output_' . bin2hex(random_bytes(4)) . '.pdf';

                $thrown = null;
                try {
                    $service->render('https://example.com', $outputPath);
                } catch (RendererException $e) {
                    if (!($e instanceof RendererTimeoutException)) {
                        $thrown = $e;
                    } else {
                        throw $e;
                    }
                }

                $this->assertNotNull(
                    $thrown,
                    "RendererException must be thrown for exit code {$exitCode} with stderr"
                );
                $this->assertSame(
                    502,
                    $thrown->getStatusCode(),
                    "Non-zero exit code {$exitCode} with stderr must produce status 502, " .
                    "got {$thrown->getStatusCode()}"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 3b: Timeout maps to HTTP 504 regardless of exit code
    //
    // When 30 seconds (or the configured timeout) elapse from the moment
    // wkhtmltopdf is invoked, the Renderer SHALL terminate the process and
    // the API SHALL return HTTP 504; this takes precedence over any 502
    // condition that would otherwise apply.
    //
    // // Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status
    //
    // Validates: Requirement 2.5
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 3b: A slow process always throws RendererTimeoutException with status 504
     *
     * Uses a 1-second timeout and a PHP script that sleeps for 10 seconds to
     * reliably trigger the timeout path. Eris iterations are capped at 5 to
     * keep the total wall-clock time under 10 seconds (each iteration spawns
     * a real subprocess and waits for the timeout to fire).
     *
     * // Feature: url-to-pdf-api, Property 3: Renderer exit code determines error status
     *
     * Validates: Requirements 2.5
     */
    public function testTimeoutThrowsRendererTimeoutException504(): void
    {
        // limitTo(5) sets $this->iterations = 5 before forAll() is called,
        // capping wall-clock time to ~5 seconds while still verifying the
        // property across multiple independent subprocess invocations.
        $this->limitTo(5);

        $this->forAll(
            Generators::constant(1)  // timeout in seconds (always 1 s)
        )
            ->withMaxSize(100)
            ->then(function (int $timeoutSeconds): void {
                $tmpDir  = $this->makeTempDir();
                $config  = $this->makeConfig($tmpDir, $timeoutSeconds);
                $logger  = $this->makeLogger();

                $service    = new TimeoutTestRendererService($config, $logger);
                $outputPath = $tmpDir . '/output_' . bin2hex(random_bytes(4)) . '.pdf';

                $thrown = null;
                try {
                    $service->render('https://example.com', $outputPath);
                } catch (RendererTimeoutException $e) {
                    $thrown = $e;
                } catch (RendererException $e) {
                    // A non-timeout RendererException here would be a test failure —
                    // the timeout should always fire before the process exits.
                    $this->fail(
                        "Expected RendererTimeoutException(504) but got RendererException(502): " .
                        $e->getMessage()
                    );
                }

                $this->assertNotNull(
                    $thrown,
                    "RendererTimeoutException must be thrown when the process exceeds the timeout"
                );
                $this->assertSame(
                    504,
                    $thrown->getStatusCode(),
                    "Timeout must produce status 504, got {$thrown->getStatusCode()}"
                );
            });
    }
}
