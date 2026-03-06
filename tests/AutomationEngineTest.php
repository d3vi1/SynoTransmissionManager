<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../webapi/src/TransmissionException.php';
require_once __DIR__ . '/../webapi/src/ConnectionException.php';
require_once __DIR__ . '/../webapi/src/AuthenticationException.php';
require_once __DIR__ . '/../webapi/src/TransmissionRPC.php';
require_once __DIR__ . '/../webapi/src/Database.php';
require_once __DIR__ . '/../webapi/src/TorrentManager.php';
require_once __DIR__ . '/../webapi/src/AutomationEngine.php';

class AutomationEngineTest extends TestCase
{
    /** @var Database */
    private $db;

    /** @var \Tests\Support\TestableTransmissionRPC */
    private $rpc;

    /** @var AutomationEngine */
    private $engine;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->rpc = new \Tests\Support\TestableTransmissionRPC();
        $this->rpc->response = [];
        $this->engine = new AutomationEngine($this->db, $this->rpc);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---------------------------------------------------------------
    // Rule CRUD
    // ---------------------------------------------------------------

    public function testAddAndListRules(): void
    {
        $id = $this->engine->addRule('alice', 'Test Rule', 'on-complete', null, [], [
            ['type' => 'notify']
        ]);
        $this->assertGreaterThan(0, $id);

        $rules = $this->engine->listRules('alice');
        $this->assertCount(1, $rules);
        $this->assertSame('Test Rule', $rules[0]['name']);
        $this->assertSame('on-complete', $rules[0]['trigger_type']);
    }

    public function testInvalidTriggerType(): void
    {
        $this->expectException(TransmissionException::class);
        $this->engine->addRule('alice', 'Bad', 'invalid-trigger');
    }

    public function testInvalidActionType(): void
    {
        $this->expectException(TransmissionException::class);
        $this->engine->addRule('alice', 'Bad', 'on-complete', null, [], [
            ['type' => 'explode']
        ]);
    }

    public function testUpdateRule(): void
    {
        $id = $this->engine->addRule('alice', 'Old', 'on-complete');
        $ok = $this->engine->updateRule('alice', $id, ['name' => 'New']);
        $this->assertTrue($ok);

        $rules = $this->engine->listRules('alice');
        $this->assertSame('New', $rules[0]['name']);
    }

    public function testDeleteRule(): void
    {
        $id = $this->engine->addRule('alice', 'Test', 'on-complete');
        $ok = $this->engine->deleteRule('alice', $id);
        $this->assertTrue($ok);

        $this->assertCount(0, $this->engine->listRules('alice'));
    }

    public function testRuleIsolation(): void
    {
        $this->engine->addRule('alice', 'Alice Rule', 'on-complete');
        $this->engine->addRule('bob', 'Bob Rule', 'on-add');

        $this->assertCount(1, $this->engine->listRules('alice'));
        $this->assertCount(1, $this->engine->listRules('bob'));
    }

    // ---------------------------------------------------------------
    // Condition evaluation
    // ---------------------------------------------------------------

    public function testEvaluateEmptyConditions(): void
    {
        $this->assertTrue($this->engine->evaluateConditions(['name' => 'test'], []));
    }

    public function testEvaluateLabelCondition(): void
    {
        $torrent = ['labels' => ['movies', 'hd']];
        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'label', 'value' => 'movies'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'label', 'value' => 'music'
        ]));
    }

    public function testEvaluateNameContains(): void
    {
        $torrent = ['name' => 'Ubuntu 24.04 LTS'];
        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'name', 'value' => 'ubuntu', 'operator' => 'contains'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'name', 'value' => 'fedora', 'operator' => 'contains'
        ]));
    }

    public function testEvaluateNameRegex(): void
    {
        $torrent = ['name' => 'Ubuntu 24.04 LTS'];
        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'name', 'value' => '/ubuntu.*lts/i', 'operator' => 'regex'
        ]));
    }

    public function testEvaluateSizeCondition(): void
    {
        $torrent = ['totalSize' => 1073741824]; // 1 GB

        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'size', 'value' => '500000000', 'operator' => 'gt'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'size', 'value' => '2000000000', 'operator' => 'gt'
        ]));
    }

    public function testEvaluateTrackerCondition(): void
    {
        $torrent = ['trackers' => [
            ['announce' => 'https://tracker.example.com/announce']
        ]];

        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'tracker', 'value' => 'example.com'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'tracker', 'value' => 'other.com'
        ]));
    }

    public function testEvaluateStatusCondition(): void
    {
        $torrent = ['status' => 6]; // Seeding

        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'status', 'value' => '6'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'status', 'value' => '4'
        ]));
    }

    public function testEvaluateRatioCondition(): void
    {
        $torrent = ['uploadRatio' => 2.5];

        $this->assertTrue($this->engine->evaluateCondition($torrent, [
            'type' => 'ratio', 'value' => '2.0', 'operator' => 'gt'
        ]));
        $this->assertFalse($this->engine->evaluateCondition($torrent, [
            'type' => 'ratio', 'value' => '3.0', 'operator' => 'gt'
        ]));
    }

    public function testMultipleConditionsAndLogic(): void
    {
        $torrent = ['name' => 'Ubuntu 24.04', 'labels' => ['linux'], 'totalSize' => 1073741824];

        // All match
        $conditions = [
            ['type' => 'name', 'value' => 'ubuntu', 'operator' => 'contains'],
            ['type' => 'label', 'value' => 'linux'],
        ];
        $this->assertTrue($this->engine->evaluateConditions($torrent, $conditions));

        // One fails
        $conditions[] = ['type' => 'label', 'value' => 'windows'];
        $this->assertFalse($this->engine->evaluateConditions($torrent, $conditions));
    }

    // ---------------------------------------------------------------
    // Action execution
    // ---------------------------------------------------------------

    public function testActionStop(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->rpc->response = [];
        $this->engine->executeAction(['type' => 'stop'], $torrent, 'alice');

        $lastCall = $this->rpc->lastCall();
        $this->assertSame('torrent-stop', $lastCall['method']);
    }

    public function testActionLabel(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->rpc->response = [];
        $this->engine->executeAction(['type' => 'label', 'labels' => 'done,archived'], $torrent, 'alice');

        $lastCall = $this->rpc->lastCall();
        $this->assertSame('torrent-set', $lastCall['method']);
        $this->assertSame([1], $lastCall['arguments']['ids']);
        $this->assertContains('done', $lastCall['arguments']['labels']);
        $this->assertContains('archived', $lastCall['arguments']['labels']);
    }

    public function testActionRemove(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->rpc->response = [];
        $this->engine->executeAction(['type' => 'remove', 'delete_data' => false], $torrent, 'alice');

        $lastCall = $this->rpc->lastCall();
        $this->assertSame('torrent-remove', $lastCall['method']);
    }

    public function testActionMove(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->rpc->response = [];
        $this->engine->executeAction(['type' => 'move', 'path' => '/volume1/done'], $torrent, 'alice');

        $lastCall = $this->rpc->lastCall();
        $this->assertSame('torrent-set-location', $lastCall['method']);
        $this->assertSame([1], $lastCall['arguments']['ids']);
        $this->assertSame('/volume1/done', $lastCall['arguments']['location']);
        $this->assertTrue($lastCall['arguments']['move']);
    }

    public function testActionMoveNoPath(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->expectException(TransmissionException::class);
        $this->engine->executeAction(['type' => 'move', 'path' => ''], $torrent, 'alice');
    }

    public function testUnknownActionType(): void
    {
        $torrent = ['id' => 1, 'name' => 'Test'];
        $this->expectException(TransmissionException::class);
        $this->engine->executeAction(['type' => 'explode'], $torrent, 'alice');
    }

    // ---------------------------------------------------------------
    // Rule processing
    // ---------------------------------------------------------------

    public function testProcessRules(): void
    {
        $this->engine->addRule('alice', 'Stop seeded', 'on-complete', null, [
            ['type' => 'label', 'value' => 'auto']
        ], [
            ['type' => 'stop']
        ]);

        $torrents = [
            ['id' => 1, 'name' => 'Torrent A', 'labels' => ['auto'], 'status' => 6],
            ['id' => 2, 'name' => 'Torrent B', 'labels' => ['manual'], 'status' => 6],
        ];

        $this->rpc->response = [];
        $log = $this->engine->processRules('on-complete', $torrents);

        // Should only match Torrent A
        $this->assertCount(1, $log);
        $this->assertSame('Torrent A', $log[0]['torrent']);
        $this->assertContains('stop', $log[0]['actions']);
    }

    public function testProcessRulesNoMatch(): void
    {
        $this->engine->addRule('alice', 'Rule', 'on-complete', null, [
            ['type' => 'label', 'value' => 'nonexistent']
        ], [
            ['type' => 'stop']
        ]);

        $torrents = [
            ['id' => 1, 'name' => 'Test', 'labels' => ['other']],
        ];

        $log = $this->engine->processRules('on-complete', $torrents);
        $this->assertCount(0, $log);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function testValidActionTypes(): void
    {
        // These should not throw
        $valid = ['move', 'label', 'remove', 'stop', 'script', 'notify'];
        foreach ($valid as $type) {
            $id = $this->engine->addRule('alice', "Rule $type", 'on-complete', null, [], [
                ['type' => $type]
            ]);
            $this->assertGreaterThan(0, $id);
        }
    }

    public function testActionMissingType(): void
    {
        $this->expectException(TransmissionException::class);
        $this->engine->addRule('alice', 'Bad', 'on-complete', null, [], [
            ['path' => '/vol1']  // No 'type' key
        ]);
    }
}
