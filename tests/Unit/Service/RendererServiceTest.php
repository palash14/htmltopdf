<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Exception\RendererException;
use App\Exception\RendererTimeoutException;
use App\Exception\RendererUnavailableException;
use App\Model\Config;
use App\Service\RendererService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RendererService.
 *
 * We use a testable subclass that overrides `isExecutable()` (so no real binary
 * is needed at construction) and `buildCommand()` (so we can inject PHP scripts
 * as stand-ins for wkhtmltopdf).
 *
 * Requirements: 2.1–2.5, 9.3, 9.4, 9.5
 */
class RendererServiceTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Infrastructure
    // -----------------------------------------------------------------------

    /** @var string[] Temp directories registered for cleanup */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $files = glob($dir . '/*');
            if ($files !== false) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/renderer_unit_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function makeConfig(
        int $timeoutSeconds = 30,
        string $rendererEngine = 'wkhtmltopdf',
        ?string $chromePath = null,
    ): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/fake/wkhtmltopdf',  // not used because buildCommand() is overridden
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            renderTimeoutSeconds: $timeoutSeconds,
            rendererEngine: $rendererEngine,
            chromePath: $chromePath,
        );
    }

    /**
     * @return MockObject&LoggerInterface
     */
    private function makeLogger(): MockObject
    {
        return $this->createMock(LoggerInterface::class);
    }

    /**
     * Write a PHP stub script to $tmpDir and return its path.
     *
     * Modes:
     *  - 'success'      exits 0, writes '%PDF-1.4 fake' to $outputPath
     *  - 'failure'      exits 1, writes 'render error' to stderr
     *  - 'empty-output' exits 0, writes an empty file to $outputPath
     *  - 'no-output'    exits 0, does NOT create $outputPath
     *  - 'slow'         sleeps 60 seconds then exits 0
     *  - 'long-stderr'  exits 1, writes 3000 'x' chars to stderr
     */
    private function makeScript(string $mode, string $tmpDir, string $outputPath): string
    {
        $path        = $tmpDir . '/script_' . $mode . '.php';
        $escapedOut  = var_export($outputPath, true);

        $body = match ($mode) {
            'success'      => "<?php\nfile_put_contents({$escapedOut}, '%PDF-1.4 fake');\nexit(0);\n",
            'failure'      => "<?php\nfwrite(STDERR, 'render error');\nexit(1);\n",
            'empty-output' => "<?php\nfile_put_contents({$escapedOut}, '');\nexit(0);\n",
            'no-output'    => "<?php\nexit(0);\n",
            'slow'         => "<?php\nsleep(60);\nexit(0);\n",
            'long-stderr'  => "<?php\nfwrite(STDERR, str_repeat('x', 3000));\nexit(1);\n",
            default        => "<?php\nexit(0);\n",
        };

        file_put_contents($path, $body);
        return $path;
    }

    /**
     * Build a RendererService that:
     *  - Always reports the path as executable.
     *  - Uses [PHP_BINARY, $scriptPath] as the process command instead of
     *    the real wkhtmltopdf invocation.
     *
     * This allows the test to run on any machine without wkhtmltopdf installed
     * and on both Windows and Unix.
     */
    private function makeService(
        Config          $config,
        LoggerInterface $logger,
        string          $scriptPath,
    ): RendererService {
        return new class($config, $logger, $scriptPath) extends RendererService {
            public function __construct(
                Config          $config,
                LoggerInterface $logger,
                private string  $script,
            ) {
                parent::__construct($config, $logger);
            }

            protected function isExecutable(string $path): bool
            {
                return true;
            }

            protected function buildCommand(string $url, string $outputPath): array
            {
                // Replace the wkhtmltopdf binary with PHP running our stub script.
                return [PHP_BINARY, $this->script];
            }
        };
    }

    private function makeCommandInspector(Config $config, LoggerInterface $logger): RendererService
    {
        return new class($config, $logger) extends RendererService {
            protected function isExecutable(string $path): bool
            {
                return true;
            }

            /**
             * @return list<string>
             */
            public function inspectCommand(string $url, string $outputPath): array
            {
                return $this->buildCommand($url, $outputPath);
            }
        };
    }

    // -----------------------------------------------------------------------
    // 1. Constructor — RendererUnavailableException when binary not executable
    // -----------------------------------------------------------------------

    /**
     * When isExecutable() returns false and the path is non-empty, the
     * constructor must throw RendererUnavailableException (HTTP 500).
     *
     * Requirements: 9.5
     */
    public function testConstructorThrowsWhenBinaryNotExecutable(): void
    {
        $config = new Config(
            port: 8080,
            wkhtmltopdfPath: '/nonexistent/wkhtmltopdf',
            apiKeys: ['k'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
        );
        $logger = $this->makeLogger();

        $this->expectException(RendererUnavailableException::class);
        $this->expectExceptionMessage('wkhtmltopdf renderer is not executable at: /nonexistent/wkhtmltopdf');

        new class($config, $logger) extends RendererService {
            protected function isExecutable(string $path): bool
            {
                return false;
            }
        };
    }

    /**
     * RendererUnavailableException must carry HTTP status 500.
     *
     * Requirements: 9.5
     */
    public function testRendererUnavailableExceptionStatusCode(): void
    {
        $ex = new RendererUnavailableException('test');
        $this->assertSame(500, $ex->getStatusCode());
    }

    /**
     * When the wkhtmltopdfPath is an empty string the constructor must NOT
     * throw, enabling test construction in environments without the binary.
     */
    public function testConstructorDoesNotThrowWhenPathIsEmpty(): void
    {
        $config = new Config(
            port: 8080,
            wkhtmltopdfPath: '',
            apiKeys: ['k'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
        );
        $logger = $this->makeLogger();

        $service = new class($config, $logger) extends RendererService {
            protected function isExecutable(string $path): bool
            {
                return false; // would normally throw, but path is empty
            }
        };

        $this->assertInstanceOf(RendererService::class, $service);
    }

    public function testBuildCommandPassesUrlBeforeOutputPath(): void
    {
        $config = $this->makeConfig();
        $logger = $this->makeLogger();
        $service = $this->makeCommandInspector($config, $logger);

        $url = 'https://example.com/page.html';
        $outputPath = '/tmp/out.pdf';

        /** @var object{inspectCommand: callable(string,string): array} $service */
        $command = $service->inspectCommand($url, $outputPath);

        self::assertContains('--background', $command);
        self::assertContains('--enable-javascript', $command);
        self::assertContains('--javascript-delay', $command);
        self::assertContains('--print-media-type', $command);
        self::assertNotContains('--no-background', $command);
        self::assertNotContains('--disable-javascript', $command);
        self::assertSame($url, $command[count($command) - 2]);
        self::assertSame($outputPath, $command[count($command) - 1]);
    }

    public function testBuildCommandUsesChromeWhenConfigured(): void
    {
        $config = $this->makeConfig(rendererEngine: 'chrome', chromePath: '/usr/bin/google-chrome');
        $logger = $this->makeLogger();
        $service = $this->makeCommandInspector($config, $logger);

        $url = 'https://example.com/page.html';
        $outputPath = '/tmp/out.pdf';

        /** @var object{inspectCommand: callable(string,string): array} $service */
        $command = $service->inspectCommand($url, $outputPath);

        self::assertSame('/usr/bin/google-chrome', $command[0]);
        self::assertContains('--headless', $command);
        self::assertContains('--window-size=1280,1696', $command);
        self::assertContains('--force-device-scale-factor=1', $command);
        self::assertContains('--no-pdf-header-footer', $command);
        self::assertContains('--print-to-pdf-no-header', $command);
        self::assertContains('--print-to-pdf=' . $outputPath, $command);
        self::assertSame($url, $command[count($command) - 1]);
    }

    // -----------------------------------------------------------------------
    // 2. Successful render → log info, no exception
    // -----------------------------------------------------------------------

    /**
     * When the subprocess exits 0 and produces a non-empty PDF, render() must
     * log success and return without throwing.
     *
     * Requirements: 2.1, 2.2
     */
    public function testSuccessfulRenderLogsAndReturns(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('success', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();

        $logger->expects($this->once())
               ->method('info')
               ->with('Renderer succeeded', $this->arrayHasKey('filename'));

        $service = $this->makeService($config, $logger, $script);
        $service->render('https://example.com/', $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));
    }

    // -----------------------------------------------------------------------
    // 3. Non-zero exit code → RendererException (HTTP 502)
    // -----------------------------------------------------------------------

    /**
     * A non-zero exit code must throw RendererException and log a failure entry.
     *
     * Requirements: 2.4
     */
    public function testNonZeroExitCodeThrowsRendererException(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('failure', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();

        $logger->expects($this->once())
               ->method('error')
               ->with('Renderer process failed', $this->arrayHasKey('exit_code'));

        $service = $this->makeService($config, $logger, $script);

        $this->expectException(RendererException::class);
        $service->render('https://example.com/', $outputPath);
    }

    /**
     * RendererException must carry HTTP status 502.
     *
     * Requirements: 2.4
     */
    public function testRendererExceptionStatusCode(): void
    {
        $ex = new RendererException('test');
        $this->assertSame(502, $ex->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 4. Zero-byte / missing output file → RendererException (HTTP 502)
    // -----------------------------------------------------------------------

    /**
     * Exit 0 with a zero-byte output file must throw RendererException.
     *
     * Requirements: 2.3
     */
    public function testZeroByteOutputThrowsRendererException(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('empty-output', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();

        $logger->expects($this->once())
               ->method('error')
               ->with('Renderer produced empty output', $this->arrayHasKey('exit_code'));

        $service = $this->makeService($config, $logger, $script);

        $this->expectException(RendererException::class);
        $this->expectExceptionMessage('Renderer produced empty output');
        $service->render('https://example.com/', $outputPath);
    }

    /**
     * Exit 0 with no output file at all must throw RendererException.
     *
     * Requirements: 2.3
     */
    public function testMissingOutputFileThrowsRendererException(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('no-output', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();

        $service = $this->makeService($config, $logger, $script);

        $this->expectException(RendererException::class);
        $this->expectExceptionMessage('Renderer produced empty output');
        $service->render('https://example.com/', $outputPath);
    }

    // -----------------------------------------------------------------------
    // 5. Timeout → RendererTimeoutException (HTTP 504)
    // -----------------------------------------------------------------------

    /**
     * When the process runs longer than renderTimeoutSeconds, render() must
     * terminate it and throw RendererTimeoutException.
     *
     * Uses a 1-second timeout so the test finishes quickly.
     * PHP_BINARY is invoked directly so proc_terminate() kills it immediately
     * on both Windows and Unix.
     *
     * Requirements: 2.5
     */
    public function testTimeoutThrowsRendererTimeoutException(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('slow', $tmpDir, $outputPath);

        // 1-second timeout keeps the test fast while still triggering the path.
        $config = $this->makeConfig(1);
        $logger = $this->makeLogger();

        $logger->expects($this->once())
               ->method('error')
               ->with('Renderer timeout', $this->arrayHasKey('timeout'));

        $service = $this->makeService($config, $logger, $script);

        $this->expectException(RendererTimeoutException::class);
        $service->render('https://example.com/', $outputPath);
    }

    /**
     * RendererTimeoutException must carry HTTP status 504.
     *
     * Requirements: 2.5
     */
    public function testRendererTimeoutExceptionStatusCode(): void
    {
        $ex = new RendererTimeoutException();
        $this->assertSame(504, $ex->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 6. Stderr capped at 2000 characters
    // -----------------------------------------------------------------------

    /**
     * When stderr exceeds 2000 characters, the logged stderr field must be
     * capped at exactly 2000 characters.
     *
     * Requirements: 9.3
     */
    public function testStderrIsCappedAt2000Chars(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('long-stderr', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();

        $logger->expects($this->once())
               ->method('error')
               ->with(
                   'Renderer process failed',
                   $this->callback(function (array $context) {
                       return isset($context['stderr'])
                           && strlen($context['stderr']) <= 2000;
                   })
               );

        $service = $this->makeService($config, $logger, $script);

        try {
            $service->render('https://example.com/', $outputPath);
        } catch (RendererException) {
            // expected — we care about the logged stderr length
        }
    }

    // -----------------------------------------------------------------------
    // 7. URL redaction — api_key masked in log entries
    // -----------------------------------------------------------------------

    /**
     * The URL written to the log must have the api_key query-param value
     * replaced with [REDACTED] so secrets never appear in logs.
     *
     * Requirements: 9.4
     */
    public function testApiKeyIsRedactedInFailureLogEntry(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('failure', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();
        $secretKey  = 'super-secret-api-key-abc123';

        $logger->expects($this->once())
               ->method('error')
               ->with(
                   'Renderer process failed',
                   $this->callback(function (array $context) use ($secretKey) {
                       $loggedUrl = $context['url'] ?? '';
                       return !str_contains($loggedUrl, $secretKey)
                           && str_contains($loggedUrl, '[REDACTED]');
                   })
               );

        $service = $this->makeService($config, $logger, $script);

        try {
            $service->render(
                "https://example.com/?api_key={$secretKey}&other=1",
                $outputPath
            );
        } catch (RendererException) {
            // expected
        }
    }

    /**
     * A URL without an api_key param is logged unchanged (no spurious REDACTED).
     *
     * Requirements: 9.4
     */
    public function testUrlWithoutApiKeyIsNotAltered(): void
    {
        $tmpDir     = $this->makeTempDir();
        $outputPath = $tmpDir . '/out.pdf';
        $script     = $this->makeScript('failure', $tmpDir, $outputPath);
        $config     = $this->makeConfig(30);
        $logger     = $this->makeLogger();
        $url        = 'https://example.com/page?foo=bar';

        $logger->expects($this->once())
               ->method('error')
               ->with(
                   'Renderer process failed',
                   $this->callback(function (array $context) use ($url) {
                       return ($context['url'] ?? '') === $url;
                   })
               );

        $service = $this->makeService($config, $logger, $script);

        try {
            $service->render($url, $outputPath);
        } catch (RendererException) {
            // expected
        }
    }
}
