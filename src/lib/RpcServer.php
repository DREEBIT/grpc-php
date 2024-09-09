<?php
/*
 *
 * Copyright 2020 gRPC authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Grpc;

use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * An enhanced version of ```Grpc\RpcServer```.
 * It adds callbacks before and after the processing of an RPC event.
 * Also adds an interface for service implementations to expose their method descriptors.
 */
class RpcServer extends Server
{
    /**
     * Hook callback to be invoked before the event is processed by a service implementation.
     * @var string
     */
    const HOOK_BEFORE = 'beforeProcess';

    /**
     * Hook callback to be invoked after the event has been processed.
     * Notice that depending on the event type, the response may already be sent (i.e. server-side streaming).
     * @var string
     */
    const HOOK_AFTER = 'afterProcess';


    /**
     * @var array|string[]
     */
    private $allValidHooks = [
        self::HOOK_BEFORE,
        self::HOOK_AFTER,
    ];


    /**
     * [ <String method_full_path> => MethodDescriptor ]
     * @var array
     */
    private $paths_map = [];


    /**
     * [ <String hook> => [ ServerCallbackInterface ] ]
     * @var array
     */
    private $callbacks = [];


    /**
     * @var ErrorCallbackInterface|null
     */
    private $errorCallback = null;


    /**
     * Add a callback to any of the hooks defined above.
     * @param string $hook
     * @param ServerCallbackInterface $callback
     * @return void
     */
    public function addCallback(string $hook, ServerCallbackInterface $callback): void
    {
        if (!in_array($hook, $this->allValidHooks))
        {
            throw new InvalidArgumentException("Unknown hook '$hook'");
        }

        if (!isset($this->callbacks[$hook]))
        {
            $this->callbacks[$hook] = [];
        }

        $this->callbacks[$hook][] = $callback;
    }


    /**
     * Set a callback to invoke when an exception is caught while handling an RPC event.
     * @param ErrorCallbackInterface $callback
     * @return void
     */
    public function setErrorCallback(ErrorCallbackInterface $callback): void
    {
        $this->errorCallback = $callback;
    }


    /**
     * Some magic to do with calling the underlying c lib.
     * @return object|null
     */
    private function waitForNextEvent()
    {
        return $this->requestCall();
    }


    /**
     * Add a service implementation.
     * @param ServiceInterface $service
     * @return array
     */
    public function handle(ServiceInterface $service): array
    {
        $methodDescriptors = $service->getMethodDescriptors();

        $exist_methods = array_intersect_key($this->paths_map, $methodDescriptors);
        if (!empty($exist_methods)) {
            fwrite(STDERR, "WARNING: " . 'override already registered methods: ' .
                implode(', ', array_keys($exist_methods)) . PHP_EOL);
        }

        $this->paths_map = array_merge($this->paths_map, $methodDescriptors);
        return $this->paths_map;
    }


    /**
     * Start the server, listen for and process RPC events.
     * @return void
     */
    public function run(): void
    {
        $this->start();

        while (true) try {
            // This blocks until the server receives a request
            $event = $this->waitForNextEvent();

            $full_path = $event->method;
            $context = new ServerContext($event);
            $server_writer = new ServerCallWriter($event->call, $context);

            if (!array_key_exists($full_path, $this->paths_map))
            {
                $context->setStatus(Status::unimplemented());
                $server_writer->finish();
                continue;
            }

            $method_desc = $this->paths_map[$full_path];
            $server_reader = new ServerCallReader(
                $event->call,
                $method_desc->request_type
            );

            try
            {
                $this->triggerCallbacks(self::HOOK_BEFORE, $method_desc, $context, $server_reader, $server_writer);

                $this->processCall(
                    $method_desc,
                    $server_reader,
                    $server_writer,
                    $context
                );

                $this->triggerCallbacks(self::HOOK_AFTER, $method_desc, $context, $server_reader, $server_writer);
            }
            catch (Exception $e)
            {
                $context->setStatus(Status::status(
                    STATUS_INTERNAL,
                    $e->getMessage()
                ));

                if ($this->errorCallback)
                {
                    $this->errorCallback->invoke($e, $context);
                }

                $server_writer->finish();
            }
        }
        catch (Exception $e)
        {
            if ($this->errorCallback)
            {
                $this->errorCallback->invoke($e, null);
            }

            fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }


    /**
     * @param MethodDescriptor $method_desc
     * @param ServerCallReader $server_reader
     * @param ServerCallWriter $server_writer
     * @param ServerContext $context
     * @return void
     * @throws Exception
     */
    private function processCall(
        MethodDescriptor $method_desc,
        ServerCallReader $server_reader,
        ServerCallWriter $server_writer,
        ServerContext $context
    ): void
    {
        // Dispatch to actual server logic
        switch ($method_desc->call_type) {
            case MethodDescriptor::UNARY_CALL:
                $request = $server_reader->read();
                $response =
                    call_user_func(
                        array($method_desc->service, $method_desc->method_name),
                        $request ?? new $method_desc->request_type,
                        $context
                    );
                $server_writer->finish($response);
                break;
            case MethodDescriptor::SERVER_STREAMING_CALL:
                $request = $server_reader->read();
                call_user_func(
                    array($method_desc->service, $method_desc->method_name),
                    $request ?? new $method_desc->request_type,
                    $server_writer,
                    $context
                );
                break;
            case MethodDescriptor::CLIENT_STREAMING_CALL:
                $response = call_user_func(
                    array($method_desc->service, $method_desc->method_name),
                    $server_reader,
                    $context
                );
                $server_writer->finish($response);
                break;
            case MethodDescriptor::BIDI_STREAMING_CALL:
                call_user_func(
                    array($method_desc->service, $method_desc->method_name),
                    $server_reader,
                    $server_writer,
                    $context
                );
                break;
            default:
                throw new Exception('Unknown call type: ' . $method_desc->call_type);
        }
    }


    /**
     * @param string $hook
     * @param MethodDescriptor $method_desc
     * @param ServerContext $context
     * @param ServerCallReader $server_reader
     * @param ServerCallWriter $server_writer
     * @return void
     */
    private function triggerCallbacks(
        string $hook,
        MethodDescriptor $method_desc,
        ServerContext $context,
        ServerCallReader $server_reader,
        ServerCallWriter $server_writer
    ): void
    {
        if (!isset($this->callbacks[$hook]))
        {
            return;
        }

        foreach ($this->callbacks[$hook] as $callback)
        {
            if (!($callback instanceof ServerCallbackInterface))
            {
                throw new RuntimeException('Callback must implement ServerCallbackInterface');
            }

            $callback->invoke(
                $method_desc,
                $context,
                $server_reader,
                $server_writer
            );
        }
    }
}
