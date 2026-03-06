<?php

declare(strict_types=1);

/**
 * Post-processing automation engine for SynoTransmissionManager.
 *
 * Evaluates rules with triggers (on-complete, on-add, on-ratio, schedule),
 * conditions (label, name, size, tracker), and actions (move, notify,
 * remove, label, script).
 */
class AutomationEngine
{
    /** @var Database */
    private $db;

    /** @var TransmissionRPC */
    private $rpc;

    /** @var string[] Allowed script paths */
    private $allowedScriptPaths;

    /** @var int Script execution timeout in seconds */
    private $scriptTimeout;

    /**
     * @param Database        $db
     * @param TransmissionRPC $rpc
     * @param string[]        $allowedScriptPaths Whitelisted script directories
     * @param int             $scriptTimeout      Max script execution time
     */
    public function __construct(
        Database $db,
        TransmissionRPC $rpc,
        array $allowedScriptPaths = [],
        int $scriptTimeout = 30
    ) {
        $this->db = $db;
        $this->rpc = $rpc;
        $this->allowedScriptPaths = $allowedScriptPaths;
        $this->scriptTimeout = $scriptTimeout;
    }

    // ---------------------------------------------------------------
    // Rule CRUD (delegated to Database)
    // ---------------------------------------------------------------

    /**
     * List rules for a user.
     */
    public function listRules(string $user): array
    {
        return $this->db->getUserRules($user);
    }

    /**
     * Add a new rule.
     */
    public function addRule(
        string $user,
        string $name,
        string $triggerType,
        ?string $triggerValue = null,
        array $conditions = [],
        array $actions = []
    ): int {
        $this->validateTriggerType($triggerType);
        $this->validateActions($actions);
        return $this->db->addRule($user, $name, $triggerType, $triggerValue, $conditions, $actions);
    }

    /**
     * Update a rule.
     */
    public function updateRule(string $user, int $ruleId, array $data): bool
    {
        if (isset($data['trigger_type'])) {
            $this->validateTriggerType($data['trigger_type']);
        }
        if (isset($data['actions']) && is_array($data['actions'])) {
            $this->validateActions($data['actions']);
        }
        return $this->db->updateRule($user, $ruleId, $data);
    }

    /**
     * Delete a rule.
     */
    public function deleteRule(string $user, int $ruleId): bool
    {
        return $this->db->deleteRule($user, $ruleId);
    }

    // ---------------------------------------------------------------
    // Rule processing
    // ---------------------------------------------------------------

    /**
     * Process rules for a given trigger type against torrents.
     *
     * @param string $triggerType 'on-complete', 'on-add', 'on-ratio', 'schedule'
     * @param array  $torrents    Torrent data from Transmission
     * @return array Processing log [{rule, torrent, actions, errors}]
     */
    public function processRules(string $triggerType, array $torrents): array
    {
        $log = [];
        $rules = $this->db->getEnabledRulesByTrigger($triggerType);

        foreach ($rules as $rule) {
            foreach ($torrents as $torrent) {
                if ($this->evaluateConditions($torrent, $rule['conditions'])) {
                    $entry = [
                        'rule' => $rule['name'],
                        'torrent' => $torrent['name'] ?? 'unknown',
                        'actions' => [],
                        'errors' => [],
                    ];

                    foreach ($rule['actions'] as $action) {
                        try {
                            $this->executeAction($action, $torrent, $rule['user']);
                            $entry['actions'][] = $action['type'] ?? 'unknown';
                        } catch (\Exception $e) {
                            $entry['errors'][] = ($action['type'] ?? 'unknown') . ': ' . $e->getMessage();
                        }
                    }

                    $log[] = $entry;
                }
            }
        }

        return $log;
    }

    /**
     * Dry-run a rule against current torrents (for testing).
     *
     * @param string $user   DSM username
     * @param int    $ruleId Rule ID
     * @return array[] Matched torrents with would-be actions
     */
    public function testRule(string $user, int $ruleId): array
    {
        $rules = $this->db->getUserRules($user);
        $rule = null;
        foreach ($rules as $r) {
            if ((int)$r['id'] === $ruleId) {
                $rule = $r;
                break;
            }
        }

        if ($rule === null) {
            throw new TransmissionException('Rule not found');
        }

        // Get all user's torrents
        $torrentIds = $this->db->getUserTorrentIds($user);
        if (empty($torrentIds)) {
            return [];
        }

        $allTorrents = $this->rpc->getTorrents();
        $userTorrents = array_filter($allTorrents, function ($t) use ($torrentIds) {
            return in_array($t['id'], $torrentIds, true);
        });

        $matches = [];
        foreach ($userTorrents as $torrent) {
            if ($this->evaluateConditions($torrent, $rule['conditions'])) {
                $matches[] = [
                    'id' => $torrent['id'],
                    'name' => $torrent['name'] ?? 'unknown',
                    'wouldExecute' => array_map(function ($a) {
                        return $a['type'] ?? 'unknown';
                    }, $rule['actions']),
                ];
            }
        }

        return $matches;
    }

    // ---------------------------------------------------------------
    // Condition evaluation
    // ---------------------------------------------------------------

    /**
     * Evaluate all conditions against a torrent.
     *
     * @param array   $torrent    Torrent data
     * @param array[] $conditions Condition definitions
     * @return bool True if all conditions match (AND logic)
     */
    public function evaluateConditions(array $torrent, array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($torrent, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition.
     *
     * @param array $torrent   Torrent data
     * @param array $condition {type, value, operator?}
     * @return bool
     */
    public function evaluateCondition(array $torrent, array $condition): bool
    {
        $type = $condition['type'] ?? '';
        $value = $condition['value'] ?? '';
        $operator = $condition['operator'] ?? 'equals';

        switch ($type) {
            case 'label':
                $labels = $torrent['labels'] ?? [];
                if (!is_array($labels)) {
                    return false;
                }
                return in_array($value, $labels, true);

            case 'name':
                $name = $torrent['name'] ?? '';
                return $this->evaluateStringCondition($name, $value, $operator);

            case 'size':
                $size = $torrent['totalSize'] ?? 0;
                return $this->evaluateNumericCondition((float)$size, (float)$value, $operator);

            case 'tracker':
                $trackers = $torrent['trackers'] ?? [];
                foreach ($trackers as $tracker) {
                    $announce = $tracker['announce'] ?? '';
                    if (stripos($announce, $value) !== false) {
                        return true;
                    }
                }
                return false;

            case 'status':
                $status = $torrent['status'] ?? -1;
                return (int)$status === (int)$value;

            case 'ratio':
                $ratio = $torrent['uploadRatio'] ?? 0;
                return $this->evaluateNumericCondition((float)$ratio, (float)$value, $operator);

            default:
                return false;
        }
    }

    /**
     * Evaluate a string condition.
     */
    private function evaluateStringCondition(string $actual, string $expected, string $operator): bool
    {
        switch ($operator) {
            case 'equals':
                return strcasecmp($actual, $expected) === 0;
            case 'contains':
                return stripos($actual, $expected) !== false;
            case 'regex':
                return (bool)@preg_match($expected, $actual);
            case 'starts_with':
                return strncasecmp($actual, $expected, strlen($expected)) === 0;
            default:
                return false;
        }
    }

    /**
     * Evaluate a numeric condition.
     */
    private function evaluateNumericCondition(float $actual, float $expected, string $operator): bool
    {
        switch ($operator) {
            case 'equals':
                return abs($actual - $expected) < 0.001;
            case 'gt':
                return $actual > $expected;
            case 'lt':
                return $actual < $expected;
            case 'gte':
                return $actual >= $expected;
            case 'lte':
                return $actual <= $expected;
            default:
                return false;
        }
    }

    // ---------------------------------------------------------------
    // Action execution
    // ---------------------------------------------------------------

    /**
     * Execute a single action on a torrent.
     *
     * @param array  $action  {type, value?, ...}
     * @param array  $torrent Torrent data
     * @param string $user    DSM username
     */
    public function executeAction(array $action, array $torrent, string $user): void
    {
        $type = $action['type'] ?? '';

        switch ($type) {
            case 'move':
                $this->actionMoveFiles($torrent, $action['path'] ?? '');
                break;

            case 'label':
                $this->actionSetLabels($torrent, $action['labels'] ?? '');
                break;

            case 'remove':
                $deleteData = !empty($action['delete_data']);
                $this->actionRemove($torrent, $deleteData);
                break;

            case 'stop':
                $this->actionStop($torrent);
                break;

            case 'script':
                $this->actionScript($action['script'] ?? '', $torrent);
                break;

            case 'notify':
                // DSM notification — logged for now, full implementation in M5
                $this->actionNotify($user, $torrent, $action['message'] ?? '');
                break;

            default:
                throw new TransmissionException('Unknown action type: ' . $type);
        }
    }

    /**
     * Move torrent data to a new location.
     */
    private function actionMoveFiles(array $torrent, string $path): void
    {
        if (empty($path)) {
            throw new TransmissionException('Move action requires a path');
        }
        $this->rpc->moveTorrent($torrent['id'], $path, true);
    }

    /**
     * Set labels on a torrent.
     */
    private function actionSetLabels(array $torrent, string $labels): void
    {
        $labelArray = array_map('trim', explode(',', $labels));
        $labelArray = array_filter($labelArray, function ($l) { return $l !== ''; });
        $this->rpc->setTorrentLabels($torrent['id'], $labelArray);
    }

    /**
     * Remove a torrent.
     */
    private function actionRemove(array $torrent, bool $deleteData): void
    {
        $this->rpc->removeTorrents([$torrent['id']], $deleteData);
    }

    /**
     * Stop a torrent.
     */
    private function actionStop(array $torrent): void
    {
        $this->rpc->stopTorrents([$torrent['id']]);
    }

    /**
     * Execute a script with torrent info in environment.
     */
    protected function actionScript(string $scriptPath, array $torrent): void
    {
        if (empty($scriptPath)) {
            throw new TransmissionException('Script action requires a script path');
        }

        // Validate script path against whitelist
        $allowed = false;
        foreach ($this->allowedScriptPaths as $dir) {
            if (strpos(realpath($scriptPath) ?: $scriptPath, $dir) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new TransmissionException('Script path not in allowed directories');
        }

        if (!is_executable($scriptPath)) {
            throw new TransmissionException('Script is not executable: ' . $scriptPath);
        }

        // Build sanitised environment
        $env = [
            'TR_TORRENT_ID' => (string)($torrent['id'] ?? ''),
            'TR_TORRENT_NAME' => $torrent['name'] ?? '',
            'TR_TORRENT_DIR' => $torrent['downloadDir'] ?? '',
            'TR_TORRENT_HASH' => $torrent['hashString'] ?? '',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            escapeshellarg($scriptPath),
            $descriptors,
            $pipes,
            null,
            $env
        );

        if (!is_resource($process)) {
            throw new TransmissionException('Failed to execute script');
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], $this->scriptTimeout);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new TransmissionException('Script exited with code ' . $exitCode);
        }
    }

    /**
     * Send a notification (stub — full DSM notification in M5).
     */
    private function actionNotify(string $user, array $torrent, string $message): void
    {
        $msg = !empty($message) ? $message : 'Torrent completed: ' . ($torrent['name'] ?? 'unknown');
        // Will be implemented with synonotify in M5
        error_log('[TransmissionManager] Notification for ' . $user . ': ' . $msg);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    /**
     * Validate trigger type.
     */
    private function validateTriggerType(string $type): void
    {
        $valid = ['on-complete', 'on-add', 'on-ratio', 'schedule'];
        if (!in_array($type, $valid, true)) {
            throw new TransmissionException('Invalid trigger type: ' . $type);
        }
    }

    /**
     * Validate action definitions.
     */
    private function validateActions(array $actions): void
    {
        $validTypes = ['move', 'label', 'remove', 'stop', 'script', 'notify'];
        foreach ($actions as $action) {
            if (!isset($action['type'])) {
                throw new TransmissionException('Each action must have a type');
            }
            if (!in_array($action['type'], $validTypes, true)) {
                throw new TransmissionException('Invalid action type: ' . $action['type']);
            }
        }
    }
}
