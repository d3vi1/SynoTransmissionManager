<?php

declare(strict_types=1);

/**
 * DSM WebAPI entry point.
 *
 * Synology invokes this file with $_SERVER variables defining the
 * requested API and method. This dispatcher creates the required
 * service objects and routes calls to the TorrentManager.
 *
 * Environment provided by DSM:
 *   - SYNOPKG_PKGNAME: package identifier
 *   - SYNOPKG_PKGDEST: package installation path
 *   - HTTP_X_SYNO_USER: authenticated DSM username (set by authLevel=1)
 *
 * The following $_REQUEST params are standard:
 *   - api:     API name (e.g. SYNO.Transmission.Torrent)
 *   - method:  Method name (e.g. list)
 *   - version: API version
 */

// Autoloader (composer or manual)
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Fallback: load classes manually when composer isn't available on DSM
    $srcDir = __DIR__;
    require_once $srcDir . '/TransmissionException.php';
    require_once $srcDir . '/ConnectionException.php';
    require_once $srcDir . '/AuthenticationException.php';
    require_once $srcDir . '/TransmissionRPC.php';
    require_once $srcDir . '/Database.php';
    require_once $srcDir . '/TorrentManager.php';
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Send a JSON response and exit.
 *
 * @param bool  $success Whether the request succeeded
 * @param mixed $data    Response data (on success) or error info (on failure)
 */
function apiResponse(bool $success, $data = null): void
{
    header('Content-Type: application/json; charset=UTF-8');

    if ($success) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        $error = is_array($data) ? $data : ['code' => -1, 'message' => (string)$data];
        echo json_encode(['success' => false, 'error' => $error]);
    }
    exit;
}

/**
 * Get the authenticated DSM username.
 *
 * @return string
 */
function getAuthenticatedUser(): string
{
    $user = $_SERVER['HTTP_X_SYNO_USER'] ?? '';
    if ($user === '') {
        apiResponse(false, ['code' => 401, 'message' => 'Not authenticated']);
    }
    return $user;
}

/**
 * Create the TorrentManager with production dependencies.
 *
 * @return TorrentManager
 */
function createManager(): TorrentManager
{
    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $configPath = $pkgDest . '/var/config.json';

    // Load connection config
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }

    $rpcHost = $config['rpc_host'] ?? 'localhost';
    $rpcPort = (int)($config['rpc_port'] ?? 9091);
    $rpcUser = $config['rpc_username'] ?? null;
    $rpcPass = $config['rpc_password'] ?? null;
    $allowedPaths = $config['allowed_paths'] ?? ['/volume1/'];

    $rpc = new TransmissionRPC($rpcHost, $rpcPort, $rpcUser, $rpcPass);
    $db = new Database($pkgDest . '/var/transmission.db');

    return new TorrentManager($rpc, $db, $allowedPaths);
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$api = $_REQUEST['api'] ?? '';
$method = $_REQUEST['method'] ?? '';

try {
    $user = getAuthenticatedUser();
    $manager = createManager();

    switch ($api) {
        case 'SYNO.Transmission.Torrent':
            handleTorrentApi($manager, $user, $method);
            break;

        case 'SYNO.Transmission.Settings':
            handleSettingsApi($manager, $user, $method);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown API: ' . $api]);
    }
} catch (AuthenticationException $e) {
    apiResponse(false, ['code' => 403, 'message' => 'Transmission authentication failed']);
} catch (ConnectionException $e) {
    apiResponse(false, ['code' => 502, 'message' => 'Cannot reach Transmission daemon']);
} catch (TransmissionException $e) {
    apiResponse(false, ['code' => 500, 'message' => 'Transmission error: ' . $e->getMessage()]);
} catch (\InvalidArgumentException $e) {
    apiResponse(false, ['code' => 400, 'message' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    apiResponse(false, ['code' => 403, 'message' => $e->getMessage()]);
} catch (\Exception $e) {
    apiResponse(false, ['code' => 500, 'message' => 'Internal error']);
}

// ---------------------------------------------------------------------------
// API handlers
// ---------------------------------------------------------------------------

/**
 * Handle SYNO.Transmission.Torrent methods.
 */
function handleTorrentApi(TorrentManager $manager, string $user, string $method): void
{
    switch ($method) {
        case 'list':
            $torrents = $manager->listTorrents($user);
            apiResponse(true, ['torrents' => $torrents]);
            break;

        case 'get':
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing or invalid id']);
            }
            $torrent = $manager->getTorrent($user, $id);
            if ($torrent === null) {
                apiResponse(false, ['code' => 404, 'message' => 'Torrent not found']);
            }
            apiResponse(true, $torrent);
            break;

        case 'add':
            $url = $_REQUEST['url'] ?? '';
            $downloadDir = $_REQUEST['download_dir'] ?? null;
            $paused = filter_var($_REQUEST['paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $labels = isset($_REQUEST['labels']) ? array_filter(explode(',', $_REQUEST['labels'])) : [];

            if ($url === '' && empty($_FILES['torrent_file'])) {
                apiResponse(false, ['code' => 400, 'message' => 'Provide url or torrent_file']);
            }

            if ($url !== '') {
                $result = $manager->addTorrentUrl($user, $url, $downloadDir ?: null, $paused, $labels);
            } else {
                $fileContent = file_get_contents($_FILES['torrent_file']['tmp_name']);
                $result = $manager->addTorrentFile($user, $fileContent, $downloadDir ?: null, $paused, $labels);
            }
            apiResponse(true, $result);
            break;

        case 'start':
            $ids = parseIds();
            $result = $manager->startTorrents($user, $ids);
            apiResponse(true, $result);
            break;

        case 'stop':
            $ids = parseIds();
            $result = $manager->stopTorrents($user, $ids);
            apiResponse(true, $result);
            break;

        case 'remove':
            $ids = parseIds();
            $deleteData = filter_var($_REQUEST['delete_data'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $result = $manager->removeTorrents($user, $ids, $deleteData);
            apiResponse(true, $result);
            break;

        case 'set_files':
            $id = (int)($_REQUEST['id'] ?? 0);
            $fileIndices = array_map('intval', explode(',', $_REQUEST['file_indices'] ?? ''));
            $priority = $_REQUEST['priority'] ?? 'normal';
            $wanted = filter_var($_REQUEST['wanted'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($id <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing or invalid id']);
            }
            $result = $manager->setTorrentFiles($user, $id, $fileIndices, $priority, $wanted);
            apiResponse(true, $result);
            break;

        case 'set_labels':
            $id = (int)($_REQUEST['id'] ?? 0);
            $labels = array_filter(explode(',', $_REQUEST['labels'] ?? ''));

            if ($id <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing or invalid id']);
            }
            $result = $manager->setTorrentLabels($user, $id, $labels);
            apiResponse(true, $result);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown method: ' . $method]);
    }
}

/**
 * Handle SYNO.Transmission.Settings methods.
 */
function handleSettingsApi(TorrentManager $manager, string $user, string $method): void
{
    switch ($method) {
        case 'get':
            $settings = $manager->getSettings();
            apiResponse(true, $settings);
            break;

        case 'set':
            $settingsJson = $_REQUEST['settings'] ?? '{}';
            $settings = json_decode($settingsJson, true);
            if (!is_array($settings)) {
                apiResponse(false, ['code' => 400, 'message' => 'Invalid settings JSON']);
            }
            $result = $manager->setSettings($settings);
            apiResponse(true, $result);
            break;

        case 'test_connection':
            $connected = $manager->testConnection();
            apiResponse(true, ['connected' => $connected]);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown method: ' . $method]);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse comma-separated IDs from the 'ids' request parameter.
 *
 * @return int[]
 */
function parseIds(): array
{
    $raw = $_REQUEST['ids'] ?? '';
    if ($raw === '') {
        apiResponse(false, ['code' => 400, 'message' => 'Missing ids parameter']);
    }
    return array_map('intval', explode(',', $raw));
}
