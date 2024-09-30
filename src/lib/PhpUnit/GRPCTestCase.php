<?php

declare(strict_types=1);

namespace Grpc\PhpUnit;

use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class GRPCTestCase extends TestCase
{
    private static $responseMessageMapping = [];


    /**
     * @return void
     * @throws Throwable
     */
    public static function setUpBeforeClass(): void
    {
        self::$responseMessageMapping = [];
    }


    /**
     * Set response message class expected per call.
     * @param array $mapping
     * @return void
     */
    protected static function setResponseMessageMapping(array $mapping): void
    {
        self::$responseMessageMapping = $mapping;
    }

    /**
     * @param string $method
     * @param Message $requestMessage
     * @return Message|null
     * @throws RuntimeException
     * @throws RequestFailedException
     */
    protected function request(string $method, Message $requestMessage): ?Message
    {
        $client = new SimpleClient();
        $client->setResponseMessageMap(self::$responseMessageMapping);

        [$response, $status] = $client->unary($method, $requestMessage)->wait();

        $client->close();

        if ($status->code !== 0)
        {
            throw new RequestFailedException(
                sprintf('GRPC Request failed. Status code %d, message: %s', $status->code, $status->details),
                $status->code
            );
        }

        return $response;
    }
}