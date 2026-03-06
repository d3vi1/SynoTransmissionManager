<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that captures RPC calls instead of making HTTP requests.
 *
 * Overrides the protected request() method to record the method name and
 * arguments, then returns a configurable response (or throws an exception).
 */
class TestableTransmissionRPC extends TransmissionRPC
{
    /** @var array[] Captured calls: [['method' => ..., 'arguments' => ...], ...] */
    public $calls = [];

    /** @var array|null Canned response to return from request() */
    public $response = [];

    /** @var \Exception|null If set, request() throws this instead of returning */
    public $exception = null;

    protected function request(string $method, array $arguments = []): array
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }

    /** Helper: return the last captured call. */
    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/**
 * Comprehensive unit tests for TransmissionRPC.
 */
class TransmissionRPCTest extends TestCase
{
    /** @var TestableTransmissionRPC */
    private $rpc;

    protected function setUp(): void
    {
        $this->rpc = new TestableTransmissionRPC('10.0.0.1', 9091, 'user', 'pass');
    }

    // ---------------------------------------------------------------
    // Constructor & configuration
    // ---------------------------------------------------------------

    public function testConstructorBuildsCorrectUrl(): void
    {
        $rpc = new TransmissionRPC('myhost', 8080);
        $this->assertSame('http://myhost:8080/transmission/rpc', $rpc->getUrl());
    }

    public function testConstructorDefaultUrl(): void
    {
        $rpc = new TransmissionRPC();
        $this->assertSame('http://localhost:9091/transmission/rpc', $rpc->getUrl());
    }

    public function testSetCredentials(): void
    {
        $rpc = new TestableTransmissionRPC();
        $rpc->response = ['version' => '4.0.5'];
        $rpc->setCredentials('admin', 's3cret');

        // Credentials aren't publicly accessible, but we verify no exception.
        $rpc->getSession();
        $this->assertCount(1, $rpc->calls);
    }

    public function testGetUrlReturnsConstructedUrl(): void
    {
        $rpc = new TransmissionRPC('192.168.1.100', 12345);
        $this->assertSame('http://192.168.1.100:12345/transmission/rpc', $rpc->getUrl());
    }

    // ---------------------------------------------------------------
    // getTorrents()
    // ---------------------------------------------------------------

    public function testGetTorrentsCallsCorrectMethod(): void
    {
        $this->rpc->response = ['torrents' => []];
        $this->rpc->getTorrents();

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-get', $call['method']);
    }

    public function testGetTorrentsUsesDefaultFields(): void
    {
        $this->rpc->response = ['torrents' => []];
        $this->rpc->getTorrents();

        $call = $this->rpc->lastCall();
        $fields = $call['arguments']['fields'];

        // Must include these essential fields
        $this->assertContains('id', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('status', $fields);
        $this->assertContains('percentDone', $fields);
        $this->assertContains('rateDownload', $fields);
        $this->assertContains('rateUpload', $fields);
        $this->assertContains('labels', $fields);
        $this->assertArrayNotHasKey('ids', $call['arguments']);
    }

    public function testGetTorrentsPassesIds(): void
    {
        $this->rpc->response = ['torrents' => []];
        $this->rpc->getTorrents([1, 5, 10]);

        $call = $this->rpc->lastCall();
        $this->assertSame([1, 5, 10], $call['arguments']['ids']);
    }

    public function testGetTorrentsPassesCustomFields(): void
    {
        $this->rpc->response = ['torrents' => []];
        $this->rpc->getTorrents(null, ['id', 'name']);

        $call = $this->rpc->lastCall();
        $this->assertSame(['id', 'name'], $call['arguments']['fields']);
    }

    public function testGetTorrentsReturnsTorrentsArray(): void
    {
        $torrents = [
            ['id' => 1, 'name' => 'Ubuntu ISO'],
            ['id' => 2, 'name' => 'Fedora ISO'],
        ];
        $this->rpc->response = ['torrents' => $torrents];

        $result = $this->rpc->getTorrents();
        $this->assertCount(2, $result);
        $this->assertSame('Ubuntu ISO', $result[0]['name']);
    }

    public function testGetTorrentsReturnsEmptyArrayWhenKeyMissing(): void
    {
        $this->rpc->response = [];
        $result = $this->rpc->getTorrents();
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // getTorrent()
    // ---------------------------------------------------------------

    public function testGetTorrentCallsWithSingleId(): void
    {
        $this->rpc->response = ['torrents' => [['id' => 42, 'name' => 'Test']]];
        $result = $this->rpc->getTorrent(42);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-get', $call['method']);
        $this->assertSame([42], $call['arguments']['ids']);
    }

    public function testGetTorrentUsesDetailFields(): void
    {
        $this->rpc->response = ['torrents' => [['id' => 1]]];
        $this->rpc->getTorrent(1);

        $call = $this->rpc->lastCall();
        $fields = $call['arguments']['fields'];

        // Detail fields include file-level info not in the list view
        $this->assertContains('files', $fields);
        $this->assertContains('fileStats', $fields);
        $this->assertContains('peers', $fields);
        $this->assertContains('trackers', $fields);
        $this->assertContains('trackerStats', $fields);
        $this->assertContains('downloadDir', $fields);
        $this->assertContains('comment', $fields);
    }

    public function testGetTorrentAcceptsCustomFields(): void
    {
        $this->rpc->response = ['torrents' => [['id' => 1]]];
        $this->rpc->getTorrent(1, ['id', 'status']);

        $call = $this->rpc->lastCall();
        $this->assertSame(['id', 'status'], $call['arguments']['fields']);
    }

    public function testGetTorrentReturnsSingleTorrent(): void
    {
        $torrent = ['id' => 7, 'name' => 'My Torrent'];
        $this->rpc->response = ['torrents' => [$torrent]];

        $result = $this->rpc->getTorrent(7);
        $this->assertSame($torrent, $result);
    }

    public function testGetTorrentReturnsNullWhenNotFound(): void
    {
        $this->rpc->response = ['torrents' => []];
        $result = $this->rpc->getTorrent(999);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // addTorrentFile()
    // ---------------------------------------------------------------

    public function testAddTorrentFileEncodesMetainfo(): void
    {
        $fileContent = 'raw-torrent-bytes-here';
        $this->rpc->addTorrentFile($fileContent);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-add', $call['method']);
        $this->assertSame(base64_encode($fileContent), $call['arguments']['metainfo']);
        $this->assertFalse($call['arguments']['paused']);
    }

    public function testAddTorrentFileWithDownloadDir(): void
    {
        $this->rpc->addTorrentFile('data', '/downloads/movies');

        $call = $this->rpc->lastCall();
        $this->assertSame('/downloads/movies', $call['arguments']['download-dir']);
    }

    public function testAddTorrentFileOmitsDownloadDirWhenNull(): void
    {
        $this->rpc->addTorrentFile('data');

        $call = $this->rpc->lastCall();
        $this->assertArrayNotHasKey('download-dir', $call['arguments']);
    }

    public function testAddTorrentFilePaused(): void
    {
        $this->rpc->addTorrentFile('data', null, true);

        $call = $this->rpc->lastCall();
        $this->assertTrue($call['arguments']['paused']);
    }

    public function testAddTorrentFileWithLabels(): void
    {
        $this->rpc->addTorrentFile('data', null, false, ['linux', 'iso']);

        $call = $this->rpc->lastCall();
        $this->assertSame(['linux', 'iso'], $call['arguments']['labels']);
    }

    public function testAddTorrentFileOmitsLabelsWhenEmpty(): void
    {
        $this->rpc->addTorrentFile('data');

        $call = $this->rpc->lastCall();
        $this->assertArrayNotHasKey('labels', $call['arguments']);
    }

    // ---------------------------------------------------------------
    // addTorrentUrl()
    // ---------------------------------------------------------------

    public function testAddTorrentUrlPassesFilename(): void
    {
        $url = 'magnet:?xt=urn:btih:abc123';
        $this->rpc->addTorrentUrl($url);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-add', $call['method']);
        $this->assertSame($url, $call['arguments']['filename']);
    }

    public function testAddTorrentUrlWithAllOptions(): void
    {
        $this->rpc->addTorrentUrl(
            'https://example.com/file.torrent',
            '/downloads',
            true,
            ['movies']
        );

        $call = $this->rpc->lastCall();
        $this->assertSame('/downloads', $call['arguments']['download-dir']);
        $this->assertTrue($call['arguments']['paused']);
        $this->assertSame(['movies'], $call['arguments']['labels']);
    }

    public function testAddTorrentUrlOmitsOptionalArgs(): void
    {
        $this->rpc->addTorrentUrl('magnet:?xt=urn:btih:xyz');

        $call = $this->rpc->lastCall();
        $this->assertArrayNotHasKey('download-dir', $call['arguments']);
        $this->assertArrayNotHasKey('labels', $call['arguments']);
        $this->assertFalse($call['arguments']['paused']);
    }

    // ---------------------------------------------------------------
    // startTorrents()
    // ---------------------------------------------------------------

    public function testStartTorrentsSendsCorrectPayload(): void
    {
        $this->rpc->startTorrents([1, 2, 3]);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-start', $call['method']);
        $this->assertSame([1, 2, 3], $call['arguments']['ids']);
    }

    public function testStartTorrentsSingleId(): void
    {
        $this->rpc->startTorrents([42]);

        $call = $this->rpc->lastCall();
        $this->assertSame([42], $call['arguments']['ids']);
    }

    // ---------------------------------------------------------------
    // stopTorrents()
    // ---------------------------------------------------------------

    public function testStopTorrentsSendsCorrectPayload(): void
    {
        $this->rpc->stopTorrents([5, 10]);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-stop', $call['method']);
        $this->assertSame([5, 10], $call['arguments']['ids']);
    }

    // ---------------------------------------------------------------
    // removeTorrents()
    // ---------------------------------------------------------------

    public function testRemoveTorrentsWithoutDeletion(): void
    {
        $this->rpc->removeTorrents([1, 2]);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-remove', $call['method']);
        $this->assertSame([1, 2], $call['arguments']['ids']);
        $this->assertFalse($call['arguments']['delete-local-data']);
    }

    public function testRemoveTorrentsWithDeletion(): void
    {
        $this->rpc->removeTorrents([3], true);

        $call = $this->rpc->lastCall();
        $this->assertTrue($call['arguments']['delete-local-data']);
    }

    // ---------------------------------------------------------------
    // setTorrentFiles()
    // ---------------------------------------------------------------

    public function testSetTorrentFilesHighPriority(): void
    {
        $this->rpc->setTorrentFiles(1, [0, 2], 'high', true);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-set', $call['method']);
        $this->assertSame([1], $call['arguments']['ids']);
        $this->assertSame([0, 2], $call['arguments']['priority-high']);
        $this->assertSame([0, 2], $call['arguments']['files-wanted']);
    }

    public function testSetTorrentFilesNormalPriority(): void
    {
        $this->rpc->setTorrentFiles(5, [1, 3], 'normal');

        $call = $this->rpc->lastCall();
        $this->assertSame([1, 3], $call['arguments']['priority-normal']);
    }

    public function testSetTorrentFilesLowPriority(): void
    {
        $this->rpc->setTorrentFiles(5, [4], 'low');

        $call = $this->rpc->lastCall();
        $this->assertSame([4], $call['arguments']['priority-low']);
    }

    public function testSetTorrentFilesUnwanted(): void
    {
        $this->rpc->setTorrentFiles(1, [0, 1], 'normal', false);

        $call = $this->rpc->lastCall();
        $this->assertSame([0, 1], $call['arguments']['files-unwanted']);
        $this->assertArrayNotHasKey('files-wanted', $call['arguments']);
    }

    public function testSetTorrentFilesInvalidPriorityIgnored(): void
    {
        $this->rpc->setTorrentFiles(1, [0], 'invalid');

        $call = $this->rpc->lastCall();
        $this->assertArrayNotHasKey('priority-high', $call['arguments']);
        $this->assertArrayNotHasKey('priority-normal', $call['arguments']);
        $this->assertArrayNotHasKey('priority-low', $call['arguments']);
    }

    // ---------------------------------------------------------------
    // setTorrentLabels()
    // ---------------------------------------------------------------

    public function testSetTorrentLabelsSendsCorrectPayload(): void
    {
        $this->rpc->setTorrentLabels(42, ['linux', 'iso']);

        $call = $this->rpc->lastCall();
        $this->assertSame('torrent-set', $call['method']);
        $this->assertSame([42], $call['arguments']['ids']);
        $this->assertSame(['linux', 'iso'], $call['arguments']['labels']);
    }

    public function testSetTorrentLabelsEmptyArray(): void
    {
        $this->rpc->setTorrentLabels(1, []);

        $call = $this->rpc->lastCall();
        $this->assertSame([], $call['arguments']['labels']);
    }

    // ---------------------------------------------------------------
    // getSession()
    // ---------------------------------------------------------------

    public function testGetSessionWithoutFields(): void
    {
        $this->rpc->response = ['version' => '4.0.5'];
        $this->rpc->getSession();

        $call = $this->rpc->lastCall();
        $this->assertSame('session-get', $call['method']);
        $this->assertSame([], $call['arguments']);
    }

    public function testGetSessionWithFields(): void
    {
        $this->rpc->response = ['version' => '4.0.5'];
        $this->rpc->getSession(['version', 'download-dir']);

        $call = $this->rpc->lastCall();
        $this->assertSame(['version', 'download-dir'], $call['arguments']['fields']);
    }

    // ---------------------------------------------------------------
    // setSession()
    // ---------------------------------------------------------------

    public function testSetSessionPassesSettings(): void
    {
        $settings = [
            'speed-limit-down' => 1024,
            'speed-limit-down-enabled' => true,
        ];
        $this->rpc->setSession($settings);

        $call = $this->rpc->lastCall();
        $this->assertSame('session-set', $call['method']);
        $this->assertSame($settings, $call['arguments']);
    }

    // ---------------------------------------------------------------
    // getSessionStats()
    // ---------------------------------------------------------------

    public function testGetSessionStatsCallsCorrectMethod(): void
    {
        $this->rpc->response = ['activeTorrentCount' => 5];
        $this->rpc->getSessionStats();

        $call = $this->rpc->lastCall();
        $this->assertSame('session-stats', $call['method']);
    }

    // ---------------------------------------------------------------
    // testConnection()
    // ---------------------------------------------------------------

    public function testTestConnectionReturnsTrueOnSuccess(): void
    {
        $this->rpc->response = ['version' => '4.0.5'];
        $this->assertTrue($this->rpc->testConnection());
    }

    public function testTestConnectionReturnsFalseOnConnectionException(): void
    {
        $this->rpc->exception = new ConnectionException('unreachable');
        $this->assertFalse($this->rpc->testConnection());
    }

    public function testTestConnectionReturnsFalseOnAuthException(): void
    {
        $this->rpc->exception = new AuthenticationException('bad creds');
        $this->assertFalse($this->rpc->testConnection());
    }

    public function testTestConnectionReturnsFalseOnTransmissionException(): void
    {
        $this->rpc->exception = new TransmissionException('rpc error');
        $this->assertFalse($this->rpc->testConnection());
    }

    public function testTestConnectionReturnsFalseOnGenericException(): void
    {
        $this->rpc->exception = new \RuntimeException('unexpected');
        $this->assertFalse($this->rpc->testConnection());
    }

    // ---------------------------------------------------------------
    // getFreeSpace()
    // ---------------------------------------------------------------

    public function testGetFreeSpacePassesPath(): void
    {
        $this->rpc->response = ['path' => '/downloads', 'size-bytes' => 1073741824];
        $this->rpc->getFreeSpace('/downloads');

        $call = $this->rpc->lastCall();
        $this->assertSame('free-space', $call['method']);
        $this->assertSame('/downloads', $call['arguments']['path']);
    }

    // ---------------------------------------------------------------
    // Exception hierarchy
    // ---------------------------------------------------------------

    public function testTransmissionExceptionExtendsRuntimeException(): void
    {
        $e = new TransmissionException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testConnectionExceptionExtendsTransmissionException(): void
    {
        $e = new ConnectionException('test');
        $this->assertInstanceOf(TransmissionException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testAuthenticationExceptionExtendsTransmissionException(): void
    {
        $e = new AuthenticationException('test');
        $this->assertInstanceOf(TransmissionException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // ---------------------------------------------------------------
    // Error propagation from request()
    // ---------------------------------------------------------------

    public function testGetTorrentsThrowsOnConnectionError(): void
    {
        $this->rpc->exception = new ConnectionException('timeout');
        $this->expectException(ConnectionException::class);
        $this->rpc->getTorrents();
    }

    public function testAddTorrentFileThrowsOnAuthError(): void
    {
        $this->rpc->exception = new AuthenticationException('401');
        $this->expectException(AuthenticationException::class);
        $this->rpc->addTorrentFile('data');
    }

    public function testRemoveTorrentsThrowsOnRpcError(): void
    {
        $this->rpc->exception = new TransmissionException('rpc error');
        $this->expectException(TransmissionException::class);
        $this->rpc->removeTorrents([1]);
    }

    // ---------------------------------------------------------------
    // Call counting / idempotency
    // ---------------------------------------------------------------

    public function testMultipleCallsAreRecorded(): void
    {
        $this->rpc->response = ['torrents' => []];
        $this->rpc->getTorrents();
        $this->rpc->getTorrents([1]);
        $this->rpc->startTorrents([1]);

        $this->assertCount(3, $this->rpc->calls);
        $this->assertSame('torrent-get', $this->rpc->calls[0]['method']);
        $this->assertSame('torrent-get', $this->rpc->calls[1]['method']);
        $this->assertSame('torrent-start', $this->rpc->calls[2]['method']);
    }
}
