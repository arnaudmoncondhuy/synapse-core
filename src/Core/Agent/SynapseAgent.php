<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Agent;

use ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;

/**
 * Représente une instance d'agent IA configurée et prête à l'emploi.
 * 
 * Cette classe est "Stateless" : elle ne persiste rien en base de données.
 * Elle wrappe le ChatService avec un preset virtuel.
 */
class SynapseAgent
{
    public function __construct(
        private ChatService $chatService,
        private SynapsePreset $preset,
        private ?string $systemPrompt = null,
        private array $allowedTools = [],
        private int $maxTurns = 5
    ) {}

    /**
     * Exécute une requête auprès de l'agent.
     *
     * @param string        $message Le message de l'utilisateur
     * @param array         $history Historique optionnel (OpenAI format)
     * @param callable|null $onToken Callback pour le streaming de tokens
     * 
     * @return array Résultat normalisé Synapse
     */
    public function ask(string $message, array $history = [], ?callable $onToken = null): array
    {
        $options = [
            'stateless' => true,
            'preset'    => $this->preset,
            'history'   => $history,
            'max_turns' => $this->maxTurns,
        ];

        if ($this->systemPrompt) {
            $options['system_prompt'] = $this->systemPrompt;
        }

        if (!empty($this->allowedTools)) {
            $options['tools_override'] = $this->allowedTools;
        }

        return $this->chatService->ask($message, $options, null, $onToken);
    }

    /**
     * Accesseur au preset (pour debug ou inspection).
     */
    public function getPreset(): SynapsePreset
    {
        return $this->preset;
    }
}
