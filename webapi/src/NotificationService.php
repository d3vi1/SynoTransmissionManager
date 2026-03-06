<?php

declare(strict_types=1);

/**
 * DSM notification service for SynoTransmissionManager.
 *
 * Sends desktop notifications to DSM users via synodsmnotify (DSM 7+).
 * Supports verbosity filtering to allow users to control which
 * categories of notifications they receive.
 *
 * Categories:
 *   - download_complete: A torrent has finished downloading
 *   - rss_match:         An RSS filter matched a new item
 *   - automation:        An automation rule fired
 *   - error:             An error occurred
 */
class NotificationService
{
    /** @var string Package identifier for notifications */
    private $pkgName;

    /** @var string Verbosity level: 'all', 'errors', 'none' */
    private $verbosity;

    /** @var string[] Valid notification categories */
    private static $validCategories = [
        'download_complete',
        'rss_match',
        'automation',
        'error',
    ];

    /**
     * @param string $pkgName Package name shown in DSM notifications
     */
    public function __construct(string $pkgName = 'TransmissionManager')
    {
        $this->pkgName = $pkgName;
        $this->verbosity = 'all';
    }

    // ---------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------

    /**
     * Set the notification verbosity level.
     *
     * @param string $verbosity One of 'all', 'errors', 'none'
     * @throws \InvalidArgumentException If the verbosity level is invalid
     */
    public function setVerbosity(string $verbosity): void
    {
        $allowed = ['all', 'errors', 'none'];
        if (!in_array($verbosity, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Invalid verbosity level: ' . $verbosity . '. Allowed: ' . implode(', ', $allowed)
            );
        }
        $this->verbosity = $verbosity;
    }

    /**
     * Get the current verbosity level.
     *
     * @return string
     */
    public function getVerbosity(): string
    {
        return $this->verbosity;
    }

    // ---------------------------------------------------------------
    // Core notification method
    // ---------------------------------------------------------------

    /**
     * Send a notification to a DSM user.
     *
     * Respects verbosity settings:
     *   - 'none':   all notifications are suppressed
     *   - 'errors': only 'error' category notifications are sent
     *   - 'all':    all notifications are sent
     *
     * @param string $user     DSM username to notify
     * @param string $category Notification category
     * @param string $title    Notification title (for logging context)
     * @param string $message  Notification message body
     */
    public function notify(string $user, string $category, string $title, string $message): void
    {
        // Verbosity filtering
        if ($this->verbosity === 'none') {
            return;
        }

        if ($this->verbosity === 'errors' && $category !== 'error') {
            return;
        }

        try {
            $this->sendNotification($user, $message);
        } catch (\Exception $e) {
            error_log(
                'NotificationService: Failed to send notification to ' . $user
                . ' [' . $category . ']: ' . $e->getMessage()
            );
        }
    }

    // ---------------------------------------------------------------
    // Convenience methods
    // ---------------------------------------------------------------

    /**
     * Notify a user that a torrent download has completed.
     *
     * @param string $user        DSM username
     * @param string $torrentName Name of the completed torrent
     */
    public function notifyDownloadComplete(string $user, string $torrentName): void
    {
        $this->notify(
            $user,
            'download_complete',
            'Download Complete',
            'Download complete: ' . $torrentName
        );
    }

    /**
     * Notify a user that an RSS filter matched a new item.
     *
     * @param string $user      DSM username
     * @param string $feedName  Name of the RSS feed
     * @param string $itemTitle Title of the matched item
     */
    public function notifyRSSMatch(string $user, string $feedName, string $itemTitle): void
    {
        $this->notify(
            $user,
            'rss_match',
            'RSS Match',
            'RSS match in ' . $feedName . ': ' . $itemTitle
        );
    }

    /**
     * Notify a user that an automation rule has fired.
     *
     * @param string $user        DSM username
     * @param string $ruleName    Name of the automation rule
     * @param string $torrentName Name of the affected torrent
     */
    public function notifyAutomation(string $user, string $ruleName, string $torrentName): void
    {
        $this->notify(
            $user,
            'automation',
            'Automation',
            'Rule "' . $ruleName . '" applied to: ' . $torrentName
        );
    }

    /**
     * Notify a user about an error.
     *
     * @param string $user         DSM username
     * @param string $errorMessage Error description
     */
    public function notifyError(string $user, string $errorMessage): void
    {
        $this->notify(
            $user,
            'error',
            'Error',
            'Error: ' . $errorMessage
        );
    }

    // ---------------------------------------------------------------
    // Internal transport
    // ---------------------------------------------------------------

    /**
     * Send the notification via the DSM notification binary or fallback to error_log.
     *
     * On DSM 7+, uses /usr/syno/bin/synodsmnotify.
     * On systems without the binary, falls back to error_log.
     *
     * @param string $user    DSM username
     * @param string $message Notification message
     */
    protected function sendNotification(string $user, string $message): void
    {
        $binary = '/usr/syno/bin/synodsmnotify';

        if ($this->isExecutable($binary)) {
            $escapedUser = escapeshellarg($user);
            $escapedPkg = escapeshellarg($this->pkgName);
            $escapedMsg = escapeshellarg($message);

            $command = $binary . ' ' . $escapedUser . ' ' . $escapedPkg . ' ' . $escapedMsg;
            $this->executeCommand($command);
        } else {
            error_log(
                'NotificationService [' . $this->pkgName . ']: '
                . '@' . $user . ' - ' . $message
            );
        }
    }

    /**
     * Check if a binary is executable.
     *
     * @param string $path Path to the binary
     * @return bool
     */
    protected function isExecutable(string $path): bool
    {
        return is_executable($path);
    }

    /**
     * Execute a shell command.
     *
     * @param string $command Shell command to execute
     */
    protected function executeCommand(string $command): void
    {
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            error_log(
                'NotificationService: Command failed (exit ' . $exitCode . '): '
                . implode("\n", $output)
            );
        }
    }
}
