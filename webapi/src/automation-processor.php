#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Automation rule processor — CLI script for scheduled execution.
 *
 * Evaluates automation rules against current torrent states and
 * executes matching actions.
 *
 * Usage:
 *   php automation-processor.php
 *
 * Designed to run via cron every minute. Uses a lock file to prevent
 * concurrent execution.
 */

$pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager/target';
$varDir = '/var/packages/TransmissionManager/var';
$lockFile = $varDir . '/automation-processor.lock';
$logFile = $varDir . '/automation-processor.log';

// ---------------------------------------------------------------
// Logging
// ---------------------------------------------------------------

/**
 * Append a log message with timestamp.
 */
function autoLog(string $message, string $logFile): void
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
function acquireAutoLock(string $lockFile): bool
{
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > 300) {
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
function releaseAutoLock(string $lockFile): void
{
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ---------------------------------------------------------------
// Main
// ---------------------------------------------------------------

if (!acquireAutoLock($lockFile)) {
    autoLog('Another instance is running, exiting', $logFile);
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
    require_once $srcDir . '/AutomationEngine.php';

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
    $allowedScripts = $config['allowed_script_paths'] ?? ['/volume1/scripts/'];
    $engine = new AutomationEngine($db, $rpc, $allowedScripts);

    autoLog('Starting automation processing', $logFile);

    // Get all torrents
    $torrents = $rpc->getTorrents();

    // Process on-complete rules (status=6 seeding means recently completed)
    $completedTorrents = array_filter($torrents, function ($t) {
        return ($t['status'] ?? -1) === 6 && ($t['percentDone'] ?? 0) >= 1.0;
    });
    if (!empty($completedTorrents)) {
        $log = $engine->processRules('on-complete', array_values($completedTorrents));
        foreach ($log as $entry) {
            if (!empty($entry['actions']) || !empty($entry['errors'])) {
                autoLog(
                    sprintf(
                        'Rule "%s" on "%s": actions=[%s], errors=[%s]',
                        $entry['rule'],
                        $entry['torrent'],
                        implode(',', $entry['actions']),
                        implode(',', $entry['errors'])
                    ),
                    $logFile
                );
            }
        }
    }

    // Process on-ratio rules (ratio exceeded)
    $ratioTorrents = array_filter($torrents, function ($t) {
        return ($t['uploadRatio'] ?? 0) > 0;
    });
    if (!empty($ratioTorrents)) {
        $log = $engine->processRules('on-ratio', array_values($ratioTorrents));
        foreach ($log as $entry) {
            if (!empty($entry['actions']) || !empty($entry['errors'])) {
                autoLog(
                    sprintf(
                        'Ratio rule "%s" on "%s": actions=[%s]',
                        $entry['rule'],
                        $entry['torrent'],
                        implode(',', $entry['actions'])
                    ),
                    $logFile
                );
            }
        }
    }

    // Process schedule rules (always evaluated)
    $log = $engine->processRules('schedule', $torrents);
    foreach ($log as $entry) {
        if (!empty($entry['actions']) || !empty($entry['errors'])) {
            autoLog(
                sprintf(
                    'Schedule rule "%s" on "%s": actions=[%s]',
                    $entry['rule'],
                    $entry['torrent'],
                    implode(',', $entry['actions'])
                ),
                $logFile
            );
        }
    }

    autoLog(
        sprintf('Processing complete: %d total torrents', count($torrents)),
        $logFile
    );

    $db->close();
} catch (\Exception $e) {
    autoLog('FATAL: ' . $e->getMessage(), $logFile);
} finally {
    releaseAutoLock($lockFile);
}
