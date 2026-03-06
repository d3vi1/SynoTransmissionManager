<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Database class.
 */
class DatabaseTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('Database'));
    }

    public function testConstructorWithInMemoryDb(): void
    {
        $db = new Database(':memory:');
        $this->assertInstanceOf(Database::class, $db);
    }
}
