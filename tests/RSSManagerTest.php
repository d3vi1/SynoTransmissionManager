<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../webapi/src/TransmissionException.php';
require_once __DIR__ . '/../webapi/src/ConnectionException.php';
require_once __DIR__ . '/../webapi/src/AuthenticationException.php';
require_once __DIR__ . '/../webapi/src/TransmissionRPC.php';
require_once __DIR__ . '/../webapi/src/Database.php';
require_once __DIR__ . '/../webapi/src/TorrentManager.php';
require_once __DIR__ . '/../webapi/src/RSSManager.php';

/**
 * Testable RSSManager that stubs HTTP requests.
 */
class TestableRSSManager extends RSSManager
{
    /** @var string|null */
    public $httpResponse = null;

    /** @var \Exception|null */
    public $httpException = null;

    protected function httpGet(string $url): string
    {
        if ($this->httpException !== null) {
            throw $this->httpException;
        }
        return $this->httpResponse ?? '';
    }
}

class RSSManagerTest extends TestCase
{
    /** @var Database */
    private $db;

    /** @var TestableRSSManager */
    private $rss;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $rpc = new \Tests\Support\TestableTransmissionRPC();
        $rpc->response = [
            'torrent-added' => ['id' => 99, 'hashString' => 'abc123', 'name' => 'test']
        ];
        $manager = new TorrentManager($rpc, $this->db);
        $this->rss = new TestableRSSManager($this->db, $manager);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---------------------------------------------------------------
    // Feed CRUD
    // ---------------------------------------------------------------

    public function testAddAndListFeeds(): void
    {
        $id = $this->rss->addFeed('alice', 'Test Feed', 'https://example.com/rss');
        $this->assertGreaterThan(0, $id);

        $feeds = $this->rss->listFeeds('alice');
        $this->assertCount(1, $feeds);
        $this->assertSame('Test Feed', $feeds[0]['name']);
    }

    public function testAddFeedInvalidUrl(): void
    {
        $this->expectException(TransmissionException::class);
        $this->rss->addFeed('alice', 'Bad', 'not-a-url');
    }

    public function testAddFeedFtpUrl(): void
    {
        $this->expectException(TransmissionException::class);
        $this->rss->addFeed('alice', 'FTP', 'ftp://example.com/rss');
    }

    public function testUpdateFeed(): void
    {
        $id = $this->rss->addFeed('alice', 'Old Name', 'https://example.com/rss');
        $ok = $this->rss->updateFeed('alice', $id, ['name' => 'New Name']);
        $this->assertTrue($ok);

        $feeds = $this->rss->listFeeds('alice');
        $this->assertSame('New Name', $feeds[0]['name']);
    }

    public function testDeleteFeed(): void
    {
        $id = $this->rss->addFeed('alice', 'Test', 'https://example.com/rss');
        $ok = $this->rss->deleteFeed('alice', $id);
        $this->assertTrue($ok);

        $feeds = $this->rss->listFeeds('alice');
        $this->assertCount(0, $feeds);
    }

    public function testFeedIsolation(): void
    {
        $this->rss->addFeed('alice', 'Alice Feed', 'https://example.com/a');
        $this->rss->addFeed('bob', 'Bob Feed', 'https://example.com/b');

        $this->assertCount(1, $this->rss->listFeeds('alice'));
        $this->assertCount(1, $this->rss->listFeeds('bob'));
    }

    // ---------------------------------------------------------------
    // Filter CRUD
    // ---------------------------------------------------------------

    public function testAddAndListFilters(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');
        $filterId = $this->rss->addFilter('alice', $feedId, 'ubuntu', 'contains');
        $this->assertGreaterThan(0, $filterId);

        $filters = $this->rss->listFilters('alice', $feedId);
        $this->assertCount(1, $filters);
        $this->assertSame('ubuntu', $filters[0]['pattern']);
    }

    public function testFilterOwnershipCheck(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');

        $this->expectException(TransmissionException::class);
        $this->rss->listFilters('bob', $feedId);
    }

    public function testDeleteFilter(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');
        $filterId = $this->rss->addFilter('alice', $feedId, 'test');
        $ok = $this->rss->deleteFilter('alice', $feedId, $filterId);
        $this->assertTrue($ok);

        $this->assertCount(0, $this->rss->listFilters('alice', $feedId));
    }

    public function testInvalidMatchMode(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');

        $this->expectException(TransmissionException::class);
        $this->rss->addFilter('alice', $feedId, 'test', 'invalid');
    }

    // ---------------------------------------------------------------
    // Filter matching
    // ---------------------------------------------------------------

    public function testMatchesFilterContains(): void
    {
        $this->assertTrue($this->rss->matchesFilter('Ubuntu 24.04 LTS', 'ubuntu', 'contains'));
        $this->assertFalse($this->rss->matchesFilter('Fedora 40', 'ubuntu', 'contains'));
    }

    public function testMatchesFilterExact(): void
    {
        $this->assertTrue($this->rss->matchesFilter('ubuntu', 'ubuntu', 'exact'));
        $this->assertFalse($this->rss->matchesFilter('ubuntu 24.04', 'ubuntu', 'exact'));
    }

    public function testMatchesFilterRegex(): void
    {
        $this->assertTrue($this->rss->matchesFilter('Ubuntu 24.04 LTS', '/ubuntu.*lts/i', 'regex'));
        $this->assertFalse($this->rss->matchesFilter('Fedora 40', '/ubuntu.*lts/i', 'regex'));
    }

    public function testMatchesFilterExclude(): void
    {
        $this->assertTrue($this->rss->matchesFilter('Ubuntu 24.04', 'ubuntu', 'contains', null));
        $this->assertFalse($this->rss->matchesFilter('Ubuntu 24.04 Beta', 'ubuntu', 'contains', 'Beta'));
    }

    public function testFindMatchingFilter(): void
    {
        $filters = [
            ['pattern' => 'fedora', 'match_mode' => 'contains', 'exclude_pattern' => null],
            ['pattern' => 'ubuntu', 'match_mode' => 'contains', 'exclude_pattern' => null],
        ];

        $match = $this->rss->findMatchingFilter('Ubuntu 24.04', $filters);
        $this->assertNotNull($match);
        $this->assertSame('ubuntu', $match['pattern']);

        $noMatch = $this->rss->findMatchingFilter('Debian 12', $filters);
        $this->assertNull($noMatch);
    }

    // ---------------------------------------------------------------
    // Feed parsing
    // ---------------------------------------------------------------

    public function testParseRss2(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Test</title>
    <item>
      <title>Ubuntu 24.04</title>
      <link>https://example.com/ubuntu.torrent</link>
      <guid>guid-1</guid>
      <pubDate>Mon, 01 Jan 2024 00:00:00 GMT</pubDate>
    </item>
    <item>
      <title>Fedora 40</title>
      <enclosure url="https://example.com/fedora.torrent" type="application/x-bittorrent"/>
      <guid>guid-2</guid>
    </item>
  </channel>
</rss>
XML;

        $items = $this->rss->parseFeed($xml);
        $this->assertCount(2, $items);
        $this->assertSame('Ubuntu 24.04', $items[0]['title']);
        $this->assertSame('https://example.com/ubuntu.torrent', $items[0]['link']);
        $this->assertSame('guid-1', $items[0]['guid']);
        // Fedora uses enclosure
        $this->assertSame('https://example.com/fedora.torrent', $items[1]['link']);
    }

    public function testParseAtom(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Test</title>
  <entry>
    <title>Arch Linux</title>
    <link rel="alternate" href="https://example.com/arch.torrent"/>
    <id>atom-1</id>
    <updated>2024-01-01T00:00:00Z</updated>
  </entry>
</feed>
XML;

        $items = $this->rss->parseFeed($xml);
        $this->assertCount(1, $items);
        $this->assertSame('Arch Linux', $items[0]['title']);
        $this->assertSame('https://example.com/arch.torrent', $items[0]['link']);
        $this->assertSame('atom-1', $items[0]['guid']);
    }

    public function testParseInvalidXml(): void
    {
        $this->expectException(TransmissionException::class);
        $this->rss->parseFeed('not xml at all');
    }

    public function testParseUnsupportedFormat(): void
    {
        $this->expectException(TransmissionException::class);
        $this->rss->parseFeed('<?xml version="1.0"?><opml/>');
    }

    // ---------------------------------------------------------------
    // Feed fetching
    // ---------------------------------------------------------------

    public function testFetchFeedSuccess(): void
    {
        $this->rss->httpResponse = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Test Item</title>
      <link>https://example.com/test.torrent</link>
    </item>
  </channel>
</rss>
XML;

        $items = $this->rss->fetchFeed('https://example.com/feed');
        $this->assertCount(1, $items);
        $this->assertSame('Test Item', $items[0]['title']);
    }

    public function testFetchFeedEmpty(): void
    {
        $this->rss->httpResponse = '';
        $this->expectException(TransmissionException::class);
        $this->rss->fetchFeed('https://example.com/feed');
    }

    public function testFetchFeedHttpError(): void
    {
        $this->rss->httpException = new ConnectionException('HTTP 500');
        $this->expectException(ConnectionException::class);
        $this->rss->fetchFeed('https://example.com/feed');
    }

    // ---------------------------------------------------------------
    // History
    // ---------------------------------------------------------------

    public function testGetHistory(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');
        $this->db->addHistoryItem($feedId, 'guid-1');
        $this->db->addHistoryItem($feedId, 'guid-2');

        $history = $this->rss->getHistory('alice', $feedId);
        $this->assertCount(2, $history);
    }

    public function testHistoryOwnershipCheck(): void
    {
        $feedId = $this->rss->addFeed('alice', 'Feed', 'https://example.com/rss');
        $this->expectException(TransmissionException::class);
        $this->rss->getHistory('bob', $feedId);
    }

    // ---------------------------------------------------------------
    // Test filter API
    // ---------------------------------------------------------------

    public function testTestFilter(): void
    {
        $this->assertTrue($this->rss->testFilter('Ubuntu 24.04', 'ubuntu', 'contains'));
        $this->assertFalse($this->rss->testFilter('Fedora 40', 'ubuntu', 'contains'));
    }
}
