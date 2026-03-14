<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\ToolExecutionSubscriber;
use PHPUnit\Framework\TestCase;

class ToolExecutionSubscriberTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Exécution normale
    // -------------------------------------------------------------------------

    public function testExecutesToolAndRegistersResult(): void
    {
        $tool = $this->buildTool('get_weather', 'Paris ensoleillé');
        $registry = $this->buildRegistry(['get_weather' => $tool]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'get_weather', 'args' => ['city' => 'Paris']],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame(['get_weather' => 'Paris ensoleillé'], $event->getResults());
    }

    public function testExecutesMultipleToolsInSingleEvent(): void
    {
        $registry = $this->buildRegistry([
            'tool_a' => $this->buildTool('tool_a', 'result_a'),
            'tool_b' => $this->buildTool('tool_b', 'result_b'),
        ]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'tool_a', 'args' => []],
            ['id' => 'call_2', 'name' => 'tool_b', 'args' => []],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame('result_a', $event->getResults()['tool_a']);
        $this->assertSame('result_b', $event->getResults()['tool_b']);
    }

    public function testPassesArgsToTool(): void
    {
        $receivedArgs = null;
        $tool = $this->createStub(AiToolInterface::class);
        $tool->method('getName')->willReturn('calculator');
        $tool->method('execute')->willReturnCallback(function (array $args) use (&$receivedArgs) {
            $receivedArgs = $args;

            return 42;
        });

        $registry = $this->buildRegistry(['calculator' => $tool]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'calculator', 'args' => ['a' => 2, 'b' => 3]],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame(['a' => 2, 'b' => 3], $receivedArgs);
    }

    // -------------------------------------------------------------------------
    // Outil introuvable
    // -------------------------------------------------------------------------

    public function testRegistersNullWhenToolNotFound(): void
    {
        $registry = $this->buildRegistry([]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'inexistant', 'args' => []],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame(['inexistant' => null], $event->getResults());
    }

    // -------------------------------------------------------------------------
    // Préfixe "functions." (normalisation LLM)
    // -------------------------------------------------------------------------

    public function testStripsFunctionsPrefixFromToolName(): void
    {
        $tool = $this->buildTool('propose_to_remember', 'ok');
        $registry = $this->buildRegistry(['propose_to_remember' => $tool]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'functions.propose_to_remember', 'args' => []],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        // Résultat enregistré sous le nom d'origine (avec préfixe)
        $results = $event->getResults();
        $this->assertNotEmpty($results);
        $this->assertSame('ok', reset($results));
    }

    // -------------------------------------------------------------------------
    // Nom vide — ignoré
    // -------------------------------------------------------------------------

    public function testSkipsToolCallWithEmptyName(): void
    {
        $registry = $this->buildRegistry([]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => '', 'args' => []],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertEmpty($event->getResults());
    }

    public function testSkipsToolCallWithMissingName(): void
    {
        $registry = $this->buildRegistry([]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'args' => []], // 'name' absent
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertEmpty($event->getResults());
    }

    // -------------------------------------------------------------------------
    // Types de retour sérialisables
    // -------------------------------------------------------------------------

    public function testStringResultIsPreserved(): void
    {
        $registry = $this->buildRegistry(['t' => $this->buildTool('t', 'texte')]);
        $event = new SynapseToolCallRequestedEvent([['id' => '1', 'name' => 't', 'args' => []]]);
        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame('texte', $event->getResults()['t']);
    }

    public function testArrayResultIsPreserved(): void
    {
        $registry = $this->buildRegistry(['t' => $this->buildTool('t', ['key' => 'value'])]);
        $event = new SynapseToolCallRequestedEvent([['id' => '1', 'name' => 't', 'args' => []]]);
        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame(['key' => 'value'], $event->getResults()['t']);
    }

    public function testScalarResultIsCastToString(): void
    {
        $registry = $this->buildRegistry(['t' => $this->buildTool('t', 42)]);
        $event = new SynapseToolCallRequestedEvent([['id' => '1', 'name' => 't', 'args' => []]]);
        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertSame('42', $event->getResults()['t']);
    }

    public function testAreAllResultsRegisteredAfterExecution(): void
    {
        $registry = $this->buildRegistry([
            'a' => $this->buildTool('a', 'ok'),
            'b' => $this->buildTool('b', 'ok'),
        ]);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => '1', 'name' => 'a', 'args' => []],
            ['id' => '2', 'name' => 'b', 'args' => []],
        ]);

        (new ToolExecutionSubscriber($registry))->onToolCallRequested($event);

        $this->assertTrue($event->areAllResultsRegistered());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, AiToolInterface> $tools */
    private function buildRegistry(array $tools): ToolRegistry
    {
        $registry = $this->createStub(ToolRegistry::class);
        $registry->method('get')->willReturnCallback(
            fn (string $name) => $tools[$name] ?? null
        );

        return $registry;
    }

    private function buildTool(string $name, mixed $returnValue): AiToolInterface
    {
        $tool = $this->createStub(AiToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('execute')->willReturn($returnValue);

        return $tool;
    }
}
