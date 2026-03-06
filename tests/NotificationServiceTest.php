<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that captures commands instead of executing them.
 */
class TestableNotificationService extends NotificationService
{
    /** @var string[] Captured shell commands */
    public $executedCommands = [];

    /** @var bool Whether the notification binary is "available" */
    public $binaryAvailable = true;

    /** @var string[] Captured error_log messages */
    public $loggedMessages = [];

    protected function isExecutable(string $path): bool
    {
        return $this->binaryAvailable;
    }

    protected function executeCommand(string $command): void
    {
        $this->executedCommands[] = $command;
    }

    protected function sendNotification(string $user, string $message): void
    {
        if ($this->binaryAvailable) {
            $escapedUser = escapeshellarg($user);
            $escapedPkg = escapeshellarg('TransmissionManager');
            $escapedMsg = escapeshellarg($message);

            $command = '/usr/syno/bin/synodsmnotify ' . $escapedUser . ' ' . $escapedPkg . ' ' . $escapedMsg;
            $this->executeCommand($command);
        } else {
            $this->loggedMessages[] = 'NotificationService [TransmissionManager]: @' . $user . ' - ' . $message;
        }
    }
}

/**
 * Tests for NotificationService.
 *
 * Uses TestableNotificationService to capture shell commands and log
 * messages rather than executing them on the system.
 */
class NotificationServiceTest extends TestCase
{
    /** @var TestableNotificationService */
    private $service;

    protected function setUp(): void
    {
        $this->service = new TestableNotificationService();
    }

    // ---------------------------------------------------------------
    // Constructor / defaults
    // ---------------------------------------------------------------

    public function testDefaultVerbosityIsAll(): void
    {
        $this->assertSame('all', $this->service->getVerbosity());
    }

    // ---------------------------------------------------------------
    // setVerbosity
    // ---------------------------------------------------------------

    public function testSetVerbosityAcceptsValidValues(): void
    {
        $this->service->setVerbosity('all');
        $this->assertSame('all', $this->service->getVerbosity());

        $this->service->setVerbosity('errors');
        $this->assertSame('errors', $this->service->getVerbosity());

        $this->service->setVerbosity('none');
        $this->assertSame('none', $this->service->getVerbosity());
    }

    public function testSetVerbosityRejectsInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setVerbosity('invalid');
    }

    // ---------------------------------------------------------------
    // Verbosity filtering — 'none'
    // ---------------------------------------------------------------

    public function testVerbosityNoneSuppressesAllNotifications(): void
    {
        $this->service->setVerbosity('none');

        $this->service->notify('alice', 'download_complete', 'Title', 'Message');
        $this->service->notify('alice', 'rss_match', 'Title', 'Message');
        $this->service->notify('alice', 'automation', 'Title', 'Message');
        $this->service->notify('alice', 'error', 'Title', 'Message');

        $this->assertCount(0, $this->service->executedCommands);
        $this->assertCount(0, $this->service->loggedMessages);
    }

    // ---------------------------------------------------------------
    // Verbosity filtering — 'errors'
    // ---------------------------------------------------------------

    public function testVerbosityErrorsOnlySendsErrorCategory(): void
    {
        $this->service->setVerbosity('errors');

        $this->service->notify('alice', 'download_complete', 'Title', 'Complete');
        $this->service->notify('alice', 'rss_match', 'Title', 'Match');
        $this->service->notify('alice', 'automation', 'Title', 'Auto');
        $this->assertCount(0, $this->service->executedCommands);

        $this->service->notify('alice', 'error', 'Title', 'Something broke');
        $this->assertCount(1, $this->service->executedCommands);
    }

    // ---------------------------------------------------------------
    // Verbosity filtering — 'all'
    // ---------------------------------------------------------------

    public function testVerbosityAllSendsAllCategories(): void
    {
        $this->service->setVerbosity('all');

        $this->service->notify('alice', 'download_complete', 'T', 'M1');
        $this->service->notify('alice', 'rss_match', 'T', 'M2');
        $this->service->notify('alice', 'automation', 'T', 'M3');
        $this->service->notify('alice', 'error', 'T', 'M4');

        $this->assertCount(4, $this->service->executedCommands);
    }

    // ---------------------------------------------------------------
    // Command format (with binary available)
    // ---------------------------------------------------------------

    public function testNotifySendsCorrectCommandWhenBinaryAvailable(): void
    {
        $this->service->binaryAvailable = true;
        $this->service->notify('alice', 'download_complete', 'Done', 'Download complete: MyTorrent');

        $this->assertCount(1, $this->service->executedCommands);
        $command = $this->service->executedCommands[0];

        $this->assertStringContainsString('/usr/syno/bin/synodsmnotify', $command);
        $this->assertStringContainsString("'alice'", $command);
        $this->assertStringContainsString("'TransmissionManager'", $command);
        $this->assertStringContainsString('Download complete: MyTorrent', $command);
    }

    // ---------------------------------------------------------------
    // Fallback (binary not available)
    // ---------------------------------------------------------------

    public function testNotifyFallsBackToLogWhenBinaryUnavailable(): void
    {
        $this->service->binaryAvailable = false;
        $this->service->notify('bob', 'error', 'Error', 'Something failed');

        $this->assertCount(0, $this->service->executedCommands);
        $this->assertCount(1, $this->service->loggedMessages);
        $this->assertStringContainsString('@bob', $this->service->loggedMessages[0]);
        $this->assertStringContainsString('Something failed', $this->service->loggedMessages[0]);
    }

    // ---------------------------------------------------------------
    // Convenience methods
    // ---------------------------------------------------------------

    public function testNotifyDownloadComplete(): void
    {
        $this->service->notifyDownloadComplete('alice', 'Ubuntu 24.04');

        $this->assertCount(1, $this->service->executedCommands);
        $this->assertStringContainsString('Download complete: Ubuntu 24.04', $this->service->executedCommands[0]);
    }

    public function testNotifyRSSMatch(): void
    {
        $this->service->notifyRSSMatch('alice', 'Linux ISOs', 'Fedora 40');

        $this->assertCount(1, $this->service->executedCommands);
        $this->assertStringContainsString('RSS match in Linux ISOs: Fedora 40', $this->service->executedCommands[0]);
    }

    public function testNotifyAutomation(): void
    {
        $this->service->notifyAutomation('alice', 'Move Movies', 'BigBuckBunny.mkv');

        $this->assertCount(1, $this->service->executedCommands);
        $command = $this->service->executedCommands[0];
        $this->assertStringContainsString('Move Movies', $command);
        $this->assertStringContainsString('BigBuckBunny.mkv', $command);
    }

    public function testNotifyError(): void
    {
        $this->service->notifyError('alice', 'Disk full');

        $this->assertCount(1, $this->service->executedCommands);
        $this->assertStringContainsString('Error: Disk full', $this->service->executedCommands[0]);
    }

    // ---------------------------------------------------------------
    // Category filtering with convenience methods
    // ---------------------------------------------------------------

    public function testConvenienceMethodsRespectVerbosityErrors(): void
    {
        $this->service->setVerbosity('errors');

        $this->service->notifyDownloadComplete('alice', 'Torrent');
        $this->service->notifyRSSMatch('alice', 'Feed', 'Item');
        $this->service->notifyAutomation('alice', 'Rule', 'Torrent');
        $this->assertCount(0, $this->service->executedCommands);

        $this->service->notifyError('alice', 'Failed');
        $this->assertCount(1, $this->service->executedCommands);
    }

    public function testConvenienceMethodsRespectVerbosityNone(): void
    {
        $this->service->setVerbosity('none');

        $this->service->notifyDownloadComplete('alice', 'T');
        $this->service->notifyRSSMatch('alice', 'F', 'I');
        $this->service->notifyAutomation('alice', 'R', 'T');
        $this->service->notifyError('alice', 'E');

        $this->assertCount(0, $this->service->executedCommands);
        $this->assertCount(0, $this->service->loggedMessages);
    }

    // ---------------------------------------------------------------
    // Independent category counters
    // ---------------------------------------------------------------

    public function testDifferentCategoriesAreSentIndependently(): void
    {
        $this->service->notify('alice', 'download_complete', 'T', 'M1');
        $this->service->notify('alice', 'error', 'T', 'M2');
        $this->service->notify('bob', 'rss_match', 'T', 'M3');

        $this->assertCount(3, $this->service->executedCommands);
    }
}
