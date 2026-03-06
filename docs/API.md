# API Reference

All API endpoints are accessed through DSM's WebAPI proxy. Requests are authenticated by DSM before reaching the backend.

## General Request Format

```
POST /webapi/entry.cgi
Content-Type: application/x-www-form-urlencoded

api=SYNO.Transmission.Torrent&method=list&version=1
```

## General Response Format

**Success:**

```json
{
    "success": true,
    "data": { ... }
}
```

**Error:**

```json
{
    "success": false,
    "error": {
        "code": 400,
        "message": "Description of the error"
    }
}
```

### Common Error Codes

| Code | Meaning |
|------|---------|
| 400 | Bad request (missing or invalid parameters) |
| 401 | Not authenticated |
| 403 | Access denied (admin required, or torrent not owned by user) |
| 404 | Not found (unknown API, method, or torrent) |
| 429 | Rate limit exceeded |
| 500 | Internal error / Transmission error |
| 502 | Cannot reach Transmission daemon |

## Authentication

All endpoints require DSM authentication (`authLevel: 1`). DSM injects the authenticated username via the `HTTP_X_SYNO_USER` header. Endpoints marked **[admin only]** additionally require DSM administrator privileges.

## Rate Limits

| Action | Limit |
|--------|-------|
| `api_call` | 60 requests/minute per user |
| `torrent_add` | 10 requests/minute per user |
| `feed_add` | 5 requests/minute per user |
| `rule_add` | 5 requests/minute per user |

---

## SYNO.Transmission.Torrent

Torrent management. All methods enforce per-user isolation: users can only access torrents they added.

### `list`

List all torrents belonging to the authenticated user.

**Parameters:** None

**Response:**

```json
{
    "success": true,
    "data": {
        "torrents": [
            {
                "id": 1,
                "name": "example.iso",
                "status": 4,
                "totalSize": 734003200,
                "percentDone": 0.75,
                "rateDownload": 1048576,
                "rateUpload": 524288,
                "eta": 360,
                "uploadRatio": 1.5,
                "labels": ["linux"],
                "downloadDir": "/volume1/downloads"
            }
        ]
    }
}
```

**Auth:** Any authenticated user

---

### `get`

Get detailed information for a single torrent, including files, peers, and trackers.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | integer | Yes | Torrent ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "example.iso",
        "status": 4,
        "totalSize": 734003200,
        "percentDone": 0.75,
        "files": [ ... ],
        "peers": [ ... ],
        "trackers": [ ... ],
        "downloadDir": "/volume1/downloads",
        "hashString": "abc123...",
        "labels": ["linux"]
    }
}
```

**Auth:** Any authenticated user (must own the torrent)

---

### `add`

Add a torrent from a URL, magnet link, or `.torrent` file upload.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | Conditional | URL or magnet link (required if `torrent_file` not provided) |
| `torrent_file` | file | Conditional | Uploaded `.torrent` file (required if `url` not provided) |
| `download_dir` | string | No | Download directory (validated against allowed paths) |
| `paused` | boolean | No | Start in paused state (default: `false`) |
| `labels` | string | No | Comma-separated labels |

**Response:**

```json
{
    "success": true,
    "data": {
        "torrent-added": {
            "id": 5,
            "name": "example.iso",
            "hashString": "abc123..."
        }
    }
}
```

If the torrent already exists, the response contains `torrent-duplicate` instead of `torrent-added`.

**Auth:** Any authenticated user
**Rate limit:** `torrent_add` (10/min)

---

### `start`

Start one or more torrents.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `ids` | string | Yes | Comma-separated torrent IDs |

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Any authenticated user (must own all specified torrents)

---

### `stop`

Stop one or more torrents.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `ids` | string | Yes | Comma-separated torrent IDs |

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Any authenticated user (must own all specified torrents)

---

### `remove`

Remove one or more torrents, optionally deleting downloaded data.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `ids` | string | Yes | Comma-separated torrent IDs |
| `delete_data` | boolean | No | Also delete downloaded files (default: `false`) |

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Any authenticated user (must own all specified torrents)

---

### `set_files`

Set file priorities and wanted/unwanted status within a torrent.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | integer | Yes | Torrent ID |
| `file_indices` | string | Yes | Comma-separated file indices (0-based) |
| `priority` | string | No | `high`, `normal`, or `low` (default: `normal`) |
| `wanted` | boolean | No | Whether files are wanted (default: `true`) |

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Any authenticated user (must own the torrent)

---

### `set_labels`

Set labels on a torrent.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | integer | Yes | Torrent ID |
| `labels` | string | Yes | Comma-separated labels (empty string to clear) |

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Any authenticated user (must own the torrent)

---

## SYNO.Transmission.Settings

Transmission daemon settings management.

### `get`

Get current Transmission session settings.

**Parameters:** None

**Response:**

```json
{
    "success": true,
    "data": {
        "download-dir": "/volume1/downloads",
        "speed-limit-down": 1024,
        "speed-limit-down-enabled": false,
        "speed-limit-up": 512,
        "speed-limit-up-enabled": false,
        "alt-speed-enabled": false,
        "alt-speed-down": 256,
        "alt-speed-up": 128,
        "alt-speed-time-enabled": false,
        "seedRatioLimit": 2.0,
        "seedRatioLimited": false,
        "idle-seeding-limit": 30,
        "idle-seeding-limit-enabled": false,
        "encryption": "preferred",
        "dht-enabled": true,
        "pex-enabled": true,
        "lpd-enabled": true,
        "utp-enabled": true,
        "port-forwarding-enabled": true,
        "peer-port": 51413
    }
}
```

**Auth:** Any authenticated user

---

### `set`

Update Transmission session settings. **[admin only]**

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `settings` | string (JSON) | Yes | JSON object with settings key-value pairs |

Example `settings` value:

```json
{
    "speed-limit-down-enabled": true,
    "speed-limit-down": 2048,
    "download-dir": "/volume1/downloads"
}
```

**Response:**

```json
{
    "success": true,
    "data": {}
}
```

**Auth:** Admin only

---

### `test_connection`

Test connectivity to the Transmission daemon. **[admin only]**

**Parameters:** None

**Response:**

```json
{
    "success": true,
    "data": {
        "connected": true
    }
}
```

**Auth:** Admin only

---

## SYNO.Transmission.RSS

RSS feed and filter management. Feeds and filters are scoped to the authenticated user.

### `list_feeds`

List all RSS feeds for the authenticated user.

**Parameters:** None

**Response:**

```json
{
    "success": true,
    "data": {
        "feeds": [
            {
                "id": 1,
                "name": "My Feed",
                "url": "https://example.com/rss",
                "refresh_interval": 1800,
                "last_checked": "2025-01-15 12:00:00",
                "is_enabled": 1,
                "created_date": "2025-01-01 00:00:00"
            }
        ]
    }
}
```

**Auth:** Any authenticated user

---

### `add_feed`

Add a new RSS feed.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `name` | string | Yes | Display name for the feed |
| `url` | string | Yes | RSS feed URL |
| `refresh_interval` | integer | No | Refresh interval in seconds (default: 1800) |

**Response:**

```json
{
    "success": true,
    "data": {
        "id": 2
    }
}
```

**Auth:** Any authenticated user
**Rate limit:** `feed_add` (5/min)

---

### `update_feed`

Update an existing RSS feed.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |
| `data` | string (JSON) | Yes | JSON object with fields to update (`name`, `url`, `refresh_interval`, `is_enabled`) |

**Response:**

```json
{
    "success": true,
    "data": {
        "updated": true
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `delete_feed`

Delete an RSS feed and all its associated filters and history.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "deleted": true
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `test_feed`

Test-fetch an RSS feed URL and return its items without saving.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | Yes | RSS feed URL to test |

**Response:**

```json
{
    "success": true,
    "data": {
        "items": [
            {
                "title": "Example Item",
                "link": "https://example.com/item",
                "guid": "unique-id-123"
            }
        ]
    }
}
```

**Auth:** Any authenticated user

---

### `list_filters`

List all filters for a given feed.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "filters": [
            {
                "id": 1,
                "feed_id": 1,
                "pattern": "linux.*iso",
                "match_mode": "regex",
                "exclude_pattern": "beta",
                "download_path": "/volume1/downloads/linux",
                "labels": "linux,iso",
                "start_paused": 0
            }
        ]
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `add_filter`

Add a filter to an RSS feed.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |
| `pattern` | string | Yes | Match pattern |
| `match_mode` | string | No | `contains`, `regex`, or `exact` (default: `contains`) |
| `exclude_pattern` | string | No | Exclusion pattern |
| `download_path` | string | No | Override download directory |
| `labels` | string | No | Labels to apply to matched torrents |
| `start_paused` | boolean | No | Start matched torrents paused (default: `false`) |

**Response:**

```json
{
    "success": true,
    "data": {
        "id": 3
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `update_filter`

Update an existing filter.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |
| `filter_id` | integer | Yes | Filter ID |
| `data` | string (JSON) | Yes | JSON object with fields to update (`pattern`, `match_mode`, `exclude_pattern`, `download_path`, `labels`, `start_paused`) |

**Response:**

```json
{
    "success": true,
    "data": {
        "updated": true
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `delete_filter`

Delete a filter from a feed.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |
| `filter_id` | integer | Yes | Filter ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "deleted": true
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

### `test_filter`

Test a filter pattern against a sample title without saving anything.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `title` | string | Yes | Sample title to test against |
| `pattern` | string | Yes | Match pattern to test |
| `match_mode` | string | No | `contains`, `regex`, or `exact` (default: `contains`) |
| `exclude_pattern` | string | No | Exclusion pattern to test |

**Response:**

```json
{
    "success": true,
    "data": {
        "matches": true
    }
}
```

**Auth:** Any authenticated user

---

### `get_history`

Get download history for a feed (items previously matched and downloaded by filters).

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `feed_id` | integer | Yes | Feed ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "history": [
            {
                "id": 1,
                "feed_id": 1,
                "item_guid": "unique-id-123",
                "downloaded_date": "2025-01-15 14:30:00"
            }
        ]
    }
}
```

**Auth:** Any authenticated user (must own the feed)

---

## SYNO.Transmission.Automation

Automation rules with trigger-condition-action patterns. Rules are scoped to the authenticated user.

### `list_rules`

List all automation rules for the authenticated user.

**Parameters:** None

**Response:**

```json
{
    "success": true,
    "data": {
        "rules": [
            {
                "id": 1,
                "name": "Move completed to archive",
                "is_enabled": 1,
                "trigger_type": "on_complete",
                "trigger_value": null,
                "conditions": [
                    {"field": "label", "op": "contains", "value": "archive"}
                ],
                "actions": [
                    {"type": "move", "path": "/volume1/archive"}
                ],
                "created_date": "2025-01-01 00:00:00"
            }
        ]
    }
}
```

**Auth:** Any authenticated user

---

### `add_rule`

Add a new automation rule.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `name` | string | Yes | Rule name |
| `trigger_type` | string | Yes | Trigger type: `on_complete`, `on_add`, `on_ratio`, `schedule` |
| `trigger_value` | string | No | Trigger-specific value (e.g., ratio threshold for `on_ratio`, cron expression for `schedule`) |
| `conditions` | string (JSON) | No | JSON array of condition objects |
| `actions` | string (JSON) | No | JSON array of action objects |

**Condition object format:**

```json
{
    "field": "label",
    "op": "contains",
    "value": "movies"
}
```

**Action object format:**

```json
{
    "type": "move",
    "path": "/volume1/movies"
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "id": 2
    }
}
```

**Auth:** Any authenticated user
**Rate limit:** `rule_add` (5/min)

---

### `update_rule`

Update an existing automation rule.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `rule_id` | integer | Yes | Rule ID |
| `data` | string (JSON) | Yes | JSON object with fields to update (`name`, `is_enabled`, `trigger_type`, `trigger_value`, `conditions`, `actions`) |

**Response:**

```json
{
    "success": true,
    "data": {
        "updated": true
    }
}
```

**Auth:** Any authenticated user (must own the rule)

---

### `delete_rule`

Delete an automation rule.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `rule_id` | integer | Yes | Rule ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "deleted": true
    }
}
```

**Auth:** Any authenticated user (must own the rule)

---

### `test_rule`

Dry-run a rule against current torrents to see which would match.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `rule_id` | integer | Yes | Rule ID |

**Response:**

```json
{
    "success": true,
    "data": {
        "matches": [
            {
                "id": 3,
                "name": "example-movie.mkv"
            }
        ]
    }
}
```

**Auth:** Any authenticated user (must own the rule)
