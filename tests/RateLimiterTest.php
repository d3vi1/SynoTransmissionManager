<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the RateLimiter class.
 *
 * Uses an in-memory SQLite database to test rate limiting logic
 * without filesystem side-effects.
 */
class RateLimiterTest extends TestCase
{
    /** @var Database */
    private $db;

    /** @var RateLimiter */
    private $limiter;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->limiter = new RateLimiter($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---------------------------------------------------------------
    // Basic limit checking
    // ---------------------------------------------------------------

    public function testCallsWithinLimitSucceed(): void
    {
        // 5 calls with a limit of 10 should all succeed
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->checkLimit('alice', 'torrent_add', 10);
        }
        $this->assertTrue(true); // No exception thrown
    }

    public function testExactlyAtLimitSucceeds(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->checkLimit('alice', 'torrent_add', 10);
        }
        $this->assertTrue(true); // No exception thrown
    }

    public function testExceedingLimitThrows(): void
    {
        // Fill up to the limit
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->checkLimit('alice', 'torrent_add', 10);
        }

        // The 11th call should throw
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        $this->limiter->checkLimit('alice', 'torrent_add', 10);
    }

    // ---------------------------------------------------------------
    // Independent action counters
    // ---------------------------------------------------------------

    public function testDifferentActionsHaveIndependentCounters(): void
    {
        // Hit the limit on torrent_add
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->checkLimit('alice', 'torrent_add', 5);
        }

        // feed_add should still work (separate counter)
        $this->limiter->checkLimit('alice', 'feed_add', 5);
        $this->assertTrue(true);
    }

    public function testDifferentUsersHaveIndependentCounters(): void
    {
        // Alice hits the limit
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->checkLimit('alice', 'torrent_add', 5);
        }

        // Bob should still be able to make requests
        $this->limiter->checkLimit('bob', 'torrent_add', 5);
        $this->assertTrue(true);

        // Alice should be blocked
        $this->expectException(TransmissionException::class);
        $this->limiter->checkLimit('alice', 'torrent_add', 5);
    }

    // ---------------------------------------------------------------
    // Cleanup
    // ---------------------------------------------------------------

    public function testCleanupRemovesOldRecords(): void
    {
        // Insert some records directly
        for ($i = 0; $i < 10; $i++) {
            $this->db->recordRateLimit('alice', 'torrent_add');
        }

        // Remove all current records (use time()+1 since cleanup uses < comparison)
        $this->db->cleanupRateLimits(time() + 1);

        // Now insert fresh ones — only 3
        for ($i = 0; $i < 3; $i++) {
            $this->db->recordRateLimit('alice', 'torrent_add');
        }

        // Cleanup should not remove these (they are fresh)
        $this->limiter->cleanup();

        // Should succeed because only 3 + 1 = 4 within the window
        $this->limiter->checkLimit('alice', 'torrent_add', 5);
        $this->assertTrue(true);
    }

    public function testCleanupAllowsNewRequestsAfterWindowExpires(): void
    {
        // Fill up the limit
        for ($i = 0; $i < 5; $i++) {
            $this->db->recordRateLimit('alice', 'api_call');
        }

        // Simulate time passing by cleaning up everything older than "now"
        // (pretend all existing records are old)
        $this->db->cleanupRateLimits(time() + 1);

        // Now new requests should succeed
        $this->limiter->checkLimit('alice', 'api_call', 5);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Default limits
    // ---------------------------------------------------------------

    public function testCheckDefaultLimitUsesKnownLimits(): void
    {
        // api_call has a default of 60/min — should not throw
        $this->limiter->checkDefaultLimit('alice', 'api_call');
        $this->assertTrue(true);
    }

    public function testCheckDefaultLimitThrowsForUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rate-limited action');
        $this->limiter->checkDefaultLimit('alice', 'unknown_action');
    }

    public function testGetDefaultLimitReturnsCorrectValues(): void
    {
        $this->assertSame(10, RateLimiter::getDefaultLimit('torrent_add'));
        $this->assertSame(5, RateLimiter::getDefaultLimit('feed_add'));
        $this->assertSame(5, RateLimiter::getDefaultLimit('rule_add'));
        $this->assertSame(60, RateLimiter::getDefaultLimit('api_call'));
        $this->assertNull(RateLimiter::getDefaultLimit('nonexistent'));
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    public function testSingleCallLimitOf1Succeeds(): void
    {
        $this->limiter->checkLimit('alice', 'rare_action', 1);
        $this->assertTrue(true);
    }

    public function testSecondCallWithLimitOf1Throws(): void
    {
        $this->limiter->checkLimit('alice', 'rare_action', 1);

        $this->expectException(TransmissionException::class);
        $this->limiter->checkLimit('alice', 'rare_action', 1);
    }
}
