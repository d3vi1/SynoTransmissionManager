<?php

declare(strict_types=1);

namespace Tests\Support;

use TransmissionRPC;

/**
 * Testable subclass that captures RPC calls instead of making HTTP requests.
 *
 * Overrides the protected request() method to record the method name and
 * arguments, then returns a configurable response (or throws an exception).
 */
class TestableTransmissionRPC extends TransmissionRPC
{
    /** @var array[] Captured calls: [['method' => ..., 'arguments' => ...], ...] */
    public $calls = [];

    /** @var array|null Canned response to return from request() */
    public $response = [];

    /** @var \Exception|null If set, request() throws this instead of returning */
    public $exception = null;

    protected function request(string $method, array $arguments = []): array
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }

    /** Helper: return the last captured call. */
    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}
