<?php

declare(strict_types = 1);

namespace Grpc\PhpUnit;

use Exception;
use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\UnaryCall;
use RuntimeException;

class SimpleClient extends BaseStub
{
    /**
     * Map methods to expected response messages classes.
     * @var array
     */
    private $responseMessageMap = [];


    /**
     * @param string $hostname
     * @param array $opts
     * @param Channel|null $channel
     * @throws Exception
     */
    public function __construct(string $hostname = 'grpc:9501', array $opts = [], $channel = null)
    {
        $opts['credentials'] = ChannelCredentials::createInsecure();

        parent::__construct($hostname, $opts, $channel);
    }


    public function setResponseMessageMap(array $mapping): void
    {
        $this->responseMessageMap = $mapping;
    }


    /**
     * @param string $method
     * @param Message $request
     * @param array $metadata
     * @param array $options
     * @return UnaryCall
     */
    public function unary(string $method, Message $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest($method, $request,
            [$this->getResponseMessageFQN($method), 'mergeFromString'],
            $metadata, $options
        );
    }


    /**
     * @param $method
     * @return string
     * @throws RuntimeException
     */
    protected function getResponseMessageFQN($method): string
    {
        if (!isset($this->responseMessageMap[$method]))
        {
            throw new RuntimeException(sprintf('No response message class defined for call `%s`', $method));
        }

        return $this->responseMessageMap[$method];
    }
}