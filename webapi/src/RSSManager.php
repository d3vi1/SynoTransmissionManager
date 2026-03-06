<?php

declare(strict_types=1);

/**
 * RSS feed engine for SynoTransmissionManager.
 *
 * Fetches and parses RSS 2.0 and Atom feeds, matches items against user
 * filters, deduplicates via download history, and triggers torrent adds
 * through TorrentManager.
 */
class RSSManager
{
    /** @var Database */
    private $db;

    /** @var TorrentManager */
    private $torrentManager;

    /** @var int HTTP timeout in seconds */
    private $httpTimeout;

    /**
     * @param Database       $db
     * @param TorrentManager $torrentManager
     * @param int            $httpTimeout HTTP request timeout
     */
    public function __construct(Database $db, TorrentManager $torrentManager, int $httpTimeout = 30)
    {
        $this->db = $db;
        $this->torrentManager = $torrentManager;
        $this->httpTimeout = $httpTimeout;
    }

    // ---------------------------------------------------------------
    // Feed CRUD (delegated to Database with ownership)
    // ---------------------------------------------------------------

    /**
     * List feeds for a user.
     *
     * @param string $user DSM username
     * @return array[]
     */
    public function listFeeds(string $user): array
    {
        return $this->db->getUserFeeds($user);
    }

    /**
     * Add a new feed.
     *
     * @param string $user            DSM username
     * @param string $name            Feed name
     * @param string $url             Feed URL
     * @param int    $refreshInterval Refresh interval in seconds
     * @return int Feed ID
     */
    public function addFeed(string $user, string $name, string $url, int $refreshInterval = 1800): int
    {
        $this->validateUrl($url);
        return $this->db->addFeed($user, $name, $url, $refreshInterval);
    }

    /**
     * Update a feed.
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @param array  $data   Fields to update
     * @return bool
     */
    public function updateFeed(string $user, int $feedId, array $data): bool
    {
        if (isset($data['url'])) {
            $this->validateUrl($data['url']);
        }
        return $this->db->updateFeed($user, $feedId, $data);
    }

    /**
     * Delete a feed and all associated filters/history.
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @return bool
     */
    public function deleteFeed(string $user, int $feedId): bool
    {
        return $this->db->deleteFeed($user, $feedId);
    }

    // ---------------------------------------------------------------
    // Filter CRUD
    // ---------------------------------------------------------------

    /**
     * List filters for a feed (verifies feed ownership).
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @return array[]
     */
    public function listFilters(string $user, int $feedId): array
    {
        $this->verifyFeedOwnership($user, $feedId);
        return $this->db->getFiltersForFeed($feedId);
    }

    /**
     * Add a filter to a feed.
     *
     * @param string      $user           DSM username
     * @param int         $feedId         Feed ID
     * @param string      $pattern        Match pattern
     * @param string      $matchMode      'contains', 'regex', 'exact'
     * @param string|null $excludePattern Exclusion pattern
     * @param string|null $downloadPath   Download directory
     * @param string|null $labels         Comma-separated labels
     * @param bool        $startPaused    Start torrent paused
     * @return int Filter ID
     */
    public function addFilter(
        string $user,
        int $feedId,
        string $pattern,
        string $matchMode = 'contains',
        ?string $excludePattern = null,
        ?string $downloadPath = null,
        ?string $labels = null,
        bool $startPaused = false
    ): int {
        $this->verifyFeedOwnership($user, $feedId);
        $this->validateMatchMode($matchMode);
        if ($matchMode === 'regex') {
            $this->validateRegex($pattern);
        }
        return $this->db->addFilter($feedId, $pattern, $matchMode, $excludePattern, $downloadPath, $labels, $startPaused);
    }

    /**
     * Update a filter.
     *
     * @param string $user     DSM username
     * @param int    $feedId   Feed ID
     * @param int    $filterId Filter ID
     * @param array  $data     Fields to update
     * @return bool
     */
    public function updateFilter(string $user, int $feedId, int $filterId, array $data): bool
    {
        $this->verifyFeedOwnership($user, $feedId);
        if (isset($data['match_mode'])) {
            $this->validateMatchMode($data['match_mode']);
        }
        if (isset($data['pattern']) && ($data['match_mode'] ?? null) === 'regex') {
            $this->validateRegex($data['pattern']);
        }
        return $this->db->updateFilter($filterId, $data);
    }

    /**
     * Delete a filter.
     *
     * @param string $user     DSM username
     * @param int    $feedId   Feed ID
     * @param int    $filterId Filter ID
     * @return bool
     */
    public function deleteFilter(string $user, int $feedId, int $filterId): bool
    {
        $this->verifyFeedOwnership($user, $feedId);
        return $this->db->deleteFilter($filterId);
    }

    // ---------------------------------------------------------------
    // History
    // ---------------------------------------------------------------

    /**
     * Get download history for a feed.
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @param int    $limit  Max items
     * @return array[]
     */
    public function getHistory(string $user, int $feedId, int $limit = 100): array
    {
        $this->verifyFeedOwnership($user, $feedId);
        return $this->db->getHistory($feedId, $limit);
    }

    // ---------------------------------------------------------------
    // Feed fetching and parsing
    // ---------------------------------------------------------------

    /**
     * Fetch and parse an RSS/Atom feed URL.
     *
     * @param string $url Feed URL
     * @return array[] Parsed items [{title, link, guid, pubDate}]
     * @throws TransmissionException on fetch or parse failure
     */
    public function fetchFeed(string $url): array
    {
        $this->validateUrl($url);
        $content = $this->httpGet($url);

        if (empty($content)) {
            throw new TransmissionException('Empty response from feed URL');
        }

        return $this->parseFeed($content);
    }

    /**
     * Test a feed URL — fetch and return items without saving.
     *
     * @param string $url Feed URL
     * @return array[] Parsed items
     */
    public function testFeed(string $url): array
    {
        return $this->fetchFeed($url);
    }

    /**
     * Test a filter pattern against a title string.
     *
     * @param string $title       Item title to test
     * @param string $pattern     Filter pattern
     * @param string $matchMode   'contains', 'regex', 'exact'
     * @param string|null $excludePattern Exclusion pattern
     * @return bool True if the title matches
     */
    public function testFilter(string $title, string $pattern, string $matchMode = 'contains', ?string $excludePattern = null): bool
    {
        return $this->matchesFilter($title, $pattern, $matchMode, $excludePattern);
    }

    // ---------------------------------------------------------------
    // Processing pipeline
    // ---------------------------------------------------------------

    /**
     * Process all feeds due for refresh.
     *
     * For each feed: fetch items, dedupe, match filters, add torrents.
     *
     * @return array Processing results [{feedId, feedName, added, skipped, errors}]
     */
    public function processAllFeeds(): array
    {
        $results = [];
        $feeds = $this->db->getFeedsDueForRefresh();

        foreach ($feeds as $feed) {
            $results[] = $this->processFeed($feed);
        }

        return $results;
    }

    /**
     * Process a single feed: fetch, dedupe, match, add.
     *
     * @param array $feed Feed row from database
     * @return array {feedId, feedName, added, skipped, errors}
     */
    public function processFeed(array $feed): array
    {
        $result = [
            'feedId' => (int)$feed['id'],
            'feedName' => $feed['name'],
            'added' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $items = $this->fetchFeed($feed['url']);
            $filters = $this->db->getFiltersForFeed((int)$feed['id']);

            foreach ($items as $item) {
                $guid = $item['guid'] ?? $item['link'] ?? $item['title'];

                // Skip already-downloaded items
                if ($this->db->isItemDownloaded((int)$feed['id'], $guid)) {
                    $result['skipped']++;
                    continue;
                }

                // Check against filters
                $matchedFilter = $this->findMatchingFilter($item['title'] ?? '', $filters);
                if ($matchedFilter === null) {
                    continue;
                }

                // Add torrent
                try {
                    $torrentUrl = $item['link'] ?? '';
                    if (empty($torrentUrl)) {
                        continue;
                    }

                    $this->torrentManager->addTorrentUrl(
                        $feed['user'],
                        $torrentUrl,
                        $matchedFilter['download_path'] ?? null,
                        (bool)($matchedFilter['start_paused'] ?? false),
                        $matchedFilter['labels'] ?? null
                    );

                    $this->db->addHistoryItem((int)$feed['id'], $guid);
                    $result['added']++;
                } catch (\Exception $e) {
                    $result['errors'][] = 'Failed to add "' . ($item['title'] ?? 'unknown') . '": ' . $e->getMessage();
                }
            }

            $this->db->updateFeedLastChecked((int)$feed['id']);
        } catch (\Exception $e) {
            $result['errors'][] = 'Feed fetch failed: ' . $e->getMessage();
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Filter matching
    // ---------------------------------------------------------------

    /**
     * Find the first filter that matches an item title.
     *
     * @param string  $title   Item title
     * @param array[] $filters Filter rows
     * @return array|null Matching filter or null
     */
    public function findMatchingFilter(string $title, array $filters): ?array
    {
        foreach ($filters as $filter) {
            if ($this->matchesFilter($title, $filter['pattern'], $filter['match_mode'], $filter['exclude_pattern'] ?? null)) {
                return $filter;
            }
        }
        return null;
    }

    /**
     * Check if a title matches a filter pattern.
     *
     * @param string      $title          Item title
     * @param string      $pattern        Filter pattern
     * @param string      $matchMode      'contains', 'regex', 'exact'
     * @param string|null $excludePattern Exclusion pattern
     * @return bool
     */
    public function matchesFilter(string $title, string $pattern, string $matchMode, ?string $excludePattern = null): bool
    {
        $matches = false;

        switch ($matchMode) {
            case 'contains':
                $matches = stripos($title, $pattern) !== false;
                break;
            case 'exact':
                $matches = strcasecmp($title, $pattern) === 0;
                break;
            case 'regex':
                $matches = (bool)@preg_match($pattern, $title);
                break;
            default:
                $matches = false;
        }

        // Check exclusion
        if ($matches && $excludePattern !== null && $excludePattern !== '') {
            if (stripos($title, $excludePattern) !== false) {
                $matches = false;
            }
        }

        return $matches;
    }

    // ---------------------------------------------------------------
    // Feed parsing (RSS 2.0 + Atom)
    // ---------------------------------------------------------------

    /**
     * Parse RSS 2.0 or Atom XML content into a normalised item array.
     *
     * @param string $xml Raw XML content
     * @return array[] [{title, link, guid, pubDate}]
     * @throws TransmissionException on parse failure
     */
    public function parseFeed(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = !empty($errors) ? $errors[0]->message : 'Unknown parse error';
            throw new TransmissionException('Failed to parse feed XML: ' . trim($msg));
        }

        // Detect format
        $rootName = $doc->getName();
        if ($rootName === 'rss') {
            return $this->parseRss2($doc);
        }
        if ($rootName === 'feed') {
            return $this->parseAtom($doc);
        }

        throw new TransmissionException('Unsupported feed format: ' . $rootName);
    }

    /**
     * Parse RSS 2.0 format.
     */
    private function parseRss2(\SimpleXMLElement $rss): array
    {
        $items = [];
        if (!isset($rss->channel->item)) {
            return $items;
        }

        foreach ($rss->channel->item as $item) {
            $link = (string)($item->link ?? '');
            // Check for enclosure URL (common in torrent RSS feeds)
            if (empty($link) && isset($item->enclosure['url'])) {
                $link = (string)$item->enclosure['url'];
            }

            $items[] = [
                'title' => (string)($item->title ?? ''),
                'link' => $link,
                'guid' => (string)($item->guid ?? $link),
                'pubDate' => (string)($item->pubDate ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Parse Atom format.
     */
    private function parseAtom(\SimpleXMLElement $feed): array
    {
        $items = [];

        foreach ($feed->entry as $entry) {
            $link = '';
            // Atom can have multiple <link> elements
            foreach ($entry->link as $l) {
                $rel = (string)($l['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $rel === 'enclosure') {
                    $link = (string)($l['href'] ?? '');
                    if ($rel === 'enclosure') {
                        break; // Prefer enclosure
                    }
                }
            }
            if (empty($link) && isset($entry->link[0])) {
                $link = (string)($entry->link[0]['href'] ?? '');
            }

            $items[] = [
                'title' => (string)($entry->title ?? ''),
                'link' => $link,
                'guid' => (string)($entry->id ?? $link),
                'pubDate' => (string)($entry->updated ?? $entry->published ?? ''),
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------

    /**
     * Fetch URL content via cURL.
     *
     * @param string $url URL to fetch
     * @return string Response body
     * @throws ConnectionException on fetch failure
     */
    protected function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'SynoTransmissionManager/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new ConnectionException('HTTP request failed: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new ConnectionException('HTTP ' . $httpCode . ' from feed URL');
        }

        return (string)$response;
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    /**
     * Validate a URL.
     *
     * @param string $url URL to validate
     * @throws TransmissionException on invalid URL
     */
    private function validateUrl(string $url): void
    {
        if (empty($url)) {
            throw new TransmissionException('Feed URL cannot be empty');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TransmissionException('Invalid feed URL: ' . $url);
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new TransmissionException('Feed URL must use HTTP or HTTPS');
        }
    }

    /**
     * Validate a match mode.
     *
     * @param string $matchMode Match mode to validate
     * @throws TransmissionException on invalid mode
     */
    private function validateMatchMode(string $matchMode): void
    {
        if (!in_array($matchMode, ['contains', 'regex', 'exact'], true)) {
            throw new TransmissionException('Invalid match mode: ' . $matchMode);
        }
    }

    /**
     * Validate a regex pattern.
     *
     * @param string $pattern Regex pattern
     * @throws TransmissionException on invalid regex
     */
    private function validateRegex(string $pattern): void
    {
        if (@preg_match($pattern, '') === false) {
            throw new TransmissionException('Invalid regex pattern: ' . $pattern);
        }
    }

    /**
     * Verify that a feed belongs to a user.
     *
     * @param string $user   DSM username
     * @param int    $feedId Feed ID
     * @throws TransmissionException if feed not found or not owned by user
     */
    private function verifyFeedOwnership(string $user, int $feedId): void
    {
        $feeds = $this->db->getUserFeeds($user);
        foreach ($feeds as $feed) {
            if ((int)$feed['id'] === $feedId) {
                return;
            }
        }
        throw new TransmissionException('Feed not found or not owned by user');
    }
}
