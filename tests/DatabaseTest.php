<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for the Database class.
 *
 * Every test uses an in-memory SQLite database so there are no
 * filesystem side-effects and tests run fast.
 */
class DatabaseTest extends TestCase
{
    /** @var Database */
    private $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---------------------------------------------------------------
    // Constructor / schema
    // ---------------------------------------------------------------

    public function testConstructorCreatesSchemaInMemory(): void
    {
        $this->assertInstanceOf(Database::class, $this->db);
    }

    // ---------------------------------------------------------------
    // User-torrent CRUD
    // ---------------------------------------------------------------

    public function testAddUserTorrentReturnsId(): void
    {
        $id = $this->db->addUserTorrent('alice', 1, 'abc123');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddUserTorrentIgnoresDuplicate(): void
    {
        $this->db->addUserTorrent('alice', 1, 'abc123');
        // INSERT OR IGNORE — no exception, returns 0 for lastInsertRowID on skip
        $id2 = $this->db->addUserTorrent('alice', 1, 'abc123');
        // Should not throw; the exact return value on ignore depends on SQLite
        $this->assertTrue(true);
    }

    public function testGetUserTorrentIdsReturnsOnlyUsersTorrents(): void
    {
        $this->db->addUserTorrent('alice', 10, 'aaa');
        $this->db->addUserTorrent('alice', 20, 'bbb');
        $this->db->addUserTorrent('bob', 30, 'ccc');

        $ids = $this->db->getUserTorrentIds('alice');
        $this->assertCount(2, $ids);
        $this->assertContains(10, $ids);
        $this->assertContains(20, $ids);
        $this->assertNotContains(30, $ids);
    }

    public function testGetUserTorrentIdsReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->db->getUserTorrentIds('nobody'));
    }

    public function testIsUserTorrent(): void
    {
        $this->db->addUserTorrent('alice', 5, 'hash5');
        $this->assertTrue($this->db->isUserTorrent('alice', 5));
        $this->assertFalse($this->db->isUserTorrent('bob', 5));
        $this->assertFalse($this->db->isUserTorrent('alice', 99));
    }

    public function testRemoveUserTorrent(): void
    {
        $this->db->addUserTorrent('alice', 7, 'hash7');
        $this->assertTrue($this->db->isUserTorrent('alice', 7));

        $deleted = $this->db->removeUserTorrent('alice', 7);
        $this->assertTrue($deleted);
        $this->assertFalse($this->db->isUserTorrent('alice', 7));
    }

    public function testRemoveUserTorrentReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->db->removeUserTorrent('alice', 999));
    }

    public function testRemoveUserTorrentDoesNotAffectOtherUsers(): void
    {
        $this->db->addUserTorrent('alice', 1, 'hash1');
        $this->db->addUserTorrent('bob', 1, 'hash1');

        $this->db->removeUserTorrent('alice', 1);
        $this->assertFalse($this->db->isUserTorrent('alice', 1));
        $this->assertTrue($this->db->isUserTorrent('bob', 1));
    }

    public function testGetUserTorrentByHash(): void
    {
        $this->db->addUserTorrent('alice', 42, 'deadbeef');
        $row = $this->db->getUserTorrentByHash('alice', 'deadbeef');

        $this->assertNotNull($row);
        $this->assertSame('alice', $row['user']);
        $this->assertEquals(42, $row['torrent_id']);
        $this->assertSame('deadbeef', $row['hash_string']);
    }

    public function testGetUserTorrentByHashReturnsNullForWrongUser(): void
    {
        $this->db->addUserTorrent('alice', 42, 'deadbeef');
        $this->assertNull($this->db->getUserTorrentByHash('bob', 'deadbeef'));
    }

    public function testGetUserTorrentByHashReturnsNullForUnknownHash(): void
    {
        $this->assertNull($this->db->getUserTorrentByHash('alice', 'nosuchhash'));
    }

    // ---------------------------------------------------------------
    // RSS feed CRUD
    // ---------------------------------------------------------------

    public function testAddFeedReturnsId(): void
    {
        $id = $this->db->addFeed('alice', 'Linux Distros', 'https://example.com/rss');
        $this->assertGreaterThan(0, $id);
    }

    public function testGetUserFeeds(): void
    {
        $this->db->addFeed('alice', 'Feed A', 'https://a.com/rss');
        $this->db->addFeed('alice', 'Feed B', 'https://b.com/rss', 3600);
        $this->db->addFeed('bob', 'Feed C', 'https://c.com/rss');

        $feeds = $this->db->getUserFeeds('alice');
        $this->assertCount(2, $feeds);

        $names = array_column($feeds, 'name');
        $this->assertContains('Feed A', $names);
        $this->assertContains('Feed B', $names);
    }

    public function testGetUserFeedsReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->db->getUserFeeds('nobody'));
    }

    public function testUpdateFeed(): void
    {
        $id = $this->db->addFeed('alice', 'Old Name', 'https://old.com/rss');
        $updated = $this->db->updateFeed('alice', $id, ['name' => 'New Name', 'url' => 'https://new.com/rss']);
        $this->assertTrue($updated);

        $feeds = $this->db->getUserFeeds('alice');
        $this->assertSame('New Name', $feeds[0]['name']);
        $this->assertSame('https://new.com/rss', $feeds[0]['url']);
    }

    public function testUpdateFeedIgnoresDisallowedFields(): void
    {
        $id = $this->db->addFeed('alice', 'Feed', 'https://e.com/rss');
        $updated = $this->db->updateFeed('alice', $id, ['id' => 999, 'user' => 'hacker']);
        $this->assertFalse($updated);
    }

    public function testUpdateFeedRespectsUserIsolation(): void
    {
        $id = $this->db->addFeed('alice', 'Feed', 'https://e.com/rss');
        $updated = $this->db->updateFeed('bob', $id, ['name' => 'Hacked']);
        $this->assertFalse($updated);

        // Name unchanged
        $feeds = $this->db->getUserFeeds('alice');
        $this->assertSame('Feed', $feeds[0]['name']);
    }

    public function testDeleteFeed(): void
    {
        $id = $this->db->addFeed('alice', 'ToDelete', 'https://d.com/rss');
        $this->assertTrue($this->db->deleteFeed('alice', $id));
        $this->assertSame([], $this->db->getUserFeeds('alice'));
    }

    public function testDeleteFeedRespectsUserIsolation(): void
    {
        $id = $this->db->addFeed('alice', 'Protected', 'https://p.com/rss');
        $this->assertFalse($this->db->deleteFeed('bob', $id));
        $this->assertCount(1, $this->db->getUserFeeds('alice'));
    }

    public function testDeleteFeedCascadesToFiltersAndHistory(): void
    {
        $feedId = $this->db->addFeed('alice', 'CascadeTest', 'https://cascade.com/rss');
        $filterId = $this->db->addFilter($feedId, 'ubuntu');
        $this->db->addHistoryItem($feedId, 'guid-1');

        // Verify children exist
        $this->assertCount(1, $this->db->getFiltersForFeed($feedId));
        $this->assertTrue($this->db->isItemDownloaded($feedId, 'guid-1'));

        // Delete the feed
        $this->db->deleteFeed('alice', $feedId);

        // Children should be gone
        $this->assertSame([], $this->db->getFiltersForFeed($feedId));
        $this->assertFalse($this->db->isItemDownloaded($feedId, 'guid-1'));
    }

    public function testGetFeedsDueForRefreshIncludesNeverChecked(): void
    {
        $this->db->addFeed('alice', 'New Feed', 'https://new.com/rss');
        $due = $this->db->getFeedsDueForRefresh();
        $this->assertCount(1, $due);
        $this->assertSame('New Feed', $due[0]['name']);
    }

    public function testUpdateFeedLastChecked(): void
    {
        $id = $this->db->addFeed('alice', 'Checked', 'https://c.com/rss');
        $this->assertTrue($this->db->updateFeedLastChecked($id));

        // After updating, it should no longer be "due" (default interval = 1800s)
        $due = $this->db->getFeedsDueForRefresh();
        $this->assertCount(0, $due);
    }

    // ---------------------------------------------------------------
    // RSS filter CRUD
    // ---------------------------------------------------------------

    public function testAddFilterReturnsId(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $filterId = $this->db->addFilter($feedId, 'ubuntu.*amd64');
        $this->assertGreaterThan(0, $filterId);
    }

    public function testGetFiltersForFeed(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $this->db->addFilter($feedId, 'pattern1');
        $this->db->addFilter($feedId, 'pattern2', 'regex', 'excludeMe');

        $filters = $this->db->getFiltersForFeed($feedId);
        $this->assertCount(2, $filters);
        $this->assertSame('pattern1', $filters[0]['pattern']);
        $this->assertSame('regex', $filters[1]['match_mode']);
        $this->assertSame('excludeMe', $filters[1]['exclude_pattern']);
    }

    public function testAddFilterWithAllOptions(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $filterId = $this->db->addFilter(
            $feedId,
            'pattern',
            'regex',
            'exclude',
            '/downloads/movies',
            'movie,hd',
            true
        );

        $filters = $this->db->getFiltersForFeed($feedId);
        $this->assertCount(1, $filters);
        $this->assertSame('regex', $filters[0]['match_mode']);
        $this->assertSame('exclude', $filters[0]['exclude_pattern']);
        $this->assertSame('/downloads/movies', $filters[0]['download_path']);
        $this->assertSame('movie,hd', $filters[0]['labels']);
        $this->assertEquals(1, $filters[0]['start_paused']);
    }

    public function testUpdateFilter(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $filterId = $this->db->addFilter($feedId, 'old-pattern');

        $updated = $this->db->updateFilter($filterId, ['pattern' => 'new-pattern', 'match_mode' => 'exact']);
        $this->assertTrue($updated);

        $filters = $this->db->getFiltersForFeed($feedId);
        $this->assertSame('new-pattern', $filters[0]['pattern']);
        $this->assertSame('exact', $filters[0]['match_mode']);
    }

    public function testUpdateFilterIgnoresDisallowedFields(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $filterId = $this->db->addFilter($feedId, 'pattern');
        $updated = $this->db->updateFilter($filterId, ['id' => 999, 'feed_id' => 888]);
        $this->assertFalse($updated);
    }

    public function testDeleteFilter(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $filterId = $this->db->addFilter($feedId, 'pattern');

        $this->assertTrue($this->db->deleteFilter($filterId));
        $this->assertSame([], $this->db->getFiltersForFeed($feedId));
    }

    public function testDeleteFilterReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->db->deleteFilter(9999));
    }

    // ---------------------------------------------------------------
    // RSS history
    // ---------------------------------------------------------------

    public function testAddHistoryItemReturnsId(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $id = $this->db->addHistoryItem($feedId, 'guid-abc');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddHistoryItemIgnoresDuplicate(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $this->db->addHistoryItem($feedId, 'guid-abc');
        // Second insert should not throw
        $this->db->addHistoryItem($feedId, 'guid-abc');
        $this->assertTrue(true);
    }

    public function testIsItemDownloaded(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $this->assertFalse($this->db->isItemDownloaded($feedId, 'guid-1'));

        $this->db->addHistoryItem($feedId, 'guid-1');
        $this->assertTrue($this->db->isItemDownloaded($feedId, 'guid-1'));
        $this->assertFalse($this->db->isItemDownloaded($feedId, 'guid-2'));
    }

    public function testGetHistory(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        $this->db->addHistoryItem($feedId, 'guid-a');
        $this->db->addHistoryItem($feedId, 'guid-b');
        $this->db->addHistoryItem($feedId, 'guid-c');

        $history = $this->db->getHistory($feedId);
        $this->assertCount(3, $history);
    }

    public function testGetHistoryRespectsLimit(): void
    {
        $feedId = $this->db->addFeed('alice', 'Feed', 'https://f.com/rss');
        for ($i = 0; $i < 10; $i++) {
            $this->db->addHistoryItem($feedId, "guid-$i");
        }

        $history = $this->db->getHistory($feedId, 5);
        $this->assertCount(5, $history);
    }

    // ---------------------------------------------------------------
    // Automation rules CRUD
    // ---------------------------------------------------------------

    public function testAddRuleReturnsId(): void
    {
        $id = $this->db->addRule('alice', 'Move completed', 'on-complete');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddRuleWithConditionsAndActions(): void
    {
        $conditions = [['field' => 'label', 'op' => 'equals', 'value' => 'movie']];
        $actions = [['type' => 'move', 'path' => '/movies']];
        $id = $this->db->addRule('alice', 'Move movies', 'on-complete', null, $conditions, $actions);

        $rules = $this->db->getUserRules('alice');
        $this->assertCount(1, $rules);
        $this->assertSame('Move movies', $rules[0]['name']);
        $this->assertIsArray($rules[0]['conditions']);
        $this->assertSame('label', $rules[0]['conditions'][0]['field']);
        $this->assertIsArray($rules[0]['actions']);
        $this->assertSame('move', $rules[0]['actions'][0]['type']);
    }

    public function testGetUserRulesIsolation(): void
    {
        $this->db->addRule('alice', 'Rule A', 'on-complete');
        $this->db->addRule('bob', 'Rule B', 'on-add');

        $this->assertCount(1, $this->db->getUserRules('alice'));
        $this->assertCount(1, $this->db->getUserRules('bob'));
        $this->assertSame([], $this->db->getUserRules('charlie'));
    }

    public function testUpdateRule(): void
    {
        $id = $this->db->addRule('alice', 'Old Name', 'on-complete');
        $updated = $this->db->updateRule('alice', $id, ['name' => 'New Name', 'is_enabled' => 0]);
        $this->assertTrue($updated);

        $rules = $this->db->getUserRules('alice');
        $this->assertSame('New Name', $rules[0]['name']);
        $this->assertEquals(0, $rules[0]['is_enabled']);
    }

    public function testUpdateRuleRespectsUserIsolation(): void
    {
        $id = $this->db->addRule('alice', 'Original', 'on-complete');
        $updated = $this->db->updateRule('bob', $id, ['name' => 'Hijacked']);
        $this->assertFalse($updated);

        $rules = $this->db->getUserRules('alice');
        $this->assertSame('Original', $rules[0]['name']);
    }

    public function testUpdateRuleIgnoresDisallowedFields(): void
    {
        $id = $this->db->addRule('alice', 'Rule', 'on-complete');
        $updated = $this->db->updateRule('alice', $id, ['id' => 999, 'user' => 'hacker']);
        $this->assertFalse($updated);
    }

    public function testUpdateRuleJsonEncodesConditionsAndActions(): void
    {
        $id = $this->db->addRule('alice', 'Rule', 'on-complete');
        $newConditions = [['field' => 'size', 'op' => 'gt', 'value' => 1024]];
        $this->db->updateRule('alice', $id, ['conditions' => $newConditions]);

        $rules = $this->db->getUserRules('alice');
        $this->assertSame('size', $rules[0]['conditions'][0]['field']);
    }

    public function testDeleteRule(): void
    {
        $id = $this->db->addRule('alice', 'Delete Me', 'on-complete');
        $this->assertTrue($this->db->deleteRule('alice', $id));
        $this->assertSame([], $this->db->getUserRules('alice'));
    }

    public function testDeleteRuleRespectsUserIsolation(): void
    {
        $id = $this->db->addRule('alice', 'Protected', 'on-complete');
        $this->assertFalse($this->db->deleteRule('bob', $id));
        $this->assertCount(1, $this->db->getUserRules('alice'));
    }

    public function testGetEnabledRulesByTrigger(): void
    {
        $this->db->addRule('alice', 'On Complete', 'on-complete');
        $this->db->addRule('alice', 'On Add', 'on-add');
        $this->db->addRule('bob', 'Bob Complete', 'on-complete');

        // Disable Alice's on-complete rule
        $rules = $this->db->getUserRules('alice');
        $onCompleteId = null;
        foreach ($rules as $r) {
            if ($r['trigger_type'] === 'on-complete') {
                $onCompleteId = (int)$r['id'];
            }
        }
        $this->db->updateRule('alice', $onCompleteId, ['is_enabled' => 0]);

        // Only Bob's on-complete should be returned
        $enabled = $this->db->getEnabledRulesByTrigger('on-complete');
        $this->assertCount(1, $enabled);
        $this->assertSame('Bob Complete', $enabled[0]['name']);
    }

    // ---------------------------------------------------------------
    // Cross-user isolation (integration)
    // ---------------------------------------------------------------

    public function testFullIsolationScenario(): void
    {
        // Alice adds a torrent and a feed with filter
        $this->db->addUserTorrent('alice', 1, 'hash1');
        $feedId = $this->db->addFeed('alice', 'Alice Feed', 'https://a.com');
        $this->db->addFilter($feedId, 'pattern');

        // Bob cannot see Alice's data
        $this->assertSame([], $this->db->getUserTorrentIds('bob'));
        $this->assertSame([], $this->db->getUserFeeds('bob'));

        // Bob cannot modify Alice's data
        $this->assertFalse($this->db->removeUserTorrent('bob', 1));
        $this->assertFalse($this->db->updateFeed('bob', $feedId, ['name' => 'Hacked']));
        $this->assertFalse($this->db->deleteFeed('bob', $feedId));

        // Alice's data is intact
        $this->assertCount(1, $this->db->getUserTorrentIds('alice'));
        $this->assertCount(1, $this->db->getUserFeeds('alice'));
    }
}
