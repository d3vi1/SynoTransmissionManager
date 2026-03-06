<?php

declare(strict_types=1);

/**
 * Transmission RPC Client.
 *
 * Communicates with the Transmission daemon via its JSON-RPC interface.
 * Handles CSRF session ID management (409 retry pattern) and HTTP Basic auth.
 *
 * @see https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md
 */
class TransmissionRPC
{
    /** @var string */
    private $url;

    /** @var string|null */
    private $sessionId;

    /** @var string|null */
    private $username;

    /** @var string|null */
    private $password;

    /** @var int Connection timeout in seconds */
    private $timeout;

    /**
     * @param string      $host     Transmission daemon host
     * @param int         $port     Transmission daemon RPC port
     * @param string|null $username RPC username (null if auth disabled)
     * @param string|null $password RPC password (null if auth disabled)
     * @param int         $timeout  Connection timeout in seconds
     */
    public function __construct(
        string $host = 'localhost',
        int $port = 9091,
        ?string $username = null,
        ?string $password = null,
        int $timeout = 10
    ) {
        $this->url = "http://{$host}:{$port}/transmission/rpc";
        $this->sessionId = null;
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    // ---------------------------------------------------------------
    // Torrent query methods
    // ---------------------------------------------------------------

    /**
     * Get a list of torrents with the specified fields.
     *
     * @param int[]|null    $ids    Torrent IDs (null = all)
     * @param string[]|null $fields Fields to return (null = default set)
     * @return array[] Array of torrent data arrays
     */
    public function getTorrents(?array $ids = null, ?array $fields = null): array
    {
        $defaultFields = [
            'id', 'hashString', 'name', 'status', 'error', 'errorString',
            'totalSize', 'percentDone', 'rateDownload', 'rateUpload',
            'uploadRatio', 'eta', 'peersConnected', 'labels',
            'uploadedEver', 'downloadedEver', 'addedDate', 'doneDate',
        ];

        $args = ['fields' => $fields ?? $defaultFields];
        if ($ids !== null) {
            $args['ids'] = $ids;
        }

        $result = $this->request('torrent-get', $args);
        return $result['torrents'] ?? [];
    }

    /**
     * Get a single torrent with full detail fields.
     *
     * @param int           $id     Torrent ID
     * @param string[]|null $fields Fields (null = full detail set)
     * @return array|null Torrent data or null if not found
     */
    public function getTorrent(int $id, ?array $fields = null): ?array
    {
        $detailFields = $fields ?? [
            'id', 'hashString', 'name', 'status', 'error', 'errorString',
            'totalSize', 'percentDone', 'rateDownload', 'rateUpload',
            'uploadRatio', 'eta', 'files', 'fileStats', 'peers',
            'trackers', 'trackerStats', 'priorities', 'wanted',
            'peersConnected', 'labels', 'uploadedEver', 'downloadedEver',
            'addedDate', 'doneDate', 'comment', 'creator', 'pieceCount',
            'pieceSize', 'downloadDir', 'isFinished', 'secondsSeeding',
            'secondsDownloading',
        ];

        $torrents = $this->getTorrents([$id], $detailFields);
        return $torrents[0] ?? null;
    }

    // ---------------------------------------------------------------
    // Torrent action methods
    // ---------------------------------------------------------------

    /**
     * Add a torrent from raw .torrent file content.
     *
     * @param string      $fileContent Raw .torrent bytes (will be base64-encoded)
     * @param string|null $downloadDir Download directory (null = daemon default)
     * @param bool        $paused      Start in paused state
     * @param string[]    $labels      Labels to assign
     * @return array Result with 'torrent-added' or 'torrent-duplicate' key
     */
    public function addTorrentFile(
        string $fileContent,
        ?string $downloadDir = null,
        bool $paused = false,
        array $labels = []
    ): array {
        $args = [
            'metainfo' => base64_encode($fileContent),
            'paused' => $paused,
        ];
        if ($downloadDir !== null) {
            $args['download-dir'] = $downloadDir;
        }
        if (!empty($labels)) {
            $args['labels'] = $labels;
        }

        return $this->request('torrent-add', $args);
    }

    /**
     * Add a torrent from a URL or magnet link.
     *
     * @param string      $url         URL or magnet link
     * @param string|null $downloadDir Download directory (null = daemon default)
     * @param bool        $paused      Start in paused state
     * @param string[]    $labels      Labels to assign
     * @return array Result with 'torrent-added' or 'torrent-duplicate' key
     */
    public function addTorrentUrl(
        string $url,
        ?string $downloadDir = null,
        bool $paused = false,
        array $labels = []
    ): array {
        $args = [
            'filename' => $url,
            'paused' => $paused,
        ];
        if ($downloadDir !== null) {
            $args['download-dir'] = $downloadDir;
        }
        if (!empty($labels)) {
            $args['labels'] = $labels;
        }

        return $this->request('torrent-add', $args);
    }

    /**
     * Start one or more torrents.
     *
     * @param int[] $ids Torrent IDs
     * @return array RPC result
     */
    public function startTorrents(array $ids): array
    {
        return $this->request('torrent-start', ['ids' => $ids]);
    }

    /**
     * Stop one or more torrents.
     *
     * @param int[] $ids Torrent IDs
     * @return array RPC result
     */
    public function stopTorrents(array $ids): array
    {
        return $this->request('torrent-stop', ['ids' => $ids]);
    }

    /**
     * Remove one or more torrents.
     *
     * @param int[] $ids        Torrent IDs
     * @param bool  $deleteData Also delete downloaded data
     * @return array RPC result
     */
    public function removeTorrents(array $ids, bool $deleteData = false): array
    {
        return $this->request('torrent-remove', [
            'ids' => $ids,
            'delete-local-data' => $deleteData,
        ]);
    }

    /**
     * Set file priorities and wanted status for a torrent.
     *
     * @param int    $id          Torrent ID
     * @param int[]  $fileIndices File indices to modify
     * @param string $priority    'high', 'normal', or 'low'
     * @param bool   $wanted      Whether files are wanted
     * @return array RPC result
     */
    public function setTorrentFiles(
        int $id,
        array $fileIndices,
        string $priority = 'normal',
        bool $wanted = true
    ): array {
        $args = ['ids' => [$id]];

        $priorityMap = [
            'high' => 'priority-high',
            'normal' => 'priority-normal',
            'low' => 'priority-low',
        ];
        if (isset($priorityMap[$priority])) {
            $args[$priorityMap[$priority]] = $fileIndices;
        }

        $wantedKey = $wanted ? 'files-wanted' : 'files-unwanted';
        $args[$wantedKey] = $fileIndices;

        return $this->request('torrent-set', $args);
    }

    /**
     * Set labels on a torrent.
     *
     * @param int      $id     Torrent ID
     * @param string[] $labels Labels to set
     * @return array RPC result
     */
    public function setTorrentLabels(int $id, array $labels): array
    {
        return $this->request('torrent-set', [
            'ids' => [$id],
            'labels' => $labels,
        ]);
    }

    /**
     * Move torrent data to a new location.
     *
     * @param int    $id       Torrent ID
     * @param string $location New directory path
     * @param bool   $move     Move existing data (true) or just change path (false)
     * @return array RPC result
     */
    public function moveTorrent(int $id, string $location, bool $move = true): array
    {
        return $this->request('torrent-set-location', [
            'ids' => [$id],
            'location' => $location,
            'move' => $move,
        ]);
    }

    // ---------------------------------------------------------------
    // Session / settings methods
    // ---------------------------------------------------------------

    /**
     * Get Transmission session settings.
     *
     * @param string[]|null $fields Fields to return (null = all)
     * @return array Session settings
     */
    public function getSession(?array $fields = null): array
    {
        $args = [];
        if ($fields !== null) {
            $args['fields'] = $fields;
        }
        return $this->request('session-get', $args);
    }

    /**
     * Set Transmission session settings.
     *
     * @param array $settings Key-value pairs to change
     * @return array RPC result
     */
    public function setSession(array $settings): array
    {
        return $this->request('session-set', $settings);
    }

    /**
     * Get session statistics (speeds, counts).
     *
     * @return array Session stats
     */
    public function getSessionStats(): array
    {
        return $this->request('session-stats');
    }

    /**
     * Test if the Transmission daemon is reachable.
     *
     * @return bool True if connection succeeds
     */
    public function testConnection(): bool
    {
        try {
            $this->getSession(['version']);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Free space
    // ---------------------------------------------------------------

    /**
     * Get free disk space at the given path.
     *
     * @param string $path Directory path on the daemon host
     * @return array With 'path', 'size-bytes', and 'total-size' keys
     */
    public function getFreeSpace(string $path): array
    {
        return $this->request('free-space', ['path' => $path]);
    }

    // ---------------------------------------------------------------
    // Connection configuration
    // ---------------------------------------------------------------

    /**
     * @param string|null $username
     * @param string|null $password
     */
    public function setCredentials(?string $username, ?string $password): void
    {
        $this->username = $username;
        $this->password = $password;
    }

    /** @return string */
    public function getUrl(): string
    {
        return $this->url;
    }

    // ---------------------------------------------------------------
    // Internal RPC transport
    // ---------------------------------------------------------------

    /**
     * Send a JSON-RPC request to the Transmission daemon.
     *
     * On a 409 response, extracts X-Transmission-Session-Id and retries once.
     *
     * @param string $method    RPC method name
     * @param array  $arguments Method arguments
     * @return array The 'arguments' from the RPC response
     * @throws ConnectionException
     * @throws AuthenticationException
     * @throws TransmissionException
     */
    protected function request(string $method, array $arguments = []): array
    {
        return $this->doRequest($method, $arguments, true);
    }

    /**
     * @param bool $retryOnCsrf Retry on 409 (false on second attempt)
     */
    private function doRequest(string $method, array $arguments, bool $retryOnCsrf): array
    {
        $payload = json_encode([
            'method' => $method,
            'arguments' => empty($arguments) ? new \stdClass() : $arguments,
        ]);

        $headers = ['Content-Type: application/json'];
        if ($this->sessionId !== null) {
            $headers[] = 'X-Transmission-Session-Id: ' . $this->sessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADER => true,
        ]);

        if ($this->username !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $httpCode === 0) {
            throw new ConnectionException(
                'Failed to connect to Transmission at ' . $this->url . ': ' . $curlError
            );
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        // CSRF protection: 409 Conflict
        if ($httpCode === 409 && $retryOnCsrf) {
            $this->sessionId = $this->extractSessionId($responseHeaders);
            if ($this->sessionId !== null) {
                return $this->doRequest($method, $arguments, false);
            }
            throw new TransmissionException('409 response but no session ID found');
        }

        if ($httpCode === 401) {
            throw new AuthenticationException('Authentication failed for Transmission RPC');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new TransmissionException('Transmission RPC returned HTTP ' . $httpCode);
        }

        $data = json_decode($responseBody, true);
        if ($data === null) {
            throw new TransmissionException('Invalid JSON response from Transmission RPC');
        }

        if (!isset($data['result']) || $data['result'] !== 'success') {
            throw new TransmissionException(
                'RPC error: ' . ($data['result'] ?? 'unknown')
            );
        }

        return $data['arguments'] ?? [];
    }

    /**
     * Extract X-Transmission-Session-Id from response headers.
     *
     * @param string $headers Raw HTTP headers
     * @return string|null
     */
    private function extractSessionId(string $headers): ?string
    {
        if (preg_match('/X-Transmission-Session-Id:\s*(\S+)/i', $headers, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
