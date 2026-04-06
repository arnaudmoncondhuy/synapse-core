<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Architect;

use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique les propositions structurées de l'{@see ArchitectAgent} — Phase 11.
 *
 * Ce service est le pont entre la sortie JSON de l'architecte et les entités
 * Doctrine. Chaque proposition passe par le pipeline HITL existant :
 *
 *   - **Création d'agent** : l'agent est créé **inactif** avec une version
 *     de prompt **en pending** (Garde-fou #3). Le LLM-as-Judge (Garde-fou #2)
 *     est invoqué automatiquement par le {@see PromptVersionRecorder}.
 *     L'admin doit approuver pour que l'agent devienne opérationnel.
 *
 *   - **Amélioration de prompt** : une version pending est créée via
 *     `PromptVersionRecorder::snapshot(pending: true)`. Le prompt live de
 *     l'agent n'est pas modifié tant que la version n'est pas approuvée.
 *
 *   - **Création de workflow** : le workflow est créé **inactif**. L'admin
 *     doit le passer actif après revue.
 *
 * ## Audit trail
 *
 * Toutes les entités créées utilisent la convention `changedBy` = `agent:architect`
 * pour tracer l'origine dans l'historique.
 */
class ArchitectProposalProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly PromptVersionRecorder $promptVersionRecorder,
    ) {
    }

    /**
     * Dispatche une proposition vers le handler approprié selon `_action`.
     *
     * @param array<string, mixed> $proposal sortie structurée de l'ArchitectAgent
     *
     * @throws \InvalidArgumentException si la proposition est invalide
     *
     * @return array{type: string, entity: SynapseAgent|SynapseWorkflow, message: string}
     */
    public function process(array $proposal, string $changedBy = 'agent:architect'): array
    {
        $action = $proposal['_action'] ?? null;
        if (!is_string($action)) {
            throw new \InvalidArgumentException('Proposition invalide : clé "_action" manquante.');
        }

        return match ($action) {
            'create_agent' => $this->processCreateAgent($proposal, $changedBy),
            'improve_prompt' => $this->processImprovePrompt($proposal, $changedBy),
            'create_workflow' => $this->processCreateWorkflow($proposal, $changedBy),
            default => throw new \InvalidArgumentException(sprintf('Action de proposition inconnue : "%s".', $action)),
        };
    }

    /**
     * Crée un nouvel agent inactif + version prompt en pending.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseAgent, message: string}
     */
    private function processCreateAgent(array $proposal, string $changedBy): array
    {
        $key = $this->requireString($proposal, 'key', 'Clé d\'agent');
        $name = $this->requireString($proposal, 'name', 'Nom d\'agent');
        $systemPrompt = $this->requireString($proposal, 'system_prompt', 'System prompt');

        $existing = $this->agentRepository->findByKey($key);
        if (null !== $existing) {
            throw new \InvalidArgumentException(sprintf('Un agent avec la clé "%s" existe déjà.', $key));
        }

        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName($name);
        $agent->setEmoji(is_string($proposal['emoji'] ?? null) ? $proposal['emoji'] : '🤖');
        $agent->setDescription(is_string($proposal['description'] ?? null) ? $proposal['description'] : '');
        $agent->setSystemPrompt($systemPrompt);
        $agent->setIsBuiltin(false);
        $agent->setIsActive(false); // Inactif — attend approbation admin

        $this->entityManager->persist($agent);

        // Snapshot le prompt en pending (HITL) — déclenche le LLM-as-Judge
        $reason = is_string($proposal['reasoning'] ?? null) ? $proposal['reasoning'] : null;
        $this->promptVersionRecorder->snapshot(
            agent: $agent,
            newPrompt: $systemPrompt,
            changedBy: $changedBy,
            reason: $reason,
            flush: false,
            pending: true,
        );

        $this->entityManager->flush();

        return [
            'type' => 'create_agent',
            'entity' => $agent,
            'message' => sprintf('Agent "%s" créé (inactif, prompt en attente de validation).', $name),
        ];
    }

    /**
     * Crée une version pending pour un agent existant.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseAgent, message: string}
     */
    private function processImprovePrompt(array $proposal, string $changedBy): array
    {
        $newPrompt = $this->requireString($proposal, 'new_system_prompt', 'Nouveau prompt');

        // Retrouver l'agent — le _agent_key est ajouté par le CLI/caller
        $agentKey = $proposal['_agent_key'] ?? null;
        if (!is_string($agentKey) || '' === $agentKey) {
            throw new \InvalidArgumentException('Clé "_agent_key" manquante dans la proposition — le caller doit la renseigner.');
        }

        $agent = $this->agentRepository->findByKey($agentKey);
        if (null === $agent) {
            throw new \InvalidArgumentException(sprintf('Agent "%s" introuvable.', $agentKey));
        }

        $reason = is_string($proposal['changes_summary'] ?? null) ? $proposal['changes_summary'] : null;
        $version = $this->promptVersionRecorder->snapshot(
            agent: $agent,
            newPrompt: $newPrompt,
            changedBy: $changedBy,
            reason: $reason,
            flush: true,
            pending: true,
        );

        $message = null !== $version
            ? sprintf('Proposition de prompt soumise pour l\'agent "%s" (version #%s, en attente de validation).', $agent->getName(), (string) $version->getId())
            : sprintf('Prompt identique à la version actuelle de l\'agent "%s" — aucune modification.', $agent->getName());

        return [
            'type' => 'improve_prompt',
            'entity' => $agent,
            'message' => $message,
        ];
    }

    /**
     * Crée un nouveau workflow inactif.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseWorkflow, message: string}
     */
    private function processCreateWorkflow(array $proposal, string $changedBy): array
    {
        $key = $this->requireString($proposal, 'key', 'Clé de workflow');
        $name = $this->requireString($proposal, 'name', 'Nom de workflow');

        $steps = $proposal['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            throw new \InvalidArgumentException('Le workflow doit contenir au moins une étape.');
        }

        $definition = [
            'version' => 1,
            'description' => is_string($proposal['description'] ?? null) ? $proposal['description'] : '',
            'steps' => $steps,
        ];

        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey($key);
        $workflow->setName($name);
        $workflow->setDescription(is_string($proposal['description'] ?? null) ? $proposal['description'] : null);
        $workflow->setDefinition($definition);
        $workflow->setIsBuiltin(false);
        $workflow->setIsActive(false); // Inactif — attend activation admin

        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        return [
            'type' => 'create_workflow',
            'entity' => $workflow,
            'message' => sprintf('Workflow "%s" créé (inactif, en attente d\'activation par un admin).', $name),
        ];
    }

    /**
     * Extrait une chaîne requise depuis la proposition.
     *
     * @param array<string, mixed> $proposal
     */
    private function requireString(array $proposal, string $key, string $label): string
    {
        $value = $proposal[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('%s manquant ou vide dans la proposition (clé : "%s").', $label, $key));
        }

        return trim($value);
    }
}
