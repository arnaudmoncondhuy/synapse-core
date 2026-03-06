<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Timing;

/**
 * Service de profilage temporel global pour Synapse.
 * Permet de chronométrer de manière asynchrone les différentes étapes du cycle LLM (Contexte, PgVector, Réseau).
 */
class SynapseProfiler
{
    /** @var array<int, array{name: string, description: string, duration_ms: float, turn: int}> */
    private array $steps = [];

    /** @var array<string, array{name: string, description: string, start: float}> */
    private array $activeTimers = [];
    private ?float $globalStart = null;

    public function __construct()
    {
    }

    /**
     * Démarre un chronomètre pour une étape donnée.
     * En cas de compteurs imbriqués ou multiples, l'ID d'étape est conservé pour retrouver sa fin d'exécution.
     */
    public function start(string $group, string $name, string $description = ''): void
    {
        if (null === $this->globalStart) {
            $this->globalStart = microtime(true);
        }

        $id = $group.'_'.$name;
        $this->activeTimers[$id] = [
            'name' => $name,
            'description' => $description,
            'start' => microtime(true),
        ];
    }

    /**
     * Stoppe le chronomètre et enregistre l'étape dans la Timeline.
     */
    public function stop(string $group, string $name, int $turn = 0): void
    {
        $id = $group.'_'.$name;
        if (!isset($this->activeTimers[$id])) {
            return;
        }

        $timer = $this->activeTimers[$id];
        $durationMs = round((microtime(true) - $timer['start']) * 1000, 2);

        $this->steps[] = [
            'name' => $timer['name'],
            'description' => $timer['description'],
            'duration_ms' => $durationMs,
            'turn' => $turn,
        ];

        unset($this->activeTimers[$id]);
    }

    /**
     * Renvoie le tableau structuré des étapes et de la durée totale pour l'interface de Debug (Admin).
     *
     * @return array{total_ms: float, steps: array<int, array{name: string, description: string, duration_ms: float, turn: int}>}
     */
    public function getTimings(): array
    {
        $totalMs = 0;
        if (null !== $this->globalStart) {
            $totalMs = round((microtime(true) - $this->globalStart) * 1000, 2);
        }

        return [
            'total_ms' => $totalMs,
            'steps' => $this->steps,
        ];
    }

    /**
     * Purge les compteurs de la requête courante (utile en cas de relance manuelle via une command).
     */
    public function reset(): void
    {
        $this->steps = [];
        $this->activeTimers = [];
        $this->globalStart = null;
    }
}
