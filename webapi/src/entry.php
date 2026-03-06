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
    require_once $srcDir . '/RSSManager.php';
    require_once $srcDir . '/AutomationEngine.php';
    require_once $srcDir . '/NotificationService.php';
    require_once $srcDir . '/RateLimiter.php';
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

/**
 * Create the RSSManager with production dependencies.
 *
 * @param TorrentManager $manager Existing torrent manager
 * @return RSSManager
 */
function createRSSManager(TorrentManager $manager): RSSManager
{
    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $db = new Database($pkgDest . '/var/transmission.db');
    return new RSSManager($db, $manager);
}

/**
 * Create the AutomationEngine with production dependencies.
 *
 * @return AutomationEngine
 */
function createAutomationEngine(): AutomationEngine
{
    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $configPath = $pkgDest . '/var/config.json';
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }

    $rpcHost = $config['rpc_host'] ?? 'localhost';
    $rpcPort = (int)($config['rpc_port'] ?? 9091);
    $rpcUser = $config['rpc_username'] ?? null;
    $rpcPass = $config['rpc_password'] ?? null;
    $allowedScripts = $config['allowed_script_paths'] ?? ['/volume1/scripts/'];

    $rpc = new TransmissionRPC($rpcHost, $rpcPort, $rpcUser, $rpcPass);
    $db = new Database($pkgDest . '/var/transmission.db');

    return new AutomationEngine($db, $rpc, $allowedScripts);
}

/**
 * Check if the authenticated user is a DSM administrator.
 *
 * Checks the HTTP_X_SYNO_IS_ADMIN header set by DSM for admin users.
 *
 * @param string $user DSM username (unused, reserved for future use)
 * @return bool True if the user has admin privileges
 */
function isAdmin(string $user): bool
{
    $isAdmin = $_SERVER['HTTP_X_SYNO_IS_ADMIN'] ?? '';
    return $isAdmin === 'true' || $isAdmin === '1';
}

/**
 * Require admin privileges or respond with a 403 error.
 *
 * @param string $user DSM username
 */
function requireAdmin(string $user): void
{
    if (!isAdmin($user)) {
        apiResponse(false, ['code' => 403, 'message' => 'Admin privileges required']);
    }
}

/**
 * Create a RateLimiter with production dependencies.
 *
 * @return RateLimiter
 */
function createRateLimiter(): RateLimiter
{
    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $db = new Database($pkgDest . '/var/transmission.db');
    return new RateLimiter($db);
}

/**
 * Create a NotificationService with production dependencies.
 *
 * @return NotificationService
 */
function createNotificationService(): NotificationService
{
    $pkgDest = getenv('SYNOPKG_PKGDEST') ?: '/var/packages/TransmissionManager';
    $configPath = $pkgDest . '/var/config.json';
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }

    $notifier = new NotificationService();
    $verbosity = $config['notification_verbosity'] ?? 'all';
    $notifier->setVerbosity($verbosity);

    return $notifier;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$api = $_REQUEST['api'] ?? '';
$method = $_REQUEST['method'] ?? '';

try {
    $user = getAuthenticatedUser();

    // Rate limiting: check global API call limit
    $rateLimiter = createRateLimiter();
    $rateLimiter->cleanup();
    $rateLimiter->checkDefaultLimit($user, 'api_call');

    $manager = createManager();
    $notifier = createNotificationService();

    switch ($api) {
        case 'SYNO.Transmission.Torrent':
            handleTorrentApi($manager, $user, $method, $rateLimiter, $notifier);
            break;

        case 'SYNO.Transmission.Settings':
            handleSettingsApi($manager, $user, $method);
            break;

        case 'SYNO.Transmission.RSS':
            $rssManager = createRSSManager($manager);
            handleRSSApi($rssManager, $user, $method, $rateLimiter);
            break;

        case 'SYNO.Transmission.Automation':
            $automationEngine = createAutomationEngine();
            handleAutomationApi($automationEngine, $user, $method, $rateLimiter);
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
function handleTorrentApi(TorrentManager $manager, string $user, string $method, RateLimiter $rateLimiter, NotificationService $notifier): void
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
            $rateLimiter->checkDefaultLimit($user, 'torrent_add');

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

            // Notify user about the newly added torrent
            $torrentInfo = $result['torrent-added'] ?? $result['torrent-duplicate'] ?? null;
            if ($torrentInfo !== null && isset($torrentInfo['name'])) {
                $notifier->notifyDownloadComplete($user, $torrentInfo['name']);
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
            requireAdmin($user);
            $settingsJson = $_REQUEST['settings'] ?? '{}';
            $settings = json_decode($settingsJson, true);
            if (!is_array($settings)) {
                apiResponse(false, ['code' => 400, 'message' => 'Invalid settings JSON']);
            }
            $result = $manager->setSettings($settings);
            apiResponse(true, $result);
            break;

        case 'test_connection':
            requireAdmin($user);
            $connected = $manager->testConnection();
            apiResponse(true, ['connected' => $connected]);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown method: ' . $method]);
    }
}

/**
 * Handle SYNO.Transmission.RSS methods.
 */
function handleRSSApi(RSSManager $rss, string $user, string $method, RateLimiter $rateLimiter): void
{
    switch ($method) {
        case 'list_feeds':
            apiResponse(true, ['feeds' => $rss->listFeeds($user)]);
            break;

        case 'add_feed':
            $rateLimiter->checkDefaultLimit($user, 'feed_add');

            $name = $_REQUEST['name'] ?? '';
            $url = $_REQUEST['url'] ?? '';
            $interval = (int)($_REQUEST['refresh_interval'] ?? 1800);
            if ($name === '' || $url === '') {
                apiResponse(false, ['code' => 400, 'message' => 'Name and URL required']);
            }
            $id = $rss->addFeed($user, $name, $url, $interval);
            apiResponse(true, ['id' => $id]);
            break;

        case 'update_feed':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            $data = json_decode($_REQUEST['data'] ?? '{}', true) ?: [];
            if ($feedId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing feed_id']);
            }
            $ok = $rss->updateFeed($user, $feedId, $data);
            apiResponse(true, ['updated' => $ok]);
            break;

        case 'delete_feed':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            if ($feedId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing feed_id']);
            }
            $ok = $rss->deleteFeed($user, $feedId);
            apiResponse(true, ['deleted' => $ok]);
            break;

        case 'test_feed':
            $url = $_REQUEST['url'] ?? '';
            if ($url === '') {
                apiResponse(false, ['code' => 400, 'message' => 'URL required']);
            }
            $items = $rss->testFeed($url);
            apiResponse(true, ['items' => $items]);
            break;

        case 'list_filters':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            if ($feedId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing feed_id']);
            }
            apiResponse(true, ['filters' => $rss->listFilters($user, $feedId)]);
            break;

        case 'add_filter':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            $pattern = $_REQUEST['pattern'] ?? '';
            $matchMode = $_REQUEST['match_mode'] ?? 'contains';
            $exclude = $_REQUEST['exclude_pattern'] ?? null;
            $downloadPath = $_REQUEST['download_path'] ?? null;
            $labels = $_REQUEST['labels'] ?? null;
            $paused = filter_var($_REQUEST['start_paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($feedId <= 0 || $pattern === '') {
                apiResponse(false, ['code' => 400, 'message' => 'feed_id and pattern required']);
            }
            $id = $rss->addFilter($user, $feedId, $pattern, $matchMode, $exclude, $downloadPath, $labels, $paused);
            apiResponse(true, ['id' => $id]);
            break;

        case 'update_filter':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            $filterId = (int)($_REQUEST['filter_id'] ?? 0);
            $data = json_decode($_REQUEST['data'] ?? '{}', true) ?: [];
            if ($feedId <= 0 || $filterId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'feed_id and filter_id required']);
            }
            $ok = $rss->updateFilter($user, $feedId, $filterId, $data);
            apiResponse(true, ['updated' => $ok]);
            break;

        case 'delete_filter':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            $filterId = (int)($_REQUEST['filter_id'] ?? 0);
            if ($feedId <= 0 || $filterId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'feed_id and filter_id required']);
            }
            $ok = $rss->deleteFilter($user, $feedId, $filterId);
            apiResponse(true, ['deleted' => $ok]);
            break;

        case 'test_filter':
            $title = $_REQUEST['title'] ?? '';
            $pattern = $_REQUEST['pattern'] ?? '';
            $matchMode = $_REQUEST['match_mode'] ?? 'contains';
            $exclude = $_REQUEST['exclude_pattern'] ?? null;
            $matches = $rss->testFilter($title, $pattern, $matchMode, $exclude);
            apiResponse(true, ['matches' => $matches]);
            break;

        case 'get_history':
            $feedId = (int)($_REQUEST['feed_id'] ?? 0);
            if ($feedId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing feed_id']);
            }
            $history = $rss->getHistory($user, $feedId);
            apiResponse(true, ['history' => $history]);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown RSS method: ' . $method]);
    }
}

/**
 * Handle SYNO.Transmission.Automation methods.
 */
function handleAutomationApi(AutomationEngine $engine, string $user, string $method, RateLimiter $rateLimiter): void
{
    switch ($method) {
        case 'list_rules':
            apiResponse(true, ['rules' => $engine->listRules($user)]);
            break;

        case 'add_rule':
            $rateLimiter->checkDefaultLimit($user, 'rule_add');

            $name = $_REQUEST['name'] ?? '';
            $triggerType = $_REQUEST['trigger_type'] ?? '';
            $triggerValue = $_REQUEST['trigger_value'] ?? null;
            $conditions = json_decode($_REQUEST['conditions'] ?? '[]', true) ?: [];
            $actions = json_decode($_REQUEST['actions'] ?? '[]', true) ?: [];
            if ($name === '' || $triggerType === '') {
                apiResponse(false, ['code' => 400, 'message' => 'Name and trigger_type required']);
            }
            $id = $engine->addRule($user, $name, $triggerType, $triggerValue, $conditions, $actions);
            apiResponse(true, ['id' => $id]);
            break;

        case 'update_rule':
            $ruleId = (int)($_REQUEST['rule_id'] ?? 0);
            $data = json_decode($_REQUEST['data'] ?? '{}', true) ?: [];
            if ($ruleId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing rule_id']);
            }
            $ok = $engine->updateRule($user, $ruleId, $data);
            apiResponse(true, ['updated' => $ok]);
            break;

        case 'delete_rule':
            $ruleId = (int)($_REQUEST['rule_id'] ?? 0);
            if ($ruleId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing rule_id']);
            }
            $ok = $engine->deleteRule($user, $ruleId);
            apiResponse(true, ['deleted' => $ok]);
            break;

        case 'test_rule':
            $ruleId = (int)($_REQUEST['rule_id'] ?? 0);
            if ($ruleId <= 0) {
                apiResponse(false, ['code' => 400, 'message' => 'Missing rule_id']);
            }
            $matches = $engine->testRule($user, $ruleId);
            apiResponse(true, ['matches' => $matches]);
            break;

        default:
            apiResponse(false, ['code' => 404, 'message' => 'Unknown Automation method: ' . $method]);
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
