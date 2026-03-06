<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for TorrentManager.
 *
 * Uses TestableTransmissionRPC (from TransmissionRPCTest.php) and
 * an in-memory Database to test the orchestration layer without
 * network calls.
 */
class TorrentManagerTest extends TestCase
{
    /** @var TestableTransmissionRPC */
    private $rpc;

    /** @var Database */
    private $db;

    /** @var TorrentManager */
    private $manager;

    protected function setUp(): void
    {
        $this->rpc = new TestableTransmissionRPC();
        $this->db = new Database(':memory:');
        $this->manager = new TorrentManager($this->rpc, $this->db, ['/volume1/', '/volume2/']);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Seed a user-torrent into the database + set up an RPC response.
     */
    private function seedTorrent(string $user, int $id, string $hash = 'abc123'): void
    {
        $this->db->addUserTorrent($user, $id, $hash);
    }

    // ---------------------------------------------------------------
    // listTorrents
    // ---------------------------------------------------------------

    public function testListTorrentsReturnsEmptyWhenUserHasNone(): void
    {
        $result = $this->manager->listTorrents('alice');
        $this->assertSame([], $result);
        // Should NOT call RPC at all
        $this->assertCount(0, $this->rpc->calls);
    }

    public function testListTorrentsReturnsOnlyUserTorrents(): void
    {
        $this->seedTorrent('alice', 1);
        $this->seedTorrent('alice', 2);
        $this->seedTorrent('bob', 3);

        $this->rpc->response = ['torrents' => [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ]];

        $result = $this->manager->listTorrents('alice');
        $this->assertCount(2, $result);

        // Verify only Alice's IDs were sent to RPC
        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-get', $call['method']);
        $sentIds = $call['arguments']['ids'];
        $this->assertContains(1, $sentIds);
        $this->assertContains(2, $sentIds);
        $this->assertNotContains(3, $sentIds);
    }

    // ---------------------------------------------------------------
    // getTorrent
    // ---------------------------------------------------------------

    public function testGetTorrentReturnsDataForOwnedTorrent(): void
    {
        $this->seedTorrent('alice', 42);
        $this->rpc->response = ['torrents' => [['id' => 42, 'name' => 'Test']]];

        $result = $this->manager->getTorrent('alice', 42);
        $this->assertNotNull($result);
        $this->assertSame(42, $result['id']);
    }

    public function testGetTorrentReturnsNullForUnownedTorrent(): void
    {
        $this->seedTorrent('alice', 42);
        $result = $this->manager->getTorrent('bob', 42);
        $this->assertNull($result);
        // Should NOT call RPC
        $this->assertCount(0, $this->rpc->calls);
    }

    public function testGetTorrentReturnsNullForNonexistent(): void
    {
        $result = $this->manager->getTorrent('alice', 999);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // addTorrentUrl
    // ---------------------------------------------------------------

    public function testAddTorrentUrlRegistersInDatabase(): void
    {
        $this->rpc->response = ['torrent-added' => ['id' => 10, 'hashString' => 'xyz', 'name' => 'New']];

        $result = $this->manager->addTorrentUrl('alice', 'magnet:?xt=urn:btih:xyz');

        $this->assertTrue($this->db->isUserTorrent('alice', 10));
        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-add', $call['method']);
        $this->assertSame('magnet:?xt=urn:btih:xyz', $call['arguments']['filename']);
    }

    public function testAddTorrentUrlHandlesDuplicate(): void
    {
        $this->rpc->response = ['torrent-duplicate' => ['id' => 5, 'hashString' => 'dup', 'name' => 'Dup']];

        $this->manager->addTorrentUrl('alice', 'magnet:?xt=urn:btih:dup');
        $this->assertTrue($this->db->isUserTorrent('alice', 5));
    }

    public function testAddTorrentUrlWithValidDownloadDir(): void
    {
        $this->rpc->response = ['torrent-added' => ['id' => 1, 'hashString' => 'h', 'name' => 'T']];

        $this->manager->addTorrentUrl('alice', 'magnet:?xt=urn:btih:h', '/volume1/Downloads');

        $call = $this->rpc->lastCall();
        $this->assertSame('/volume1/Downloads', $call['arguments']['download-dir']);
    }

    public function testAddTorrentUrlRejectsInvalidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->addTorrentUrl('alice', 'magnet:?xt=urn:btih:x', '/etc/evil');
    }

    public function testAddTorrentUrlWithPausedAndLabels(): void
    {
        $this->rpc->response = ['torrent-added' => ['id' => 1, 'hashString' => 'h', 'name' => 'T']];

        $this->manager->addTorrentUrl('alice', 'magnet:?xt=urn:btih:h', null, true, ['linux']);

        $call = $this->rpc->lastCall();
        $this->assertTrue($call['arguments']['paused']);
        $this->assertSame(['linux'], $call['arguments']['labels']);
    }

    // ---------------------------------------------------------------
    // addTorrentFile
    // ---------------------------------------------------------------

    public function testAddTorrentFileRegistersInDatabase(): void
    {
        $this->rpc->response = ['torrent-added' => ['id' => 20, 'hashString' => 'fh', 'name' => 'File']];

        $this->manager->addTorrentFile('alice', 'raw-torrent-bytes');
        $this->assertTrue($this->db->isUserTorrent('alice', 20));

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-add', $call['method']);
        $this->assertSame(base64_encode('raw-torrent-bytes'), $call['arguments']['metainfo']);
    }

    public function testAddTorrentFileRejectsInvalidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->addTorrentFile('alice', 'data', '/tmp/evil');
    }

    // ---------------------------------------------------------------
    // startTorrents
    // ---------------------------------------------------------------

    public function testStartTorrentsWithOwnership(): void
    {
        $this->seedTorrent('alice', 1);
        $this->seedTorrent('alice', 2);

        $this->manager->startTorrents('alice', [1, 2]);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-start', $call['method']);
        $this->assertSame([1, 2], $call['arguments']['ids']);
    }

    public function testStartTorrentsThrowsForUnownedId(): void
    {
        $this->seedTorrent('alice', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access denied');
        $this->manager->startTorrents('alice', [1, 999]);
    }

    // ---------------------------------------------------------------
    // stopTorrents
    // ---------------------------------------------------------------

    public function testStopTorrentsWithOwnership(): void
    {
        $this->seedTorrent('alice', 5);
        $this->manager->stopTorrents('alice', [5]);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-stop', $call['method']);
    }

    public function testStopTorrentsThrowsForUnownedId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->stopTorrents('alice', [999]);
    }

    // ---------------------------------------------------------------
    // removeTorrents
    // ---------------------------------------------------------------

    public function testRemoveTorrentsRemovesFromDbAfterRpc(): void
    {
        $this->seedTorrent('alice', 10);
        $this->assertTrue($this->db->isUserTorrent('alice', 10));

        $this->manager->removeTorrents('alice', [10]);

        // Should be removed from DB
        $this->assertFalse($this->db->isUserTorrent('alice', 10));

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-remove', $call['method']);
        $this->assertFalse($call['arguments']['delete-local-data']);
    }

    public function testRemoveTorrentsWithDeleteData(): void
    {
        $this->seedTorrent('alice', 11);
        $this->manager->removeTorrents('alice', [11], true);

        $call = $this->rpc->lastCall();
        $this->assertTrue($call['arguments']['delete-local-data']);
    }

    public function testRemoveTorrentsThrowsForUnownedId(): void
    {
        $this->seedTorrent('alice', 1);

        $this->expectException(\RuntimeException::class);
        $this->manager->removeTorrents('bob', [1]);
    }

    // ---------------------------------------------------------------
    // setTorrentFiles
    // ---------------------------------------------------------------

    public function testSetTorrentFilesWithOwnership(): void
    {
        $this->seedTorrent('alice', 7);
        $this->manager->setTorrentFiles('alice', 7, [0, 1], 'high', true);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-set', $call['method']);
        $this->assertSame([0, 1], $call['arguments']['priority-high']);
    }

    public function testSetTorrentFilesThrowsForUnowned(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->setTorrentFiles('alice', 999, [0]);
    }

    // ---------------------------------------------------------------
    // setTorrentLabels
    // ---------------------------------------------------------------

    public function testSetTorrentLabelsWithOwnership(): void
    {
        $this->seedTorrent('alice', 8);
        $this->manager->setTorrentLabels('alice', 8, ['movies', 'hd']);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-set', $call['method']);
        $this->assertSame(['movies', 'hd'], $call['arguments']['labels']);
    }

    public function testSetTorrentLabelsThrowsForUnowned(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->setTorrentLabels('bob', 8, ['x']);
    }

    // ---------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------

    public function testGetSettings(): void
    {
        $this->rpc->response = ['version' => '4.0.5', 'download-dir' => '/downloads'];
        $result = $this->manager->getSettings();
        $this->assertSame('4.0.5', $result['version']);
    }

    public function testSetSettings(): void
    {
        $this->manager->setSettings(['speed-limit-down' => 1024]);
        $call = $this->rpc->lastCall();
        $this->assertSame('session-set', $call['method']);
        $this->assertSame(1024, $call['arguments']['speed-limit-down']);
    }

    public function testTestConnection(): void
    {
        $this->rpc->response = ['version' => '4.0.5'];
        $this->assertTrue($this->manager->testConnection());
    }

    public function testTestConnectionReturnsFalseOnFailure(): void
    {
        $this->rpc->exception = new ConnectionException('timeout');
        $this->assertFalse($this->manager->testConnection());
    }

    // ---------------------------------------------------------------
    // Path validation
    // ---------------------------------------------------------------

    public function testValidatePathAcceptsAllowedPaths(): void
    {
        // Should not throw
        $this->manager->validatePath('/volume1/Downloads');
        $this->manager->validatePath('/volume1/media/movies');
        $this->manager->validatePath('/volume2/data');
        $this->assertTrue(true); // If we got here, no exception
    }

    public function testValidatePathRejectsDisallowedPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->validatePath('/etc/passwd');
    }

    public function testValidatePathBlocksTraversalAttack(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->validatePath('/volume1/Downloads/../../etc/passwd');
    }

    public function testValidatePathBlocksTraversalToRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->validatePath('/volume1/../../../tmp');
    }

    public function testValidatePathResolvesDotsCorrectly(): void
    {
        // /volume1/./Downloads resolves to /volume1/Downloads — valid
        $this->manager->validatePath('/volume1/./Downloads');
        $this->assertTrue(true);
    }

    public function testValidatePathRejectsRelativeTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // This resolves to /etc, which is not allowed
        $this->manager->validatePath('/volume1/../../etc');
    }

    // ---------------------------------------------------------------
    // Cross-user isolation integration
    // ---------------------------------------------------------------

    public function testCrossUserIsolation(): void
    {
        $this->seedTorrent('alice', 1);
        $this->seedTorrent('bob', 2);

        // Alice can't see/touch Bob's torrents
        $this->assertNull($this->manager->getTorrent('alice', 2));

        $this->expectException(\RuntimeException::class);
        $this->manager->startTorrents('alice', [2]);
    }

    public function testRemoveDoesNotAffectOtherUserDb(): void
    {
        $this->seedTorrent('alice', 1);
        $this->seedTorrent('bob', 2);

        $this->manager->removeTorrents('alice', [1]);

        $this->assertFalse($this->db->isUserTorrent('alice', 1));
        $this->assertTrue($this->db->isUserTorrent('bob', 2));
    }
}
