<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RendererException;
use App\Exception\RendererTimeoutException;
use App\Exception\RendererUnavailableException;
use App\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Spawns wkhtmltopdf as a subprocess to render a URL to a PDF file.
 *
 * Responsibilities:
 *  - Verify wkhtmltopdf is executable at construction time.
 *  - Build and launch the command via proc_open.
 *  - Enforce the configured rendering timeout.
 *  - Validate the resulting output file.
 *  - Log failures and successes with a redacted URL.
 */
class RendererService
{
    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {
        // Only check if the path is non-empty (allows testable environments where
        // the binary does not exist to construct the service without a real binary).
        if ($this->config->wkhtmltopdfPath !== '' && !$this->isExecutable($this->config->wkhtmltopdfPath)) {
            throw new RendererUnavailableException(
                'wkhtmltopdf is not executable at: ' . $this->config->wkhtmltopdfPath
            );
        }
    }

    /**
     * Render the given URL to a PDF at $outputPath.
     *
     * @param string $url        The validated URL to render.
     * @param string $outputPath Absolute path where the PDF should be written.
     *
     * @throws RendererTimeoutException When the process exceeds renderTimeoutSeconds.
     * @throws RendererException        When the process exits non-zero or produces
     *                                  empty/missing output.
     */
    public function render(string $url, string $outputPath): void
    {
        $command = $this->buildCommand($url, $outputPath);

        $nullDevice  = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],  // stdin  → null device
            1 => ['pipe', 'w'],               // stdout → pipe (captured but discarded)
            2 => ['pipe', 'w'],               // stderr → pipe (captured for error messages)
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RendererException('Failed to start wkhtmltopdf process');
        }

        // Switch stderr to non-blocking so we can drain it in the loop.
        // Note: On Windows, stream_set_blocking() on proc_open pipes has no effect;
        // we avoid blocking reads in the poll loop by only draining after process exit.
        if (DIRECTORY_SEPARATOR !== '\\') {
            stream_set_blocking($pipes[2], false);
            stream_set_blocking($pipes[1], false);
        }

        $startTime = microtime(true);
        $stderr    = '';
        $exitCode  = -1;

        // Poll until the process exits or we hit the timeout.
        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                // Process has finished — drain remaining stderr output.
                // On Unix with non-blocking pipes, fread will return '' when empty;
                // on Windows, the process is done so fread will get EOF quickly.
                $stderr .= $this->drainStream($pipes[2]);
                $stderr .= $this->drainStream($pipes[1]); // absorb any stdout too
                break;
            }

            // Check for timeout.
            if ((microtime(true) - $startTime) >= $this->config->renderTimeoutSeconds) {
                proc_terminate($process);

                // Give the process a brief moment to acknowledge the termination
                // signal, then drain pipes before closing.
                usleep(50_000); // 50 ms

                // Only drain on Unix (pipes are non-blocking there).
                // On Windows, skip draining to avoid blocking on pipe reads.
                if (DIRECTORY_SEPARATOR !== '\\') {
                    $this->drainStream($pipes[2]);
                    $this->drainStream($pipes[1]);
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                $this->logger->error('Renderer timeout', [
                    'url'     => $this->redactApiKey($url),
                    'timeout' => $this->config->renderTimeoutSeconds,
                ]);

                throw new RendererTimeoutException(
                    sprintf(
                        'PDF rendering timed out after %d second(s)',
                        $this->config->renderTimeoutSeconds
                    )
                );
            }

            // Sleep 100 ms between polls to avoid a busy-wait spin.
            usleep(100_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Cap stderr at 2000 characters.
        $stderrCapped = substr($stderr, 0, 2000);

        // ----------------------------------------------------------------
        // Non-zero exit code → rendering failure (502)
        // ----------------------------------------------------------------
        if ($exitCode !== 0) {
            $this->logger->error('Renderer process failed', [
                'url'       => $this->redactApiKey($url),
                'exit_code' => $exitCode,
                'stderr'    => $stderrCapped,
            ]);

            throw new RendererException(
                sprintf(
                    'wkhtmltopdf exited with code %d: %s',
                    $exitCode,
                    $stderrCapped !== '' ? $stderrCapped : '(no stderr output)'
                )
            );
        }

        // ----------------------------------------------------------------
        // Zero-byte or missing output file → bad output (502)
        // ----------------------------------------------------------------
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            $this->logger->error('Renderer produced empty output', [
                'url'       => $this->redactApiKey($url),
                'exit_code' => $exitCode,
                'stderr'    => $stderrCapped,
            ]);

            throw new RendererException('Renderer produced empty output');
        }

        // ----------------------------------------------------------------
        // Success — log filename and a success note
        // ----------------------------------------------------------------
        $this->logger->info('Renderer succeeded', [
            'filename' => basename($outputPath),
            'url'      => $this->redactApiKey($url),
        ]);
    }

    // -------------------------------------------------------------------------
    // Protected / overridable helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the given path is an executable file.
     *
     * Declared protected so test subclasses can stub it out without needing
     * a real wkhtmltopdf binary on the test machine.
     *
     * @param string $path Absolute path to the binary.
     * @return bool        True if the path is executable.
     */
    protected function isExecutable(string $path): bool
    {
        return is_executable($path);
    }

    /**
     * Build the command array for proc_open.
     *
     * Declared protected so test subclasses can substitute a different command
     * without needing a real wkhtmltopdf installation.
     *
     * @param string $url        The URL to render.
     * @param string $outputPath The path where the PDF should be written.
     * @return list<string>      Command as an array (avoids shell-quoting issues).
     */
    protected function buildCommand(string $url, string $outputPath): array
    {
        return [
            $this->config->wkhtmltopdfPath,
            '--no-background',
            '--disable-javascript',
            '--load-error-handling',
            'ignore',
            $outputPath,
            $url,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Read all currently-available bytes from a non-blocking stream.
     *
     * Returns an empty string if the stream has no data or has been closed.
     *
     * @param resource $stream
     * @return string
     */
    private function drainStream($stream): string
    {
        $output = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $output .= $chunk;
        }
        return $output;
    }

    /**
     * Replace the value of the `api_key` query parameter in a URL with
     * `[REDACTED]` so secrets are never written to log entries.
     *
     * @param string $url The original URL.
     * @return string     The URL with the api_key value masked.
     */
    private function redactApiKey(string $url): string
    {
        return preg_replace(
            '/([?&]api_key=)[^&]*/i',
            '$1[REDACTED]',
            $url
        ) ?? $url;
    }
}
