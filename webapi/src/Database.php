<?php

declare(strict_types=1);

/**
 * SQLite database layer for SynoTransmissionManager.
 *
 * Manages user-torrent associations, RSS feeds/filters/history, and
 * automation rules. All operations use prepared statements and enforce
 * multi-user isolation (every query is scoped to the authenticated DSM user).
 *
 * The database is initialised with WAL journal mode and foreign-key
 * enforcement on first connection.
 */
class Database
{
    /** @var \SQLite3 */
    private $db;

    /**
     * @param string $dbPath Path to SQLite database file, or ':memory:'
     */
    public function __construct(string $dbPath = '/var/packages/TransmissionManager/var/transmission.db')
    {
        $this->db = new \SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $this->initialise();
    }

    // ---------------------------------------------------------------
    // Schema initialisation
    // ---------------------------------------------------------------

    /**
     * Enable WAL mode, foreign keys, and create schema if absent.
     */
    private function initialise(): void
    {
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->createSchema();
    }

    /**
     * Create all tables and indices if they do not exist.
     */
    private function createSchema(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_torrents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    torrent_id INTEGER NOT NULL,
    hash_string TEXT NOT NULL,
    added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user, torrent_id)
);
SQL
        );

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_user_torrents_user ON user_torrents(user)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_user_torrents_hash ON user_torrents(hash_string)');

        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rss_feeds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    refresh_interval INTEGER DEFAULT 1800,
    last_checked DATETIME,
    is_enabled INTEGER DEFAULT 1,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL
        );

        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rss_filters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_id INTEGER NOT NULL,
    pattern TEXT NOT NULL,
    match_mode TEXT DEFAULT 'contains',
    exclude_pattern TEXT,
    download_path TEXT,
    labels TEXT,
    start_paused INTEGER DEFAULT 0,
    FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE
);
SQL
        );

        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rss_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_id INTEGER NOT NULL,
    item_guid TEXT NOT NULL,
    downloaded_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(feed_id, item_guid),
    FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE
);
SQL
        );

        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS automation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    name TEXT NOT NULL,
    is_enabled INTEGER DEFAULT 1,
    trigger_type TEXT NOT NULL,
    trigger_value TEXT,
    conditions TEXT,
    actions TEXT,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL
        );
    }

    // ---------------------------------------------------------------
    // User-torrent CRUD
    // ---------------------------------------------------------------

    /**
     * Associate a torrent with a user.
     *
     * @param string $user       DSM username
     * @param int    $torrentId  Transmission torrent ID
     * @param string $hashString Torrent info-hash
     * @return int Inserted row ID
     */
    public function addUserTorrent(string $user, int $torrentId, string $hashString): int
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO user_torrents (user, torrent_id, hash_string) VALUES (:user, :tid, :hash)'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':tid', $torrentId, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $hashString, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    /**
     * Remove a user-torrent association.
     *
     * @param string $user      DSM username
     * @param int    $torrentId Transmission torrent ID
     * @return bool True if a row was deleted
     */
    public function removeUserTorrent(string $user, int $torrentId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_torrents WHERE user = :user AND torrent_id = :tid'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':tid', $torrentId, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Get all torrent IDs belonging to a user.
     *
     * @param string $user DSM username
     * @return int[] Torrent IDs
     */
    public function getUserTorrentIds(string $user): array
    {
        $stmt = $this->db->prepare(
            'SELECT torrent_id FROM user_torrents WHERE user = :user ORDER BY added_date DESC'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $result = $stmt->execute();

        $ids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = (int)$row['torrent_id'];
        }
        return $ids;
    }

    /**
     * Check if a torrent belongs to a user.
     *
     * @param string $user      DSM username
     * @param int    $torrentId Transmission torrent ID
     * @return bool
     */
    public function isUserTorrent(string $user, int $torrentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_torrents WHERE user = :user AND torrent_id = :tid LIMIT 1'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':tid', $torrentId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        return $result->fetchArray() !== false;
    }

    /**
     * Find a user-torrent association by hash.
     *
     * @param string $user       DSM username
     * @param string $hashString Torrent info-hash
     * @return array|null Row data or null
     */
    public function getUserTorrentByHash(string $user, string $hashString): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_torrents WHERE user = :user AND hash_string = :hash LIMIT 1'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hashString, SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row !== false ? $row : null;
    }

    // ---------------------------------------------------------------
    // RSS feed CRUD
    // ---------------------------------------------------------------

    /**
     * Add an RSS feed for a user.
     *
     * @param string $user            DSM username
     * @param string $name            Feed display name
     * @param string $url             Feed URL
     * @param int    $refreshInterval Refresh interval in seconds
     * @return int Inserted row ID
     */
    public function addFeed(string $user, string $name, string $url, int $refreshInterval = 1800): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rss_feeds (user, name, url, refresh_interval) VALUES (:user, :name, :url, :interval)'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt->bindValue(':interval', $refreshInterval, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    /**
     * Update an RSS feed (only if owned by the user).
     *
     * @param string $user DSM username
     * @param int    $feedId Feed ID
     * @param array  $data Key-value pairs to update (name, url, refresh_interval, is_enabled)
     * @return bool True if a row was updated
     */
    public function updateFeed(string $user, int $feedId, array $data): bool
    {
        $allowed = ['name', 'url', 'refresh_interval', 'is_enabled'];
        $sets = [];
        $bindings = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "$key = :$key";
                $bindings[":$key"] = $value;
            }
        }
        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE rss_feeds SET ' . implode(', ', $sets) . ' WHERE id = :id AND user = :user';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        foreach ($bindings as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Delete an RSS feed (only if owned by the user). Cascades to filters and history.
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @return bool True if a row was deleted
     */
    public function deleteFeed(string $user, int $feedId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM rss_feeds WHERE id = :id AND user = :user');
        $stmt->bindValue(':id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Get all feeds for a user.
     *
     * @param string $user DSM username
     * @return array[] Feed rows
     */
    public function getUserFeeds(string $user): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rss_feeds WHERE user = :user ORDER BY created_date DESC'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $result = $stmt->execute();

        $feeds = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $feeds[] = $row;
        }
        return $feeds;
    }

    /**
     * Get feeds that are due for a refresh.
     *
     * @return array[] Feed rows whose last_checked + refresh_interval < now
     */
    public function getFeedsDueForRefresh(): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT * FROM rss_feeds
WHERE is_enabled = 1
  AND (last_checked IS NULL
       OR datetime(last_checked, '+' || refresh_interval || ' seconds') <= datetime('now'))
ORDER BY last_checked ASC
SQL
        );
        $result = $stmt->execute();

        $feeds = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $feeds[] = $row;
        }
        return $feeds;
    }

    /**
     * Update the last_checked timestamp for a feed.
     *
     * @param int $feedId Feed ID
     * @return bool True if a row was updated
     */
    public function updateFeedLastChecked(int $feedId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE rss_feeds SET last_checked = datetime('now') WHERE id = :id"
        );
        $stmt->bindValue(':id', $feedId, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    // ---------------------------------------------------------------
    // RSS filter CRUD
    // ---------------------------------------------------------------

    /**
     * Add a filter to an RSS feed.
     *
     * @param int         $feedId         Feed ID
     * @param string      $pattern        Match pattern
     * @param string      $matchMode      'contains', 'regex', 'exact'
     * @param string|null $excludePattern Exclusion pattern
     * @param string|null $downloadPath   Download directory
     * @param string|null $labels         Comma-separated labels
     * @param bool        $startPaused    Start torrent paused
     * @return int Inserted row ID
     */
    public function addFilter(
        int $feedId,
        string $pattern,
        string $matchMode = 'contains',
        ?string $excludePattern = null,
        ?string $downloadPath = null,
        ?string $labels = null,
        bool $startPaused = false
    ): int {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO rss_filters (feed_id, pattern, match_mode, exclude_pattern, download_path, labels, start_paused)
VALUES (:feed_id, :pattern, :match_mode, :exclude, :path, :labels, :paused)
SQL
        );
        $stmt->bindValue(':feed_id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':pattern', $pattern, SQLITE3_TEXT);
        $stmt->bindValue(':match_mode', $matchMode, SQLITE3_TEXT);
        $stmt->bindValue(':exclude', $excludePattern, $excludePattern === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':path', $downloadPath, $downloadPath === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':labels', $labels, $labels === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':paused', $startPaused ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    /**
     * Update a filter (caller must verify feed ownership).
     *
     * @param int   $filterId Filter ID
     * @param array $data     Key-value pairs to update
     * @return bool True if a row was updated
     */
    public function updateFilter(int $filterId, array $data): bool
    {
        $allowed = ['pattern', 'match_mode', 'exclude_pattern', 'download_path', 'labels', 'start_paused'];
        $sets = [];
        $bindings = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "$key = :$key";
                $bindings[":$key"] = $value;
            }
        }
        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE rss_filters SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $filterId, SQLITE3_INTEGER);
        foreach ($bindings as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Delete a filter.
     *
     * @param int $filterId Filter ID
     * @return bool True if a row was deleted
     */
    public function deleteFilter(int $filterId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM rss_filters WHERE id = :id');
        $stmt->bindValue(':id', $filterId, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Get all filters for a given feed.
     *
     * @param int $feedId Feed ID
     * @return array[] Filter rows
     */
    public function getFiltersForFeed(int $feedId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM rss_filters WHERE feed_id = :feed_id');
        $stmt->bindValue(':feed_id', $feedId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $filters = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $filters[] = $row;
        }
        return $filters;
    }

    // ---------------------------------------------------------------
    // RSS history
    // ---------------------------------------------------------------

    /**
     * Record a downloaded RSS item.
     *
     * @param int    $feedId   Feed ID
     * @param string $itemGuid Item GUID
     * @return int Inserted row ID
     */
    public function addHistoryItem(int $feedId, string $itemGuid): int
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO rss_history (feed_id, item_guid) VALUES (:feed_id, :guid)'
        );
        $stmt->bindValue(':feed_id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':guid', $itemGuid, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    /**
     * Check if an RSS item has already been downloaded.
     *
     * @param int    $feedId   Feed ID
     * @param string $itemGuid Item GUID
     * @return bool
     */
    public function isItemDownloaded(int $feedId, string $itemGuid): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM rss_history WHERE feed_id = :feed_id AND item_guid = :guid LIMIT 1'
        );
        $stmt->bindValue(':feed_id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':guid', $itemGuid, SQLITE3_TEXT);
        $result = $stmt->execute();

        return $result->fetchArray() !== false;
    }

    /**
     * Get download history for a feed.
     *
     * @param int $feedId Feed ID
     * @param int $limit  Max rows
     * @return array[] History rows
     */
    public function getHistory(int $feedId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rss_history WHERE feed_id = :feed_id ORDER BY downloaded_date DESC LIMIT :limit'
        );
        $stmt->bindValue(':feed_id', $feedId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $items = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }
        return $items;
    }

    // ---------------------------------------------------------------
    // Automation rules CRUD
    // ---------------------------------------------------------------

    /**
     * Add an automation rule for a user.
     *
     * @param string $user         DSM username
     * @param string $name         Rule display name
     * @param string $triggerType  'on-complete', 'on-add', 'schedule'
     * @param string|null $triggerValue Trigger-specific value (cron expr, etc.)
     * @param array  $conditions   Condition definitions
     * @param array  $actions      Action definitions
     * @return int Inserted row ID
     */
    public function addRule(
        string $user,
        string $name,
        string $triggerType,
        ?string $triggerValue = null,
        array $conditions = [],
        array $actions = []
    ): int {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO automation_rules (user, name, trigger_type, trigger_value, conditions, actions)
VALUES (:user, :name, :trigger, :trigger_val, :conditions, :actions)
SQL
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':trigger', $triggerType, SQLITE3_TEXT);
        $stmt->bindValue(':trigger_val', $triggerValue, $triggerValue === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':conditions', json_encode($conditions), SQLITE3_TEXT);
        $stmt->bindValue(':actions', json_encode($actions), SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    /**
     * Update an automation rule (only if owned by the user).
     *
     * @param string $user   DSM username
     * @param int    $ruleId Rule ID
     * @param array  $data   Key-value pairs to update
     * @return bool True if a row was updated
     */
    public function updateRule(string $user, int $ruleId, array $data): bool
    {
        $allowed = ['name', 'is_enabled', 'trigger_type', 'trigger_value', 'conditions', 'actions'];
        $sets = [];
        $bindings = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                // JSON-encode complex fields
                if (in_array($key, ['conditions', 'actions'], true) && is_array($value)) {
                    $value = json_encode($value);
                }
                $sets[] = "$key = :$key";
                $bindings[":$key"] = $value;
            }
        }
        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE automation_rules SET ' . implode(', ', $sets) . ' WHERE id = :id AND user = :user';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $ruleId, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        foreach ($bindings as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Delete an automation rule (only if owned by the user).
     *
     * @param string $user   DSM username
     * @param int    $ruleId Rule ID
     * @return bool True if a row was deleted
     */
    public function deleteRule(string $user, int $ruleId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM automation_rules WHERE id = :id AND user = :user');
        $stmt->bindValue(':id', $ruleId, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Get all automation rules for a user.
     *
     * @param string $user DSM username
     * @return array[] Rule rows with conditions/actions as decoded arrays
     */
    public function getUserRules(string $user): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM automation_rules WHERE user = :user ORDER BY created_date DESC'
        );
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $result = $stmt->execute();

        $rules = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['conditions'] = json_decode($row['conditions'] ?? '[]', true) ?: [];
            $row['actions'] = json_decode($row['actions'] ?? '[]', true) ?: [];
            $rules[] = $row;
        }
        return $rules;
    }

    /**
     * Get enabled rules matching a trigger type.
     *
     * @param string $triggerType Trigger type ('on-complete', 'on-add', 'schedule')
     * @return array[] Rule rows with conditions/actions decoded
     */
    public function getEnabledRulesByTrigger(string $triggerType): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM automation_rules WHERE is_enabled = 1 AND trigger_type = :trigger'
        );
        $stmt->bindValue(':trigger', $triggerType, SQLITE3_TEXT);
        $result = $stmt->execute();

        $rules = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['conditions'] = json_decode($row['conditions'] ?? '[]', true) ?: [];
            $row['actions'] = json_decode($row['actions'] ?? '[]', true) ?: [];
            $rules[] = $row;
        }
        return $rules;
    }

    // ---------------------------------------------------------------
    // Utility
    // ---------------------------------------------------------------

    /**
     * Close the database connection.
     */
    public function close(): void
    {
        $this->db->close();
    }
}
