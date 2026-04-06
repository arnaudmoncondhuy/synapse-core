<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\Exception\AgentDepthExceededException;
use ArnaudMoncondhuy\SynapseCore\Agent\Exception\AgentNotFoundException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Event\AgentDepthLimitReachedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Point d'entrée unique pour résoudre un agent par nom, quelle que soit son origine.
 *
 * Ordre de résolution :
 *   1. {@see CodeAgentRegistry} — agents "code" (classes PHP implémentant directement
 *      {@see AgentInterface}, fournies par le bundle ou l'application hôte et auto-découvertes
 *      via le tag DI `synapse.agent`).
 *   2. {@see AgentRegistry} — agents "config" (entité {@see SynapseAgent}
 *      persistée en BDD), enveloppés à la volée dans un {@see ConfiguredAgent}.
 *
 * Les agents code gagnent sur les agents BDD en cas de collision de nom : un agent code
 * représente un engagement de comportement par un développeur et n'est pas modifiable
 * depuis l'admin, donc il a préséance. Un warning est loggé dans ce cas.
 *
 * ## Garde-fou #5 : profondeur maximale
 *
 * Avant toute résolution, le resolver vérifie {@see AgentContext::isDepthExceeded()}.
 * Si la limite est atteinte, il dispatche {@see AgentDepthLimitReachedEvent} (hook non
 * bloquant) puis lève {@see AgentDepthExceededException}. La profondeur max est configurée
 * via le paramètre `synapse.agents.max_depth` (défaut : {@see AgentContext::DEFAULT_MAX_DEPTH}).
 *
 * ## Pas de migration vers `symfony/ai`
 *
 * L'API est alignée sur le vocabulaire de `symfony/ai` (méthode `call()`, VOs `Input`/`Output`),
 * mais aucune migration n'est prévue. Voir {@see AgentInterface} pour les détails.
 */
class AgentResolver
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CodeAgentRegistry $codeAgents,
        private readonly AgentRegistry $configAgents,
        private readonly ChatService $chatService,
        private readonly EventDispatcherInterface $eventDispatcher,
        #[Lazy]
        private readonly WorkflowRunner $workflowRunner,
        private readonly SynapseWorkflowRepository $workflowRepo,
        #[Autowire('%synapse.agents.max_depth%')]
        private readonly int $maxDepth = AgentContext::DEFAULT_MAX_DEPTH,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Crée un contexte racine avec la profondeur maximale configurée dans `synapse.agents.max_depth`.
     *
     * C'est le point d'entrée recommandé pour un appel programmatique d'agent (CLI, Messenger
     * handler, MCP tool, contrôleur). Pour un appel imbriqué depuis un agent, utiliser plutôt
     * {@see AgentContext::createChild()} pour préserver la traçabilité parent/enfant.
     */
    public function createRootContext(
        ?string $userId = null,
        ?int $budgetTokensRemaining = null,
        string $origin = 'direct',
    ): AgentContext {
        return AgentContext::root(
            userId: $userId,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $budgetTokensRemaining,
            origin: $origin,
        );
    }

    /**
     * Retourne la profondeur maximale autorisée (valeur du paramètre `synapse.agents.max_depth`).
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Résout un agent par nom et retourne une instance exécutable.
     *
     * @throws AgentDepthExceededException si $context->isDepthExceeded() (garde-fou #5)
     * @throws AgentNotFoundException si aucun agent code ni BDD ne porte ce nom
     */
    public function resolve(string $name, AgentContext $context): AgentInterface
    {
        if ($context->isDepthExceeded()) {
            $this->eventDispatcher->dispatch(new AgentDepthLimitReachedEvent($name, $context));
            throw AgentDepthExceededException::forContext($name, $context);
        }

        $codeAgent = $this->codeAgents->get($name);
        if (null !== $codeAgent) {
            if (null !== $this->configAgents->get($name)) {
                $this->logger->warning(
                    'Agent name collision: a code agent and a config agent both declare "{name}". The code agent takes precedence.',
                    ['name' => $name],
                );
            }

            return $codeAgent;
        }

        $configEntity = $this->configAgents->get($name);
        if (null !== $configEntity) {
            if (null !== $configEntity->getWorkflowKey()) {
                return $this->resolveWorkflowAgent($configEntity);
            }

            return new ConfiguredAgent($configEntity, $this->chatService);
        }

        throw AgentNotFoundException::forName($name);
    }

    /**
     * Indique si un agent est résolvable sous ce nom (code ou BDD).
     *
     * N'applique PAS le check de profondeur : utile pour un listing admin ou un test
     * d'existence sans contrainte de contexte.
     */
    public function has(string $name): bool
    {
        return $this->codeAgents->has($name) || null !== $this->configAgents->get($name);
    }

    /**
     * Résout un agent-workflow : l'entité agent a un `workflowKey`, on retourne un
     * {@see WorkflowDelegatingAgent} qui délègue l'exécution au workflow correspondant.
     */
    private function resolveWorkflowAgent(SynapseAgent $agent): AgentInterface
    {
        $workflowKey = $agent->getWorkflowKey();
        \assert(null !== $workflowKey);

        $workflow = $this->workflowRepo->findActiveByKey($workflowKey);
        if (null === $workflow) {
            throw AgentNotFoundException::forName(sprintf('%s (workflow "%s" not found or inactive)', $agent->getKey(), $workflowKey));
        }

        return new WorkflowDelegatingAgent($agent, $workflow, $this->workflowRunner);
    }
}
