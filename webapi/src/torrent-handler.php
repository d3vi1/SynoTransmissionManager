#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * File Station .torrent handler for DSM 7.
 *
 * Invoked by DSM File Station when a user opens a .torrent file via
 * the context menu "Open with Transmission Manager" action.
 *
 * Usage:
 *   php torrent-handler.php /path/to/file.torrent
 *
 * Environment:
 *   SYNOPKG_DSM_USER  — authenticated DSM username
 *   SYNOPKG_PKGDEST   — package installation path
 *
 * Exit codes:
 *   0 — success
 *   1 — error
 */

$logFile = '/var/log/transmission-manager.log';

/**
 * Write a message to the log file.
 *
 * @param string $message Log message
 */
function logMessage(string $message): void
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] torrent-handler: ' . $message . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

try {
    // ---------------------------------------------------------------
    // Validate arguments
    // ---------------------------------------------------------------

    if (!isset($argv[1]) || $argv[1] === '') {
        logMessage('ERROR: No file path provided');
        fwrite(STDERR, "Usage: torrent-handler.php <path-to-torrent-file>\n");
        exit(1);
    }

    $filePath = $argv[1];

    if (!file_exists($filePath)) {
        logMessage('ERROR: File not found: ' . $filePath);
        fwrite(STDERR, "File not found: " . $filePath . "\n");
        exit(1);
    }

    if (!is_readable($filePath)) {
        logMessage('ERROR: File not readable: ' . $filePath);
        fwrite(STDERR, "File not readable: " . $filePath . "\n");
        exit(1);
    }

    // ---------------------------------------------------------------
    // Determine user
    // ---------------------------------------------------------------

    $user = getenv('SYNOPKG_DSM_USER');
    if ($user === false || $user === '') {
        $user = getenv('USER');
    }
    if ($user === false || $user === '') {
        logMessage('ERROR: Cannot determine DSM user (SYNOPKG_DSM_USER not set)');
        fwrite(STDERR, "Cannot determine DSM user\n");
        exit(1);
    }

    logMessage('Processing torrent file: ' . $filePath . ' for user: ' . $user);

    // ---------------------------------------------------------------
    // Load dependencies
    // ---------------------------------------------------------------

    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $srcDir = $pkgDest . '/target/webapi/src';

    // Try composer autoload first, then manual includes
    $autoload = $pkgDest . '/target/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        require_once $srcDir . '/TransmissionException.php';
        require_once $srcDir . '/ConnectionException.php';
        require_once $srcDir . '/AuthenticationException.php';
        require_once $srcDir . '/TransmissionRPC.php';
        require_once $srcDir . '/Database.php';
        require_once $srcDir . '/TorrentManager.php';
        require_once $srcDir . '/NotificationService.php';
    }

    // ---------------------------------------------------------------
    // Load configuration
    // ---------------------------------------------------------------

    $configPath = $pkgDest . '/var/config.json';
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }

    $rpcHost = $config['rpc_host'] ?? 'localhost';
    $rpcPort = (int)($config['rpc_port'] ?? 9091);
    $rpcUser = $config['rpc_username'] ?? null;
    $rpcPass = $config['rpc_password'] ?? null;
    $allowedPaths = $config['allowed_paths'] ?? ['/volume1/'];

    // ---------------------------------------------------------------
    // Create services
    // ---------------------------------------------------------------

    $rpc = new TransmissionRPC($rpcHost, $rpcPort, $rpcUser, $rpcPass);
    $db = new Database($pkgDest . '/var/transmission.db');
    $manager = new TorrentManager($rpc, $db, $allowedPaths);
    $notifier = new NotificationService();

    $verbosity = $config['notification_verbosity'] ?? 'all';
    $notifier->setVerbosity($verbosity);

    // ---------------------------------------------------------------
    // Read and add the torrent
    // ---------------------------------------------------------------

    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        logMessage('ERROR: Failed to read file: ' . $filePath);
        fwrite(STDERR, "Failed to read torrent file\n");
        exit(1);
    }

    $result = $manager->addTorrentFile($user, $fileContent);

    $torrentName = 'Unknown';
    $torrentInfo = $result['torrent-added'] ?? $result['torrent-duplicate'] ?? null;
    if ($torrentInfo !== null && isset($torrentInfo['name'])) {
        $torrentName = $torrentInfo['name'];
    }

    logMessage('SUCCESS: Added torrent "' . $torrentName . '" for user ' . $user);

    // ---------------------------------------------------------------
    // Notify the user
    // ---------------------------------------------------------------

    $notifier->notifyDownloadComplete($user, $torrentName);

    $db->close();
    exit(0);

} catch (ConnectionException $e) {
    logMessage('ERROR: Cannot reach Transmission daemon: ' . $e->getMessage());
    fwrite(STDERR, "Cannot reach Transmission daemon: " . $e->getMessage() . "\n");
    exit(1);
} catch (AuthenticationException $e) {
    logMessage('ERROR: Transmission authentication failed: ' . $e->getMessage());
    fwrite(STDERR, "Transmission authentication failed\n");
    exit(1);
} catch (TransmissionException $e) {
    logMessage('ERROR: Transmission error: ' . $e->getMessage());
    fwrite(STDERR, "Transmission error: " . $e->getMessage() . "\n");
    exit(1);
} catch (\Exception $e) {
    logMessage('ERROR: Unexpected error: ' . $e->getMessage());
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
