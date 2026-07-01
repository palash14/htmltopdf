<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigLoader;
use App\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigLoader.
 *
 * Failure paths (those that call exit(1)) are tested via subprocess:
 * a small PHP script is executed in a child process, and we assert
 * that the process exits with code 1 and that stderr contains the
 * name of the offending setting.
 *
 * Requirements: 10.1–10.6
 */
class ConfigLoaderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Absolute path to the project's autoloader so subprocess scripts can
     * bootstrap the class under test without any additional setup.
     */
    private string $autoload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    }

    /**
     * Build a minimal valid set of environment variables as an associative
     * array suitable for passing to runSubprocess().
     *
     * @return array<string,string>
     */
    private function validEnv(): array
    {
        return [
            'PORT'             => '8080',
            'WKHTMLTOPDF_PATH' => '/usr/local/bin/wkhtmltopdf',
            'API_KEYS'         => 'key-one,key-two',
            'STORAGE_DIR'      => '/tmp/pdfs',
            'BASE_URL'         => 'https://api.example.com',
        ];
    }

    /**
     * Run a PHP one-liner in a subprocess with the given environment
     * variables, and return [exitCode, stdout, stderr].
     *
     * @param string               $phpCode  Code to execute (no opening tag needed).
     * @param array<string,string> $env      Environment variables to set.
     *
     * @return array{int,string,string}
     */
    private function runSubprocess(string $phpCode, array $env = []): array
    {
        // Inherit a clean PATH but strip all APP env vars so previous
        // PHPUnit env state cannot bleed into the child.
        $inheritedEnv = [
            'PATH'              => getenv('PATH') ?: '',
            'SystemRoot'        => getenv('SystemRoot') ?: '',
            'SYSTEMROOT'        => getenv('SYSTEMROOT') ?: '',
            'TEMP'              => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP'               => getenv('TMP') ?: sys_get_temp_dir(),
            'WINDIR'            => getenv('WINDIR') ?: '',
            'ComSpec'           => getenv('ComSpec') ?: '',
        ];

        $childEnv = array_merge(
            array_filter($inheritedEnv, static fn($v) => $v !== ''),
            $env
        );

        $autoloadEscaped = addslashes($this->autoload);
        $script = "<?php require '{$autoloadEscaped}'; {$phpCode}";

        // Write script to a temp file to avoid command-line quoting issues
        $tmpFile = tempnam(sys_get_temp_dir(), 'clt_') . '.php';
        file_put_contents($tmpFile, $script);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $tmpFile],
            $descriptorSpec,
            $pipes,
            null,
            $childEnv
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        @unlink($tmpFile);

        return [$exitCode, (string) $stdout, (string) $stderr];
    }

    /**
     * Build the PHP snippet that calls ConfigLoader::load() with an optional
     * config file path.
     *
     * @param string|null $configFile Pass null to omit the config file argument.
     */
    private function loadSnippet(?string $configFile = null): string
    {
        if ($configFile === null) {
            $configFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        }
        $escaped = addslashes($configFile);
        return "\App\Config\ConfigLoader::load('{$escaped}');";
    }

    /**
     * Write a temporary PHP config file returning the given array and return
     * its path.
     *
     * @param array<string,mixed> $data
     */
    private function writeTempConfig(array $data): string
    {
        $export = var_export($data, true);
        $tmpFile = tempnam(sys_get_temp_dir(), 'cfg_') . '.php';
        file_put_contents($tmpFile, "<?php return {$export};\n");
        return $tmpFile;
    }

    /**
     * Write a temporary dotenv file and return its path.
     *
     * @param array<string,string> $data
     */
    private function writeTempEnv(array $data): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'env_');
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        file_put_contents($tmpFile, implode("\n", $lines) . "\n");
        return $tmpFile;
    }

    // -----------------------------------------------------------------------
    // 1. Happy path — all required env vars set
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.1, 10.2
     */
    public function testHappyPathReturnsConfigWithCorrectValues(): void
    {
        // Set env vars that ConfigLoader::load() will pick up
        putenv('PORT=9090');
        putenv('WKHTMLTOPDF_PATH=/usr/bin/wkhtmltopdf');
        putenv('API_KEYS=alpha,beta,gamma');
        putenv('STORAGE_DIR=/tmp/test-storage');
        putenv('BASE_URL=https://test.example.com');
        // Clear optional vars so defaults kick in
        putenv('TTL_SECONDS');
        putenv('CLEANUP_INTERVAL_SECONDS');
        putenv('MAX_CONCURRENT_RENDERERS');
        putenv('RENDER_TIMEOUT_SECONDS');
        putenv('MAX_STORAGE_MB');
        putenv('RATE_LIMIT_RPM');

        try {
            // Use /dev/null (or NUL on Windows) as config file so no file config bleeds in
            $config = ConfigLoader::load(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

            self::assertInstanceOf(Config::class, $config);
            self::assertSame(9090, $config->port);
            self::assertSame('/usr/bin/wkhtmltopdf', $config->wkhtmltopdfPath);
            self::assertSame(['alpha', 'beta', 'gamma'], $config->apiKeys);
            self::assertSame('/tmp/test-storage', $config->storageDir);
            self::assertSame('https://test.example.com', $config->baseUrl);
            // Defaults
            self::assertSame(3600, $config->ttlSeconds);
            self::assertSame(60, $config->cleanupIntervalSeconds);
            self::assertSame(5, $config->maxConcurrentRenderers);
            self::assertSame(30, $config->renderTimeoutSeconds);
            self::assertNull($config->maxStorageMb);
            self::assertNull($config->rateLimitRpm);
        } finally {
            // Clean up env vars so they don't leak into other tests
            putenv('PORT');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL');
        }
    }

    // -----------------------------------------------------------------------
    // 2–6. Missing required fields → exit 1 + stderr mentions setting name
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.3, 10.6
     */
    public function testMissingPortExitsOneAndMentionsPort(): void
    {
        $env = $this->validEnv();
        unset($env['PORT']);

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when PORT is missing');
        self::assertStringContainsStringIgnoringCase('PORT', $stderr, 'Stderr should mention PORT');
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.3
     */
    public function testMissingWkhtmltopdfPathExitsOneAndMentionsSetting(): void
    {
        $env = $this->validEnv();
        unset($env['WKHTMLTOPDF_PATH']);

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when WKHTMLTOPDF_PATH is missing');
        self::assertStringContainsStringIgnoringCase('WKHTMLTOPDF_PATH', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.3, 10.6
     */
    public function testMissingApiKeysExitsOneAndMentionsSetting(): void
    {
        $env = $this->validEnv();
        unset($env['API_KEYS']);

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when API_KEYS is missing');
        self::assertStringContainsStringIgnoringCase('API_KEYS', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.3
     */
    public function testMissingStorageDirExitsOneAndMentionsSetting(): void
    {
        $env = $this->validEnv();
        unset($env['STORAGE_DIR']);

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when STORAGE_DIR is missing');
        self::assertStringContainsStringIgnoringCase('STORAGE_DIR', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.3
     */
    public function testMissingBaseUrlExitsOneAndMentionsSetting(): void
    {
        $env = $this->validEnv();
        unset($env['BASE_URL']);

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when BASE_URL is missing');
        self::assertStringContainsStringIgnoringCase('BASE_URL', $stderr);
    }

    // -----------------------------------------------------------------------
    // 7–10. Numeric fields out of range → exit 1
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.4
     */
    public function testPortZeroExitsOne(): void
    {
        $env = $this->validEnv();
        $env['PORT'] = '0';

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 for PORT=0');
        self::assertStringContainsStringIgnoringCase('PORT', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.4
     */
    public function testPortSixtyFiveThirtySixExitsOne(): void
    {
        $env = $this->validEnv();
        $env['PORT'] = '65536';

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 for PORT=65536');
        self::assertStringContainsStringIgnoringCase('PORT', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.4
     */
    public function testTtlSecondsFiftyNineExitsOne(): void
    {
        $env = $this->validEnv();
        $env['TTL_SECONDS'] = '59';

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 for TTL_SECONDS=59 (below minimum 60)');
        self::assertStringContainsStringIgnoringCase('TTL_SECONDS', $stderr);
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.4
     */
    public function testTtlSecondsEightyFourFourOhOneExitsOne(): void
    {
        $env = $this->validEnv();
        $env['TTL_SECONDS'] = '86401';

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 for TTL_SECONDS=86401 (above maximum 86400)');
        self::assertStringContainsStringIgnoringCase('TTL_SECONDS', $stderr);
    }

    // -----------------------------------------------------------------------
    // 11. Env var takes precedence over config file
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.2
     */
    public function testEnvVarTakesPrecedenceOverConfigFileForPort(): void
    {
        $configFile = $this->writeTempConfig([
            'port'             => 7000,
            'wkhtmltopdfPath'  => '/file/wkhtmltopdf',
            'apiKeys'          => ['file-key'],
            'storageDir'       => '/tmp/file-storage',
            'baseUrl'          => 'https://file.example.com',
        ]);

        try {
            putenv('PORT=9999');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL');
            putenv('TTL_SECONDS');
            putenv('CLEANUP_INTERVAL_SECONDS');
            putenv('MAX_CONCURRENT_RENDERERS');
            putenv('RENDER_TIMEOUT_SECONDS');
            putenv('MAX_STORAGE_MB');
            putenv('RATE_LIMIT_RPM');

            $config = ConfigLoader::load($configFile);

            self::assertSame(9999, $config->port, 'Env var PORT must override config file port');
        } finally {
            putenv('PORT');
            @unlink($configFile);
        }
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.2
     */
    public function testEnvVarTakesPrecedenceOverConfigFileForBaseUrl(): void
    {
        $configFile = $this->writeTempConfig([
            'port'             => 8080,
            'wkhtmltopdfPath'  => '/file/wkhtmltopdf',
            'apiKeys'          => ['file-key'],
            'storageDir'       => '/tmp/file-storage',
            'baseUrl'          => 'https://file.example.com',
        ]);

        try {
            putenv('PORT');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL=https://env.example.com');
            putenv('TTL_SECONDS');
            putenv('CLEANUP_INTERVAL_SECONDS');
            putenv('MAX_CONCURRENT_RENDERERS');
            putenv('RENDER_TIMEOUT_SECONDS');
            putenv('MAX_STORAGE_MB');
            putenv('RATE_LIMIT_RPM');

            $config = ConfigLoader::load($configFile);

            self::assertSame(
                'https://env.example.com',
                $config->baseUrl,
                'Env var BASE_URL must override config file baseUrl'
            );
        } finally {
            putenv('BASE_URL');
            @unlink($configFile);
        }
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.1, 10.2
     */
    public function testDotenvFileTakesPrecedenceOverConfigFile(): void
    {
        $configFile = $this->writeTempConfig([
            'port'             => 8080,
            'wkhtmltopdfPath'  => '/file/wkhtmltopdf',
            'apiKeys'          => ['file-key'],
            'storageDir'       => '/tmp/file-storage',
            'baseUrl'          => 'https://file.example.com',
        ]);
        $envFile = $this->writeTempEnv([
            'PORT'             => '9090',
            'WKHTMLTOPDF_PATH' => '/env/wkhtmltopdf',
            'API_KEYS'         => 'env-key-one,env-key-two',
            'STORAGE_DIR'      => '/tmp/env-storage',
            'BASE_URL'         => 'https://env.example.com',
        ]);

        try {
            putenv('PORT');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL');
            putenv('TTL_SECONDS');
            putenv('CLEANUP_INTERVAL_SECONDS');
            putenv('MAX_CONCURRENT_RENDERERS');
            putenv('RENDER_TIMEOUT_SECONDS');
            putenv('MAX_STORAGE_MB');
            putenv('RATE_LIMIT_RPM');

            $config = ConfigLoader::load($configFile, $envFile);

            self::assertSame(9090, $config->port);
            self::assertSame('/env/wkhtmltopdf', $config->wkhtmltopdfPath);
            self::assertSame(['env-key-one', 'env-key-two'], $config->apiKeys);
            self::assertSame('/tmp/env-storage', $config->storageDir);
            self::assertSame('https://env.example.com', $config->baseUrl);
        } finally {
            @unlink($configFile);
            @unlink($envFile);
        }
    }

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * Requirements: 10.2
     */
    public function testProcessEnvTakesPrecedenceOverDotenvFile(): void
    {
        $configFile = $this->writeTempConfig([
            'port'             => 8080,
            'wkhtmltopdfPath'  => '/file/wkhtmltopdf',
            'apiKeys'          => ['file-key'],
            'storageDir'       => '/tmp/file-storage',
            'baseUrl'          => 'https://file.example.com',
        ]);
        $envFile = $this->writeTempEnv([
            'PORT'             => '9090',
            'WKHTMLTOPDF_PATH' => '/env/wkhtmltopdf',
            'API_KEYS'         => 'env-key',
            'STORAGE_DIR'      => '/tmp/env-storage',
            'BASE_URL'         => 'https://env.example.com',
        ]);

        try {
            putenv('PORT=7070');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL');
            putenv('TTL_SECONDS');
            putenv('CLEANUP_INTERVAL_SECONDS');
            putenv('MAX_CONCURRENT_RENDERERS');
            putenv('RENDER_TIMEOUT_SECONDS');
            putenv('MAX_STORAGE_MB');
            putenv('RATE_LIMIT_RPM');

            $config = ConfigLoader::load($configFile, $envFile);

            self::assertSame(7070, $config->port);
            self::assertSame('/env/wkhtmltopdf', $config->wkhtmltopdfPath);
        } finally {
            putenv('PORT');
            @unlink($configFile);
            @unlink($envFile);
        }
    }

    // -----------------------------------------------------------------------
    // 12. Empty API_KEYS → exit 1
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * When API_KEYS is present but contains no non-empty values (e.g. just
     * commas or whitespace) the loader must treat this as a missing required
     * setting and exit with code 1.
     *
     * Requirements: 10.3, 10.6
     */
    public function testEmptyApiKeysExitsOne(): void
    {
        $env = $this->validEnv();
        $env['API_KEYS'] = ',,,';

        [$exitCode, , $stderr] = $this->runSubprocess($this->loadSnippet(), $env);

        self::assertSame(1, $exitCode, 'Expected exit code 1 when API_KEYS resolves to empty list');
        self::assertStringContainsStringIgnoringCase('API_KEYS', $stderr);
    }

    // -----------------------------------------------------------------------
    // 13. Optional fields use defaults when absent
    // -----------------------------------------------------------------------

    /**
     * @covers \App\Config\ConfigLoader::load
     *
     * When optional settings are not provided (neither via env nor config file)
     * the resulting Config object should carry the documented default values.
     *
     * Requirements: 10.1
     */
    public function testOptionalFieldsUseDefaultsWhenAbsent(): void
    {
        $configFile = $this->writeTempConfig([
            'port'             => 3000,
            'wkhtmltopdfPath'  => '/usr/bin/wkhtmltopdf',
            'apiKeys'          => ['only-key'],
            'storageDir'       => '/tmp/storage',
            'baseUrl'          => 'https://defaults.example.com',
            // No optional keys provided
        ]);

        try {
            // Clear all env vars so config file is the sole source
            putenv('PORT');
            putenv('WKHTMLTOPDF_PATH');
            putenv('API_KEYS');
            putenv('STORAGE_DIR');
            putenv('BASE_URL');
            putenv('TTL_SECONDS');
            putenv('CLEANUP_INTERVAL_SECONDS');
            putenv('MAX_CONCURRENT_RENDERERS');
            putenv('RENDER_TIMEOUT_SECONDS');
            putenv('MAX_STORAGE_MB');
            putenv('RATE_LIMIT_RPM');

            $config = ConfigLoader::load($configFile);

            self::assertSame(3600, $config->ttlSeconds,             'Default ttlSeconds should be 3600');
            self::assertSame(60,   $config->cleanupIntervalSeconds, 'Default cleanupIntervalSeconds should be 60');
            self::assertSame(5,    $config->maxConcurrentRenderers, 'Default maxConcurrentRenderers should be 5');
            self::assertSame(30,   $config->renderTimeoutSeconds,   'Default renderTimeoutSeconds should be 30');
            self::assertNull($config->maxStorageMb,                 'Default maxStorageMb should be null');
            self::assertNull($config->rateLimitRpm,                 'Default rateLimitRpm should be null');
        } finally {
            @unlink($configFile);
        }
    }
}
