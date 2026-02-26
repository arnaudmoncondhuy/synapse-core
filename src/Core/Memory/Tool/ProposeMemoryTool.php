<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Memory\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;

/**
 * Outil permettant au LLM de proposer de retenir une information sur l'utilisateur.
 * 
 * Cet outil ne sauvegarde rien directement. Il renvoie un signal spécial que le 
 * frontend doit intercepter pour demander confirmation à l'utilisateur.
 */
class ProposeMemoryTool implements AiToolInterface
{
    public function getName(): string
    {
        return 'propose_to_remember';
    }

    public function getDescription(): string
    {
        return "Propose de mémoriser un fait important pour l'utilisateur. " .
            "Utilisez cet outil quand l'utilisateur partage une préférence, une information personnelle ou une contrainte " .
            "qu'il serait utile de retenir pour les prochaines conversations.";
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fact' => [
                    'type' => 'string',
                    'description' => 'Le fait ou la préférence à retenir, formulé de manière concise et claire.'
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['preference', 'constraint', 'context', 'other'],
                    'description' => 'La catégorie du souvenir.'
                ],
            ],
            'required' => ['fact']
        ];
    }

    public function execute(array $parameters): mixed
    {
        return [
            '__synapse_action' => 'memory_proposal',
            'fact' => $parameters['fact'],
            'category' => $parameters['category'] ?? 'other',
            'status' => 'pending_user_confirmation'
        ];
    }
}
