<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use PHPUnit\Framework\TestCase;

class SynapseConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new SynapseConfig();

        $this->assertNull($config->getId());
        $this->assertSame(30, $config->getRetentionDays());
        $this->assertSame('fr', $config->getContextLanguage());
        $this->assertNull($config->getSystemPrompt());
        $this->assertFalse($config->isDebugMode());
        $this->assertNull($config->getEmbeddingProvider());
        $this->assertNull($config->getEmbeddingModel());
        $this->assertNull($config->getEmbeddingDimension());
        $this->assertSame('doctrine', $config->getVectorStore());
        $this->assertSame('recursive', $config->getChunkingStrategy());
        $this->assertSame(1000, $config->getChunkSize());
        $this->assertSame(200, $config->getChunkOverlap());
        $this->assertTrue($config->isSpendingLimitsEnabled());
        $this->assertSame(5, $config->getMaxTurns());
        $this->assertNull($config->getMasterPrompt());
        $this->assertTrue($config->isMasterPromptStateless());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $config = new SynapseConfig();

        $config->setRetentionDays(90)
            ->setContextLanguage('en')
            ->setSystemPrompt('Custom prompt')
            ->setDebugMode(true)
            ->setEmbeddingProvider('openai')
            ->setEmbeddingModel('text-embedding-3-small')
            ->setEmbeddingDimension(1536)
            ->setVectorStore('pgvector')
            ->setChunkingStrategy('fixed')
            ->setChunkSize(500)
            ->setChunkOverlap(100)
            ->setSpendingLimitsEnabled(false)
            ->setMaxTurns(10)
            ->setMasterPrompt('Master directive')
            ->setMasterPromptStateless(false);

        $this->assertSame(90, $config->getRetentionDays());
        $this->assertSame('en', $config->getContextLanguage());
        $this->assertSame('Custom prompt', $config->getSystemPrompt());
        $this->assertTrue($config->isDebugMode());
        $this->assertSame('openai', $config->getEmbeddingProvider());
        $this->assertSame('text-embedding-3-small', $config->getEmbeddingModel());
        $this->assertSame(1536, $config->getEmbeddingDimension());
        $this->assertSame('pgvector', $config->getVectorStore());
        $this->assertSame('fixed', $config->getChunkingStrategy());
        $this->assertSame(500, $config->getChunkSize());
        $this->assertSame(100, $config->getChunkOverlap());
        $this->assertFalse($config->isSpendingLimitsEnabled());
        $this->assertSame(10, $config->getMaxTurns());
        $this->assertSame('Master directive', $config->getMasterPrompt());
        $this->assertFalse($config->isMasterPromptStateless());
    }

    public function testMaxTurnsEnforcesMinimumOfOne(): void
    {
        $config = new SynapseConfig();
        $config->setMaxTurns(0);
        $this->assertSame(1, $config->getMaxTurns());

        $config->setMaxTurns(-5);
        $this->assertSame(1, $config->getMaxTurns());
    }

    public function testToArray(): void
    {
        $config = new SynapseConfig();
        $config->setRetentionDays(60)
            ->setContextLanguage('en')
            ->setSystemPrompt('SP')
            ->setDebugMode(true)
            ->setMasterPrompt('MP')
            ->setMasterPromptStateless(false)
            ->setMaxTurns(3);

        $arr = $config->toArray();

        $this->assertSame(60, $arr['retention']['days']);
        $this->assertSame('en', $arr['context']['language']);
        $this->assertSame('SP', $arr['system_prompt']);
        $this->assertSame('MP', $arr['master_prompt']);
        $this->assertFalse($arr['master_prompt_stateless']);
        $this->assertTrue($arr['debug_mode']);
        $this->assertSame(3, $arr['max_turns']);
    }
}
