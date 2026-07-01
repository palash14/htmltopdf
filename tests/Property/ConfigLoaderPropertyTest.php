<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Config\ConfigLoader;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for ConfigLoader.
 *
 * Feature: url-to-pdf-api
 */
class ConfigLoaderPropertyTest extends TestCase
{
    use TestTrait;

    // -----------------------------------------------------------------------
    // Eris / PHPUnit 10 compatibility shim
    // -----------------------------------------------------------------------

    /**
     * PHPUnit 10 removed getAnnotations() and PHPUnit\Util\Test::parseTestMethodAnnotations().
     * Override Eris's getTestCaseAnnotations() to return an empty structure,
     * which makes Eris use its defaults (100 iterations, rand method, 50% ratio).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function getTestCaseAnnotations(): array
    {
        return ['method' => [], 'class' => []];
    }

    // -----------------------------------------------------------------------
    // Helpers: env var management
    // -----------------------------------------------------------------------

    /** @var array<string, string|false> Snapshot of env vars before each test */
    private array $envSnapshot = [];

    /** @var string[] Names of env vars touched by the tests */
    private const ENV_KEYS = [
        'PORT',
        'WKHTMLTOPDF_PATH',
        'API_KEYS',
        'STORAGE_DIR',
        'BASE_URL',
        'TTL_SECONDS',
        'CLEANUP_INTERVAL_SECONDS',
        'MAX_CONCURRENT_RENDERERS',
        'RENDER_TIMEOUT_SECONDS',
        'MAX_STORAGE_MB',
        'RATE_LIMIT_RPM',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot current env so we can restore it after each test
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        // Restore env vars
        foreach (self::ENV_KEYS as $key) {
            $original = $this->envSnapshot[$key];
            if ($original === false) {
                putenv($key);
            } else {
                putenv("{$key}={$original}");
            }
        }
        parent::tearDown();
    }

    /**
     * Set a list of env vars for the duration of a single test call.
     *
     * @param array<string,string> $vars
     */
    private function setEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    /**
     * Clear env vars so ConfigLoader only sees the config file (or nothing).
     */
    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    /**
     * Write a temporary PHP config file and return its path.
     *
     * @param array<string,mixed> $values
     */
    private function writeTempConfig(array $values): string
    {
        $path = sys_get_temp_dir() . '/config_loader_test_' . uniqid() . '.php';
        $export = var_export($values, true);
        file_put_contents($path, "<?php\nreturn {$export};\n");
        return $path;
    }

    // -----------------------------------------------------------------------
    // Property 19: Configuration loading round-trip
    //
    // For any valid set of configuration values set as environment variables,
    // after ConfigLoader::load() is called, the resulting Config object SHALL
    // hold values equal to those set (after type coercion), and environment
    // variables SHALL take precedence over any conflicting values in the
    // config file.
    //
    // Validates: Requirements 10.1, 10.2
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 19a: All valid required + optional env vars round-trip through ConfigLoader::load()
     *
     * // Feature: url-to-pdf-api, Property 19: Configuration loading round-trip
     */
    public function testConfigurationRoundTripFromEnvVars(): void
    {
        $this->forAll(
            Generators::choose(1, 65535),            // port
            Generators::choose(60, 86400),           // ttlSeconds
            Generators::choose(10, 3600),            // cleanupIntervalSeconds
            Generators::choose(1, 50),               // maxConcurrentRenderers
            Generators::choose(5, 300)               // renderTimeoutSeconds
        )
            ->withMaxSize(100)
            ->then(function (
                int $port,
                int $ttl,
                int $cleanupInterval,
                int $maxRenderers,
                int $renderTimeout
            ): void {
                // Set env vars
                $this->setEnv([
                    'PORT'                      => (string) $port,
                    'WKHTMLTOPDF_PATH'          => '/usr/bin/wkhtmltopdf',
                    'API_KEYS'                  => 'testkey1,testkey2',
                    'STORAGE_DIR'               => sys_get_temp_dir(),
                    'BASE_URL'                  => 'https://example.com',
                    'TTL_SECONDS'               => (string) $ttl,
                    'CLEANUP_INTERVAL_SECONDS'  => (string) $cleanupInterval,
                    'MAX_CONCURRENT_RENDERERS'  => (string) $maxRenderers,
                    'RENDER_TIMEOUT_SECONDS'    => (string) $renderTimeout,
                ]);

                $config = ConfigLoader::load('/dev/null'); // no config file

                $this->assertSame($port, $config->port,
                    "port must round-trip: expected {$port}, got {$config->port}");
                $this->assertSame('/usr/bin/wkhtmltopdf', $config->wkhtmltopdfPath);
                $this->assertSame(['testkey1', 'testkey2'], $config->apiKeys);
                $this->assertSame(sys_get_temp_dir(), $config->storageDir);
                $this->assertSame('https://example.com', $config->baseUrl);
                $this->assertSame($ttl, $config->ttlSeconds,
                    "ttlSeconds must round-trip: expected {$ttl}, got {$config->ttlSeconds}");
                $this->assertSame($cleanupInterval, $config->cleanupIntervalSeconds,
                    "cleanupIntervalSeconds must round-trip: expected {$cleanupInterval}, got {$config->cleanupIntervalSeconds}");
                $this->assertSame($maxRenderers, $config->maxConcurrentRenderers,
                    "maxConcurrentRenderers must round-trip: expected {$maxRenderers}, got {$config->maxConcurrentRenderers}");
                $this->assertSame($renderTimeout, $config->renderTimeoutSeconds,
                    "renderTimeoutSeconds must round-trip: expected {$renderTimeout}, got {$config->renderTimeoutSeconds}");
            });
    }

    /**
     * @test
     * Property 19b: Nullable optional fields round-trip when supplied via env vars
     *
     * // Feature: url-to-pdf-api, Property 19: Configuration loading round-trip
     */
    public function testNullableOptionalFieldsRoundTrip(): void
    {
        $this->forAll(
            Generators::choose(1, 1000),  // rateLimitRpm
            Generators::choose(1, 65535)  // maxStorageMb (any positive int)
        )
            ->withMaxSize(100)
            ->then(function (int $rateLimitRpm, int $maxStorageMb): void {
                $this->setEnv([
                    'PORT'             => '8080',
                    'WKHTMLTOPDF_PATH' => '/usr/bin/wkhtmltopdf',
                    'API_KEYS'         => 'key1',
                    'STORAGE_DIR'      => sys_get_temp_dir(),
                    'BASE_URL'         => 'https://example.com',
                    'RATE_LIMIT_RPM'   => (string) $rateLimitRpm,
                    'MAX_STORAGE_MB'   => (string) $maxStorageMb,
                ]);

                $config = ConfigLoader::load('/dev/null');

                $this->assertSame($rateLimitRpm, $config->rateLimitRpm,
                    "rateLimitRpm must round-trip");
                $this->assertSame($maxStorageMb, $config->maxStorageMb,
                    "maxStorageMb must round-trip");
            });
    }

    /**
     * @test
     * Property 19c: Env vars take precedence over config file values
     *
     * // Feature: url-to-pdf-api, Property 19: Configuration loading round-trip
     */
    public function testEnvVarsTakePrecedenceOverConfigFile(): void
    {
        $this->forAll(
            Generators::choose(1, 65535),   // envPort  — the value that should win
            Generators::choose(1, 65535),   // filePort — the value that should lose
            Generators::choose(60, 86400),  // envTtl
            Generators::choose(60, 86400)   // fileTtl
        )
            ->withMaxSize(100)
            ->then(function (int $envPort, int $filePort, int $envTtl, int $fileTtl): void {
                // Write a config file that uses the "file" values
                $tmpConfig = $this->writeTempConfig([
                    'port'       => $filePort,
                    'ttlSeconds' => $fileTtl,
                    // required fields still present so the loader doesn't abort
                    'wkhtmltopdfPath' => '/from-file/wkhtmltopdf',
                    'apiKeys'         => ['file-key'],
                    'storageDir'      => sys_get_temp_dir(),
                    'baseUrl'         => 'https://file-base.example.com',
                ]);

                try {
                    // Env vars override the file values
                    $this->setEnv([
                        'PORT'             => (string) $envPort,
                        'TTL_SECONDS'      => (string) $envTtl,
                        'WKHTMLTOPDF_PATH' => '/from-env/wkhtmltopdf',
                        'API_KEYS'         => 'env-key',
                        'STORAGE_DIR'      => sys_get_temp_dir(),
                        'BASE_URL'         => 'https://env-base.example.com',
                    ]);

                    $config = ConfigLoader::load($tmpConfig);

                    // Env values should win
                    $this->assertSame($envPort, $config->port,
                        "Env PORT={$envPort} must override file port={$filePort}");
                    $this->assertSame($envTtl, $config->ttlSeconds,
                        "Env TTL_SECONDS={$envTtl} must override file ttlSeconds={$fileTtl}");
                    $this->assertSame('/from-env/wkhtmltopdf', $config->wkhtmltopdfPath,
                        'Env WKHTMLTOPDF_PATH must override file value');
                    $this->assertSame(['env-key'], $config->apiKeys,
                        'Env API_KEYS must override file value');
                    $this->assertSame('https://env-base.example.com', $config->baseUrl,
                        'Env BASE_URL must override file value');
                } finally {
                    @unlink($tmpConfig);
                }
            });
    }

    // -----------------------------------------------------------------------
    // Property 20: Missing or out-of-range configuration causes startup failure
    //
    // For any subset of missing required configuration keys, or any numeric
    // configuration value outside its valid range, startup SHALL fail with a
    // non-zero exit code and a log/stderr message that names the specific
    // offending setting.
    //
    // Validates: Requirements 10.3, 10.4
    // -----------------------------------------------------------------------

    /**
     * Run a PHP subprocess that calls ConfigLoader::load() with the given
     * environment variables, and return ['exitCode' => int, 'stderr' => string].
     *
     * @param array<string,string> $env   Environment variables to pass to the subprocess
     * @param string               $configFile Path to config file (empty string = no file)
     * @return array{exitCode: int, stderr: string}
     */
    private function runConfigLoaderSubprocess(array $env, string $configFile = ''): array
    {
        $defaultEmptyConfig = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $configFileArg = var_export($configFile !== '' ? $configFile : $defaultEmptyConfig, true);
        // Use absolute path to the project root's autoload so the script works
        // regardless of where the temp file is saved.
        $projectRoot = dirname(__DIR__, 2);
        $autoloadPath = $projectRoot . '/vendor/autoload.php';
        // var_export produces a properly-quoted PHP string literal (handles backslashes on Windows)
        $autoloadPathExport = var_export($autoloadPath, true);

        $script = <<<PHP
<?php
require {$autoloadPathExport};
\App\Config\ConfigLoader::load({$configFileArg});
PHP;

        $scriptFile = sys_get_temp_dir() . '/config_loader_probe_' . uniqid() . '.php';
        file_put_contents($scriptFile, $script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Build environment: start with a clean slate (no inherited env),
        // then add only what we need for the subprocess.
        $procEnv = $env;

        $process = proc_open(
            [PHP_BINARY, $scriptFile],
            $descriptors,
            $pipes,
            $projectRoot, // project root as cwd
            $procEnv
        );

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        @unlink($scriptFile);

        return ['exitCode' => $exitCode, 'stderr' => $stderr];
    }

    /**
     * @test
     * Property 20a: Missing any single required field causes non-zero exit
     * and stderr message naming the specific missing setting.
     *
     * // Feature: url-to-pdf-api, Property 20: Missing or out-of-range configuration causes startup failure
     */
    public function testMissingRequiredFieldCausesFailureWithNamedSetting(): void
    {
        // The full set of required fields; we test removing each one in turn.
        $requiredFields = [
            'PORT'             => ['env' => 'PORT',             'label' => 'PORT'],
            'WKHTMLTOPDF_PATH' => ['env' => 'WKHTMLTOPDF_PATH', 'label' => 'WKHTMLTOPDF_PATH'],
            'API_KEYS'         => ['env' => 'API_KEYS',         'label' => 'API_KEYS'],
            'STORAGE_DIR'      => ['env' => 'STORAGE_DIR',      'label' => 'STORAGE_DIR'],
            'BASE_URL'         => ['env' => 'BASE_URL',         'label' => 'BASE_URL'],
        ];

        $this->forAll(
            Generators::elements(array_keys($requiredFields))
        )
            ->withMaxSize(100)
            ->then(function (string $missingKey) use ($requiredFields): void {
                // Build a complete valid env, then drop one required field
                $baseEnv = [
                    'PORT'             => '8080',
                    'WKHTMLTOPDF_PATH' => '/usr/bin/wkhtmltopdf',
                    'API_KEYS'         => 'testkey',
                    'STORAGE_DIR'      => sys_get_temp_dir(),
                    'BASE_URL'         => 'https://example.com',
                ];
                unset($baseEnv[$missingKey]);

                $result = $this->runConfigLoaderSubprocess($baseEnv);

                $this->assertNotEquals(0, $result['exitCode'],
                    "Exit code must be non-zero when required field '{$missingKey}' is missing; got {$result['exitCode']}");

                $label = $requiredFields[$missingKey]['label'];
                $this->assertStringContainsString(
                    $label,
                    $result['stderr'],
                    "stderr must mention the offending setting '{$label}' when it is missing. " .
                    "stderr was: " . $result['stderr']
                );
            });
    }

    /**
     * @test
     * Property 20b: Out-of-range numeric values cause non-zero exit
     * and stderr message naming the specific out-of-range setting.
     *
     * // Feature: url-to-pdf-api, Property 20: Missing or out-of-range configuration causes startup failure
     */
    public function testOutOfRangeNumericValueCausesFailureWithNamedSetting(): void
    {
        // Each entry: [envKey, label, belowMin, aboveMax]
        $numericFields = [
            'PORT'                     => ['label' => 'PORT',                     'min' => 1,    'max' => 65535],
            'TTL_SECONDS'              => ['label' => 'TTL_SECONDS',              'min' => 60,   'max' => 86400],
            'CLEANUP_INTERVAL_SECONDS' => ['label' => 'CLEANUP_INTERVAL_SECONDS', 'min' => 10,   'max' => 3600],
            'MAX_CONCURRENT_RENDERERS' => ['label' => 'MAX_CONCURRENT_RENDERERS', 'min' => 1,    'max' => 50],
            'RENDER_TIMEOUT_SECONDS'   => ['label' => 'RENDER_TIMEOUT_SECONDS',   'min' => 5,    'max' => 300],
            'RATE_LIMIT_RPM'           => ['label' => 'RATE_LIMIT_RPM',           'min' => 1,    'max' => 1000],
        ];

        $fieldNames = array_keys($numericFields);

        $this->forAll(
            Generators::elements($fieldNames),
            Generators::bool() // true = use below-min value, false = use above-max value
        )
            ->withMaxSize(100)
            ->then(function (string $fieldKey, bool $useBelowMin) use ($numericFields): void {
                $spec = $numericFields[$fieldKey];

                $outOfRangeValue = $useBelowMin
                    ? ($spec['min'] - 1)   // one below minimum
                    : ($spec['max'] + 1);  // one above maximum

                // Only skip if below-min would produce a negative value that
                // PHP might interpret differently — specifically PORT min=1 so
                // below-min is 0, which is a legitimate "missing/zero" case.
                // We include it: the loader should still reject it.

                $baseEnv = [
                    'PORT'             => '8080',
                    'WKHTMLTOPDF_PATH' => '/usr/bin/wkhtmltopdf',
                    'API_KEYS'         => 'testkey',
                    'STORAGE_DIR'      => sys_get_temp_dir(),
                    'BASE_URL'         => 'https://example.com',
                ];

                // Override the field under test with the out-of-range value
                $baseEnv[$fieldKey] = (string) $outOfRangeValue;

                $result = $this->runConfigLoaderSubprocess($baseEnv);

                $this->assertNotEquals(0, $result['exitCode'],
                    "Exit code must be non-zero when '{$fieldKey}' = {$outOfRangeValue} is out of range; got {$result['exitCode']}");

                $label = $spec['label'];
                $this->assertStringContainsString(
                    $label,
                    $result['stderr'],
                    "stderr must mention the offending setting '{$label}' when its value {$outOfRangeValue} is out of range. " .
                    "stderr was: " . $result['stderr']
                );
            });
    }
}
