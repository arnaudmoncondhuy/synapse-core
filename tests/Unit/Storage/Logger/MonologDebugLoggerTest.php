<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Logger;

use ArnaudMoncondhuy\SynapseCore\Storage\Logger\MonologDebugLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MonologDebugLoggerTest extends TestCase
{
    private LoggerInterface $psrLogger;
    private MonologDebugLogger $logger;

    protected function setUp(): void
    {
        $this->psrLogger = $this->createMock(LoggerInterface::class);
        $this->logger = new MonologDebugLogger($this->psrLogger);
    }

    public function testLogExchangeDelegatesToPsrLogger(): void
    {
        $this->psrLogger->expects($this->once())
            ->method('info')
            ->with(
                'Synapse LLM Exchange',
                $this->callback(function (array $context): bool {
                    return 'dbg_789' === $context['debug_id']
                        && 'conv_1' === $context['conversation_id']
                        && isset($context['payload']);
                })
            );

        $this->logger->logExchange(
            'dbg_789',
            ['conversation_id' => 'conv_1'],
            ['request' => 'hello']
        );
    }

    public function testFindByDebugIdAlwaysReturnsNull(): void
    {
        $this->assertNull($this->logger->findByDebugId('any_id'));
    }

    public function testLogExchangeMergesMetadataAndPayload(): void
    {
        $this->psrLogger->expects($this->once())
            ->method('info')
            ->with(
                'Synapse LLM Exchange',
                $this->callback(function (array $context): bool {
                    return 'dbg_test' === $context['debug_id']
                        && 'extra_value' === $context['extra_key']
                        && ['foo' => 'bar'] === $context['payload'];
                })
            );

        $this->logger->logExchange(
            'dbg_test',
            ['extra_key' => 'extra_value'],
            ['foo' => 'bar']
        );
    }
}
