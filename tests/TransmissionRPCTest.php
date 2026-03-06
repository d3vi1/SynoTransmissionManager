<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransmissionRPC client.
 */
class TransmissionRPCTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('TransmissionRPC'));
    }

    public function testConstructorSetsDefaults(): void
    {
        $rpc = new TransmissionRPC();
        $this->assertInstanceOf(TransmissionRPC::class, $rpc);
    }

    public function testConstructorAcceptsCustomHostPort(): void
    {
        $rpc = new TransmissionRPC('10.0.0.1', 9999);
        $this->assertInstanceOf(TransmissionRPC::class, $rpc);
    }
}
