<?php

/**
 * Example configuration file for url-to-pdf-api.
 *
 * Copy this file to config/config.php and adjust the values for your
 * environment.  Environment variables always take precedence over values
 * defined here (see .env.example for the matching variable names).
 *
 * Return an associative array from this file.
 */

return [

    // -------------------------------------------------------------------------
    // REQUIRED — these settings have no default and must be provided
    // -------------------------------------------------------------------------

    /**
     * port  (int, 1–65535)
     *
     * The TCP port the application listens on.
     * When running behind Apache/Nginx on cPanel this is typically 80 or 443;
     * for local development you might use 8080.
     *
     * Env var: PORT
     */
    'port' => 8080,

    /**
     * wkhtmltopdfPath  (string)
     *
     * Absolute path to the wkhtmltopdf binary on the server.
     * Find yours with:  which wkhtmltopdf
     *
     * Env var: WKHTMLTOPDF_PATH
     */
    'wkhtmltopdfPath' => '/usr/local/bin/wkhtmltopdf',

    /**
     * apiKeys  (string[], 1–100 entries)
     *
     * List of valid API keys that clients must supply in the
     * "Authorization: Bearer <key>" header.
     * Keep these long (≥32 chars), random, and secret.
     * Via environment variable use a comma-separated string:
     *   API_KEYS="key-one,key-two"
     *
     * Env var: API_KEYS  (comma-separated)
     */
    'apiKeys' => [
        'replace-this-with-a-long-random-secret-key',
    ],

    /**
     * storageDir  (string)
     *
     * Absolute path to the directory where generated PDF files and their
     * companion .json sidecar files are stored.
     * The directory must be writable by the PHP process.
     *
     * Env var: STORAGE_DIR
     */
    'storageDir' => '/var/www/html/storage/pdfs',

    /**
     * baseUrl  (string)
     *
     * The fully-qualified base URL of this API (no trailing slash).
     * Used to build the download_url returned in successful responses.
     * Example: "https://api.example.com"
     *
     * Env var: BASE_URL
     */
    'baseUrl' => 'https://api.example.com',

    // -------------------------------------------------------------------------
    // OPTIONAL — sensible defaults are applied when these are absent
    // -------------------------------------------------------------------------

    /**
     * ttlSeconds  (int, 60–86400, default 3600)
     *
     * How long (in seconds) a generated PDF is kept before the cleanup job
     * may delete it.  Default is 3 600 s (1 hour).
     *
     * Env var: TTL_SECONDS
     */
    // 'ttlSeconds' => 3600,

    /**
     * cleanupIntervalSeconds  (int, 10–3600, default 60)
     *
     * How often (in seconds) the cleanup cron job should run.
     * Set this in your cPanel cron schedule to match.  Default is 60 s.
     *
     * Env var: CLEANUP_INTERVAL_SECONDS
     */
    // 'cleanupIntervalSeconds' => 60,

    /**
     * maxConcurrentRenderers  (int, 1–50, default 5)
     *
     * Maximum number of wkhtmltopdf processes that may run at the same time.
     * Tune this to the CPU/memory headroom available on your server.
     *
     * Env var: MAX_CONCURRENT_RENDERERS
     */
    // 'maxConcurrentRenderers' => 5,

    /**
     * renderTimeoutSeconds  (int, 5–300, default 30)
     *
     * Maximum number of seconds to wait for a single wkhtmltopdf invocation
     * before terminating the process and returning HTTP 504 to the client.
     *
     * Env var: RENDER_TIMEOUT_SECONDS
     */
    // 'renderTimeoutSeconds' => 30,

    /**
     * rendererEngine  ("wkhtmltopdf"|"chrome", default "wkhtmltopdf")
     *
     * Use "chrome" for the closest match to modern browser CSS rendering.
     *
     * Env var: RENDERER_ENGINE
     */
    // 'rendererEngine' => 'wkhtmltopdf',

    /**
     * chromePath  (string|null, required when rendererEngine="chrome")
     *
     * Absolute path to Google Chrome or Chromium.
     *
     * Env var: CHROME_PATH
     */
    // 'chromePath' => '/usr/bin/google-chrome',

    /**
     * maxStorageMb  (int > 0 | null, default null = disabled)
     *
     * If set, the API will refuse new conversion requests with HTTP 507 once
     * the storage directory reaches or exceeds this size (in megabytes).
     * Leave null (or omit) to disable the limit.
     *
     * Env var: MAX_STORAGE_MB
     */
    // 'maxStorageMb' => null,

    /**
     * rateLimitRpm  (int 1–1000 | null, default null = disabled)
     *
     * When set, each API key is limited to this many requests per minute.
     * Excess requests receive HTTP 429.  Leave null (or omit) to disable.
     *
     * Env var: RATE_LIMIT_RPM
     */
    // 'rateLimitRpm' => null,

];
