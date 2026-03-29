<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseTokenStreamedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Représente une instance d'agent IA configurée et prête à l'emploi.
 *
 * Cette classe est "Stateless" : elle ne persiste rien en base de données.
 * Elle wrappe le ChatService avec un preset virtuel.
 */
class SynapseAgent
{
    /**
     * @param string[] $allowedTools
     */
    public function __construct(
        private readonly ChatService $chatService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SynapseModelPreset $preset,
        private readonly ?string $systemPrompt = null,
        private readonly array $allowedTools = [],
        private readonly int $maxTurns = 5,
    ) {
    }

    /**
     * Exécute une requête auprès de l'agent.
     *
     * @param string $message Le message de l'utilisateur
     * @param array<int, array<string, mixed>> $history Historique optionnel (OpenAI format)
     * @param callable|null $onToken Callback optionnel pour le streaming de tokens
     * @param array<string, mixed> $options Options supplémentaires pour la requête
     *
     * @return array<string, mixed> Résultat normalisé Synapse
     */
    public function ask(string $message, array $history = [], ?callable $onToken = null, array $options = []): array
    {
        $options = array_merge([
            'stateless' => true,
            'preset' => $this->preset,
            'history' => $history,
            'max_turns' => $this->maxTurns,
        ], $options);

        if ($this->systemPrompt) {
            $options['system_prompt'] = $this->systemPrompt;
        }

        if (!empty($this->allowedTools)) {
            $options['tools_override'] = $this->allowedTools;
        }

        /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: SynapseModelPreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool} $chatOptions */
        $chatOptions = $options;

        // Support du callback onToken via un listener temporaire sur l'event
        if (null !== $onToken) {
            $tokenListener = function (SynapseTokenStreamedEvent $e) use ($onToken): void {
                $onToken($e->token);
            };
            $this->dispatcher->addListener(SynapseTokenStreamedEvent::class, $tokenListener);
        }

        try {
            return $this->chatService->ask($message, $chatOptions);
        } finally {
            if (null !== $onToken) {
                $this->dispatcher->removeListener(SynapseTokenStreamedEvent::class, $tokenListener);
            }
        }
    }

    /**
     * Accesseur au preset (pour debug ou inspection).
     */
    public function getPreset(): SynapseModelPreset
    {
        return $this->preset;
    }
}
