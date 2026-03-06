#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * RSS feed processor — CLI script for scheduled execution.
 *
 * Processes all RSS feeds due for refresh: fetch items, match filters,
 * add matching torrents.
 *
 * Usage:
 *   php rss-processor.php
 *
 * Designed to run via cron every 5 minutes. Uses a lock file to prevent
 * concurrent execution.
 */

$pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager/target';
$varDir = '/var/packages/TransmissionManager/var';
$lockFile = $varDir . '/rss-processor.lock';
$logFile = $varDir . '/rss-processor.log';

// ---------------------------------------------------------------
// Logging
// ---------------------------------------------------------------

/**
 * Append a log message with timestamp.
 */
function rssLog(string $message, string $logFile): void
{
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    // Rotate at 1 MB
    if (file_exists($logFile) && filesize($logFile) > 1048576) {
        rename($logFile, $logFile . '.old');
    }
}

// ---------------------------------------------------------------
// Lock file
// ---------------------------------------------------------------

/**
 * Acquire a lock file with stale detection.
 */
function acquireLock(string $lockFile): bool
{
    // Check for stale lock (older than 10 minutes)
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > 600) {
            unlink($lockFile);
        } else {
            return false;
        }
    }

    file_put_contents($lockFile, (string)getmypid());
    return true;
}

/**
 * Release the lock file.
 */
function releaseLock(string $lockFile): void
{
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ---------------------------------------------------------------
// Main
// ---------------------------------------------------------------

if (!acquireLock($lockFile)) {
    rssLog('Another instance is running, exiting', $logFile);
    exit(0);
}

try {
    // Load classes
    $srcDir = __DIR__;
    require_once $srcDir . '/TransmissionException.php';
    require_once $srcDir . '/ConnectionException.php';
    require_once $srcDir . '/AuthenticationException.php';
    require_once $srcDir . '/TransmissionRPC.php';
    require_once $srcDir . '/Database.php';
    require_once $srcDir . '/TorrentManager.php';
    require_once $srcDir . '/RSSManager.php';

    // Load config
    $configPath = $varDir . '/config.json';
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }

    $rpc = new TransmissionRPC(
        $config['rpc_host'] ?? 'localhost',
        (int)($config['rpc_port'] ?? 9091),
        $config['rpc_username'] ?? null,
        $config['rpc_password'] ?? null
    );

    $db = new Database($varDir . '/transmission.db');
    $allowedPaths = $config['allowed_paths'] ?? ['/volume1/'];
    $manager = new TorrentManager($rpc, $db, $allowedPaths);
    $rssManager = new RSSManager($db, $manager);

    rssLog('Starting RSS feed processing', $logFile);

    $results = $rssManager->processAllFeeds();
    $totalAdded = 0;
    $totalErrors = 0;

    foreach ($results as $result) {
        $totalAdded += $result['added'];
        $totalErrors += count($result['errors']);

        if ($result['added'] > 0 || !empty($result['errors'])) {
            rssLog(
                sprintf(
                    'Feed "%s": added=%d, skipped=%d, errors=%d',
                    $result['feedName'],
                    $result['added'],
                    $result['skipped'],
                    count($result['errors'])
                ),
                $logFile
            );
        }

        foreach ($result['errors'] as $error) {
            rssLog('  ERROR: ' . $error, $logFile);
        }
    }

    rssLog(
        sprintf('Processing complete: %d feeds, %d added, %d errors', count($results), $totalAdded, $totalErrors),
        $logFile
    );

    $db->close();
} catch (\Exception $e) {
    rssLog('FATAL: ' . $e->getMessage(), $logFile);
} finally {
    releaseLock($lockFile);
}
