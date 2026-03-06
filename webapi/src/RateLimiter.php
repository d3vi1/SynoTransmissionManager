<?php

declare(strict_types=1);

/**
 * Rate limiter for API actions.
 *
 * Tracks per-user, per-action request counts in the database and
 * throws a TransmissionException when the configured limit is exceeded.
 *
 * Default limits (requests per minute):
 *   - torrent_add: 10
 *   - feed_add:     5
 *   - rule_add:     5
 *   - api_call:    60
 */
class RateLimiter
{
    /** @var Database */
    private $db;

    /** @var array<string, int> Default per-minute limits for each action */
    private static $defaultLimits = [
        'torrent_add' => 10,
        'feed_add'    => 5,
        'rule_add'    => 5,
        'api_call'    => 60,
    ];

    /**
     * @param Database $db Database instance for rate limit storage
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Check whether a user has exceeded the rate limit for an action.
     *
     * Records the current request and then checks if the total count
     * within the last 60 seconds exceeds the given limit.
     *
     * @param string $user         DSM username
     * @param string $action       Action identifier (e.g. 'torrent_add')
     * @param int    $maxPerMinute Maximum allowed requests per minute
     * @throws TransmissionException If the rate limit is exceeded
     */
    public function checkLimit(string $user, string $action, int $maxPerMinute): void
    {
        $now = time();
        $windowStart = $now - 60;

        // Record this request
        $this->db->recordRateLimit($user, $action);

        // Count requests in the window
        $count = $this->db->getRateLimitCount($user, $action, $windowStart);

        if ($count > $maxPerMinute) {
            throw new TransmissionException(
                'Rate limit exceeded for action "' . $action . '". '
                . 'Maximum ' . $maxPerMinute . ' requests per minute.'
            );
        }
    }

    /**
     * Check the rate limit using the default limit for a known action.
     *
     * @param string $user   DSM username
     * @param string $action Action identifier
     * @throws TransmissionException If the rate limit is exceeded
     * @throws \InvalidArgumentException If the action has no default limit
     */
    public function checkDefaultLimit(string $user, string $action): void
    {
        if (!isset(self::$defaultLimits[$action])) {
            throw new \InvalidArgumentException('Unknown rate-limited action: ' . $action);
        }

        $this->checkLimit($user, $action, self::$defaultLimits[$action]);
    }

    /**
     * Remove rate limit records older than 1 minute.
     *
     * Should be called periodically (e.g. on each request) to prevent
     * the rate_limits table from growing indefinitely.
     */
    public function cleanup(): void
    {
        $cutoff = time() - 60;
        $this->db->cleanupRateLimits($cutoff);
    }

    /**
     * Get the default per-minute limit for an action.
     *
     * @param string $action Action identifier
     * @return int|null Limit or null if action is unknown
     */
    public static function getDefaultLimit(string $action): ?int
    {
        return self::$defaultLimits[$action] ?? null;
    }
}
