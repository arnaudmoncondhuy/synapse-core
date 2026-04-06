<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Message;

/**
 * Message Messenger déclenchant l'exécution asynchrone d'un workflow (Phase 9).
 *
 * Le message porte **uniquement des scalaires et des tableaux scalaires** afin de
 * rester sérialisable via Messenger et transportable sur n'importe quelle file
 * (Doctrine aujourd'hui, Redis demain sans changement de code applicatif — seule
 * la config framework `messenger.transports` bascule).
 *
 * Le routage par défaut du bundle envoie ce message sur le transport `synapse_async`
 * (déjà utilisé par {@see TestPresetMessage} et {@see ReindexRagSourceMessage}).
 * L'application hôte peut redéfinir le transport sans toucher au bundle.
 *
 * ## Pourquoi un workflowKey, pas un workflowId ?
 *
 * L'id auto-incrémenté est un détail de stockage. La clé métier (slug unique) est
 * stable, lisible, et survit à une resynchronisation de seed ou à un changement
 * de backend. C'est la même décision que pour `TestPresetMessage::$presetId` —
 * qui est d'ailleurs le seul exemple à utiliser un id, et qu'on évitera de
 * reproduire sur les nouveaux messages.
 *
 * ## Pas de `SynapseWorkflowRun` pré-créé
 *
 * On ne crée **pas** le run côté caller synchrone avant le dispatch. C'est
 * {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner::run()},
 * appelé depuis le handler, qui crée le run avec son UUID. L'appelant du dispatch
 * n'a donc pas d'identifiant de run à retourner immédiatement — c'est une
 * caractéristique assumée de l'async : pour suivre un run, l'UI recharge la liste
 * des runs du workflow.
 *
 * Si un cas d'usage exige un identifiant corrélatif retourné immédiatement après
 * dispatch, une évolution ultérieure pourra générer un pré-UUID côté caller et
 * le passer dans ce message.
 */
final class ExecuteWorkflowMessage
{
    /**
     * @param string $workflowKey clé métier (slug) du {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow} à exécuter
     * @param array<string, mixed> $structuredInput payload passé à {@see \ArnaudMoncondhuy\SynapseCore\Agent\Input::ofStructured()}
     * @param string|null $userId Utilisateur déclencheur (null = système / CLI / cron). Persiste dans `SynapseWorkflowRun::$userId`.
     * @param string $message message texte optionnel — ignoré si `$structuredInput` est non-vide (aligné sur `MultiAgent::buildInitialInputs()`)
     */
    public function __construct(
        private readonly string $workflowKey,
        private readonly array $structuredInput = [],
        private readonly ?string $userId = null,
        private readonly string $message = '',
    ) {
    }

    public function getWorkflowKey(): string
    {
        return $this->workflowKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStructuredInput(): array
    {
        return $this->structuredInput;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
