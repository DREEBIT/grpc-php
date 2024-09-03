<?php

namespace Grpc;

/**
 * Interface to invoke a callback within the processing of an RPC event.
 */
interface ServerCallbackInterface
{
    /**
     * @param MethodDescriptor $method_desc
     * @param ServerContext $context
     * @param ServerCallReader $server_reader
     * @param ServerCallWriter $server_writer
     * @return void
     */
    public function invoke(
        MethodDescriptor $method_desc,
        ServerContext $context,
        ServerCallReader $server_reader,
        ServerCallWriter $server_writer
    ): void;
}