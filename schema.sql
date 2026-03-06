-- SynoTransmissionManager database schema

CREATE TABLE IF NOT EXISTS user_torrents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    torrent_id INTEGER NOT NULL,
    hash_string TEXT NOT NULL,
    added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user, torrent_id)
);

CREATE INDEX idx_user_torrents_user ON user_torrents(user);
CREATE INDEX idx_user_torrents_hash ON user_torrents(hash_string);

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

CREATE TABLE IF NOT EXISTS rss_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_id INTEGER NOT NULL,
    item_guid TEXT NOT NULL,
    downloaded_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(feed_id, item_guid),
    FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS automation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    name TEXT NOT NULL,
    is_enabled INTEGER DEFAULT 1,
    trigger_type TEXT NOT NULL,
    trigger_value TEXT,
    conditions TEXT, -- JSON
    actions TEXT, -- JSON
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP
);
