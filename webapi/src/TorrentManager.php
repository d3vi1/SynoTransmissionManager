<?php

declare(strict_types=1);

/**
 * High-level orchestrator bridging DSM WebAPI calls with
 * TransmissionRPC and the Database layer.
 *
 * Every torrent operation verifies ownership against the authenticated
 * DSM user before proceeding. Download paths are validated against an
 * allow-list to prevent directory-traversal attacks.
 */
class TorrentManager
{
    /** @var TransmissionRPC */
    private $rpc;

    /** @var Database */
    private $db;

    /** @var string[] Allowed base directories for downloads */
    private $allowedPaths;

    /**
     * @param TransmissionRPC $rpc          Transmission RPC client
     * @param Database        $db           Database instance
     * @param string[]        $allowedPaths Allowed download directory prefixes
     */
    public function __construct(TransmissionRPC $rpc, Database $db, array $allowedPaths = ['/volume1/'])
    {
        $this->rpc = $rpc;
        $this->db = $db;
        $this->allowedPaths = $allowedPaths;
    }

    // ---------------------------------------------------------------
    // Torrent listing / detail
    // ---------------------------------------------------------------

    /**
     * List torrents belonging to the authenticated user.
     *
     * @param string $user DSM username
     * @return array[] Torrent data for the user's torrents only
     */
    public function listTorrents(string $user): array
    {
        $ids = $this->db->getUserTorrentIds($user);
        if (empty($ids)) {
            return [];
        }

        return $this->rpc->getTorrents($ids);
    }

    /**
     * Get detailed info for a single torrent (ownership-checked).
     *
     * @param string $user DSM username
     * @param int    $id   Torrent ID
     * @return array|null Torrent detail or null if not found / not owned
     */
    public function getTorrent(string $user, int $id): ?array
    {
        if (!$this->db->isUserTorrent($user, $id)) {
            return null;
        }
        return $this->rpc->getTorrent($id);
    }

    // ---------------------------------------------------------------
    // Torrent actions
    // ---------------------------------------------------------------

    /**
     * Add a torrent from a URL or magnet link.
     *
     * @param string      $user        DSM username
     * @param string      $url         URL or magnet link
     * @param string|null $downloadDir Download directory (validated)
     * @param bool        $paused      Start paused
     * @param string[]    $labels      Labels to assign
     * @return array RPC result with torrent-added or torrent-duplicate
     * @throws \InvalidArgumentException If download path is invalid
     */
    public function addTorrentUrl(
        string $user,
        string $url,
        ?string $downloadDir = null,
        bool $paused = false,
        array $labels = []
    ): array {
        if ($downloadDir !== null) {
            $this->validatePath($downloadDir);
        }

        $result = $this->rpc->addTorrentUrl($url, $downloadDir, $paused, $labels);
        $this->registerTorrentFromResult($user, $result);
        return $result;
    }

    /**
     * Add a torrent from raw .torrent file content.
     *
     * @param string      $user        DSM username
     * @param string      $fileContent Raw .torrent bytes
     * @param string|null $downloadDir Download directory (validated)
     * @param bool        $paused      Start paused
     * @param string[]    $labels      Labels to assign
     * @return array RPC result
     * @throws \InvalidArgumentException If download path is invalid
     */
    public function addTorrentFile(
        string $user,
        string $fileContent,
        ?string $downloadDir = null,
        bool $paused = false,
        array $labels = []
    ): array {
        if ($downloadDir !== null) {
            $this->validatePath($downloadDir);
        }

        $result = $this->rpc->addTorrentFile($fileContent, $downloadDir, $paused, $labels);
        $this->registerTorrentFromResult($user, $result);
        return $result;
    }

    /**
     * Start one or more torrents (ownership-checked).
     *
     * @param string $user DSM username
     * @param int[]  $ids  Torrent IDs
     * @return array RPC result
     * @throws \RuntimeException If any ID is not owned by the user
     */
    public function startTorrents(string $user, array $ids): array
    {
        $this->verifyOwnership($user, $ids);
        return $this->rpc->startTorrents($ids);
    }

    /**
     * Stop one or more torrents (ownership-checked).
     *
     * @param string $user DSM username
     * @param int[]  $ids  Torrent IDs
     * @return array RPC result
     * @throws \RuntimeException If any ID is not owned by the user
     */
    public function stopTorrents(string $user, array $ids): array
    {
        $this->verifyOwnership($user, $ids);
        return $this->rpc->stopTorrents($ids);
    }

    /**
     * Remove one or more torrents (ownership-checked).
     *
     * @param string $user       DSM username
     * @param int[]  $ids        Torrent IDs
     * @param bool   $deleteData Also delete downloaded data
     * @return array RPC result
     * @throws \RuntimeException If any ID is not owned by the user
     */
    public function removeTorrents(string $user, array $ids, bool $deleteData = false): array
    {
        $this->verifyOwnership($user, $ids);
        $result = $this->rpc->removeTorrents($ids, $deleteData);

        // Remove from database after successful RPC call
        foreach ($ids as $id) {
            $this->db->removeUserTorrent($user, $id);
        }

        return $result;
    }

    /**
     * Set file priorities and wanted status (ownership-checked).
     *
     * @param string $user        DSM username
     * @param int    $id          Torrent ID
     * @param int[]  $fileIndices File indices
     * @param string $priority    'high', 'normal', or 'low'
     * @param bool   $wanted      Whether files are wanted
     * @return array RPC result
     * @throws \RuntimeException If torrent is not owned by the user
     */
    public function setTorrentFiles(
        string $user,
        int $id,
        array $fileIndices,
        string $priority = 'normal',
        bool $wanted = true
    ): array {
        $this->verifyOwnership($user, [$id]);
        return $this->rpc->setTorrentFiles($id, $fileIndices, $priority, $wanted);
    }

    /**
     * Set labels on a torrent (ownership-checked).
     *
     * @param string   $user   DSM username
     * @param int      $id     Torrent ID
     * @param string[] $labels Labels
     * @return array RPC result
     * @throws \RuntimeException If torrent is not owned by the user
     */
    public function setTorrentLabels(string $user, int $id, array $labels): array
    {
        $this->verifyOwnership($user, [$id]);
        return $this->rpc->setTorrentLabels($id, $labels);
    }

    // ---------------------------------------------------------------
    // Settings / session
    // ---------------------------------------------------------------

    /**
     * Get Transmission session settings.
     *
     * @return array Session settings
     */
    public function getSettings(): array
    {
        return $this->rpc->getSession();
    }

    /**
     * Update Transmission session settings (admin-only in .lib).
     *
     * @param array $settings Key-value pairs
     * @return array RPC result
     */
    public function setSettings(array $settings): array
    {
        return $this->rpc->setSession($settings);
    }

    /**
     * Test connectivity to the Transmission daemon.
     *
     * @return bool True if reachable
     */
    public function testConnection(): bool
    {
        return $this->rpc->testConnection();
    }

    // ---------------------------------------------------------------
    // Path validation
    // ---------------------------------------------------------------

    /**
     * Validate that a download path is within allowed directories.
     *
     * Prevents directory-traversal attacks by:
     * 1. Resolving `..` and `.` segments
     * 2. Checking the resolved path starts with an allowed prefix
     *
     * @param string $path Path to validate
     * @throws \InvalidArgumentException If path is not allowed
     */
    public function validatePath(string $path): void
    {
        $resolved = $this->resolvePath($path);

        foreach ($this->allowedPaths as $allowed) {
            if (strpos($resolved, $allowed) === 0) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            'Download path is not within allowed directories: ' . $path
        );
    }

    /**
     * Resolve `.` and `..` segments from a path without requiring the path
     * to exist on the local filesystem (the path refers to the remote NAS).
     *
     * @param string $path Unix path
     * @return string Resolved path (always starts with /)
     */
    private function resolvePath(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/' . implode('/', $resolved);
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Verify that all given torrent IDs belong to the user.
     *
     * @param string $user DSM username
     * @param int[]  $ids  Torrent IDs
     * @throws \RuntimeException If any ID is not owned
     */
    private function verifyOwnership(string $user, array $ids): void
    {
        foreach ($ids as $id) {
            if (!$this->db->isUserTorrent($user, $id)) {
                throw new \RuntimeException(
                    'Access denied: torrent ' . $id . ' does not belong to user ' . $user
                );
            }
        }
    }

    /**
     * Register a newly added torrent in the database.
     *
     * Handles both 'torrent-added' and 'torrent-duplicate' responses.
     *
     * @param string $user   DSM username
     * @param array  $result RPC result from torrent-add
     */
    private function registerTorrentFromResult(string $user, array $result): void
    {
        $torrent = $result['torrent-added'] ?? $result['torrent-duplicate'] ?? null;
        if ($torrent !== null && isset($torrent['id'], $torrent['hashString'])) {
            $this->db->addUserTorrent($user, (int)$torrent['id'], $torrent['hashString']);
        }
    }
}
