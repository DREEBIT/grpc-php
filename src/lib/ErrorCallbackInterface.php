<?php

namespace Grpc;

use Throwable;

/**
 * Interface to invoke a callback when an exception is thrown during handling of an RPC event.
 */
interface ErrorCallbackInterface
{
    /**
     * @param Throwable $exception
     * @param ServerContext|null $context
     * @return void
     */
    public function invoke(Throwable $exception, ?ServerContext $context = null): void;
}