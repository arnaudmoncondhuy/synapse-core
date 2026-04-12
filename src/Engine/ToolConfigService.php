<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ToolAvailability;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseToolConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToolConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service d'accès et de synchronisation de la configuration des outils.
 *
 * Centralise la lecture (runtime : filtre des définitions à exposer au LLM)
 * et la synchronisation entre le {@see ToolRegistry} (source de vérité côté code)
 * et la table `synapse_tool_config` (overrides administratifs).
 *
 * Règles de synchronisation ({@see self::sync()}) :
 * - outil en code sans ligne en base   → création avec `ACTIVE` (rétro-compatible) ;
 * - outil en base sans classe en code → suppression (cleanup des orphelins).
 */
class ToolConfigService
{
    /**
     * Cache local de la map [toolName => ToolAvailability] pour éviter les
     * requêtes multiples dans une même requête HTTP.
     *
     * @var array<string, ToolAvailability>|null
     */
    private ?array $availabilityCache = null;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly SynapseToolConfigRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Retourne le niveau de disponibilité d'un outil.
     *
     * Si aucune ligne n'existe en base pour ce nom (par exemple parce que
     * sync() n'a pas encore été appelé), on considère l'outil ACTIVE pour
     * préserver la rétro-compatibilité.
     */
    public function getAvailability(string $toolName): ToolAvailability
    {
        return $this->getAvailabilityMap()[$toolName] ?? ToolAvailability::ACTIVE;
    }

    /**
     * Retourne la map [toolName => ToolAvailability] (cache in-memory).
     *
     * @return array<string, ToolAvailability>
     */
    public function getAvailabilityMap(): array
    {
        if (null === $this->availabilityCache) {
            $this->availabilityCache = $this->repository->getAvailabilityMap();
        }

        return $this->availabilityCache;
    }

    /**
     * Filtre une liste de noms de tools pour ne garder que ceux exposables
     * dans le contexte donné.
     *
     * - `$hasAgentWhitelist === false` : on retire les outils `AGENT_ONLY` et
     *   `DISABLED` (seuls les `ACTIVE` passent) ;
     * - `$hasAgentWhitelist === true`  : on retire uniquement les `DISABLED`
     *   (un agent peut volontairement whitelister un outil `AGENT_ONLY`).
     *
     * @param list<string> $toolNames
     *
     * @return list<string>
     */
    public function filterToolNames(array $toolNames, bool $hasAgentWhitelist): array
    {
        $map = $this->getAvailabilityMap();
        $filtered = [];
        foreach ($toolNames as $name) {
            $availability = $map[$name] ?? ToolAvailability::ACTIVE;
            if (ToolAvailability::DISABLED === $availability) {
                continue;
            }
            if (ToolAvailability::AGENT_ONLY === $availability && !$hasAgentWhitelist) {
                continue;
            }
            $filtered[] = $name;
        }

        return $filtered;
    }

    /**
     * Retourne la liste des noms d'outils exposables par défaut (tchat normal).
     *
     * C'est-à-dire tous les outils `ACTIVE` du registre, à l'exclusion des
     * `DISABLED` et `AGENT_ONLY`.
     *
     * @return list<string>
     */
    public function getDefaultExposedToolNames(): array
    {
        $map = $this->getAvailabilityMap();
        $names = [];
        foreach (array_keys($this->toolRegistry->getTools()) as $name) {
            $availability = $map[$name] ?? ToolAvailability::ACTIVE;
            if (ToolAvailability::ACTIVE === $availability) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Met à jour le niveau de disponibilité d'un outil (créé à la volée si absent).
     */
    public function setAvailability(string $toolName, ToolAvailability $availability): void
    {
        $config = $this->repository->findByToolName($toolName);
        if (null === $config) {
            $config = new SynapseToolConfig($toolName, $availability);
            $this->em->persist($config);
        } else {
            $config->setAvailability($availability);
        }
        $this->em->flush();
        $this->availabilityCache = null;
    }

    /**
     * Synchronise la table `synapse_tool_config` avec le registre de code.
     *
     * Retourne un rapport [created => [...], removed => [...]] pour affichage
     * admin (permet de détecter les outils ajoutés/supprimés depuis le dernier
     * passage).
     *
     * @return array{created: list<string>, removed: list<string>}
     */
    public function sync(): array
    {
        $registryNames = array_keys($this->toolRegistry->getTools());
        $existing = $this->repository->findAll();

        $existingByName = [];
        foreach ($existing as $config) {
            $existingByName[$config->getToolName()] = $config;
        }

        $created = [];
        foreach ($registryNames as $name) {
            if (!isset($existingByName[$name])) {
                $this->em->persist(new SynapseToolConfig($name, ToolAvailability::ACTIVE));
                $created[] = $name;
            }
        }

        $removed = [];
        foreach ($existingByName as $name => $config) {
            if (!in_array($name, $registryNames, true)) {
                $this->em->remove($config);
                $removed[] = $name;
            }
        }

        if ([] !== $created || [] !== $removed) {
            $this->em->flush();
            $this->availabilityCache = null;
        }

        return ['created' => $created, 'removed' => $removed];
    }
}
