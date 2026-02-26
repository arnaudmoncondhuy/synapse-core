<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core;

/**
 * Registre de gestion des personnalités ("Personas") de l'IA.
 *
 * Ce service charge les configurations de personnalités depuis un fichier JSON.
 * Il permet à l'application de proposer plusieurs "visages" ou spécialités
 * pour l'assistant (ex: Expert Technique, Assistant Créatif, Traducteur).
 */
class PersonaRegistry
{
    private array $personas = [];

    /**
     * @param string $configPath chemin absolu vers le fichier JSON de définition des personas
     */
    public function __construct(
        private string $configPath,
    ) {
        $this->loadPersonas();
    }

    private function loadPersonas(): void
    {
        if (!file_exists($this->configPath)) {
            return;
        }

        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);

        if (is_array($data)) {
            foreach ($data as $persona) {
                if (isset($persona['key'], $persona['system_prompt'])) {
                    $this->personas[$persona['key']] = $persona;
                }
            }
        }
    }

    /**
     * Retourne toutes les personnalités disponibles.
     *
     * @return array<string, array> tableau associatif indexé par la clé de persona
     */
    public function getAll(): array
    {
        return $this->personas;
    }

    /**
     * Récupère la configuration d'une personnalité spécifique.
     *
     * @param string $key la clé unique du persona (ex: 'expert_php')
     *
     * @return array|null la configuration ou null si introuvable
     */
    public function get(string $key): ?array
    {
        return $this->personas[$key] ?? null;
    }

    /**
     * Récupère uniquement le prompt système associé à une personnalité.
     *
     * @param string $key la clé unique du persona
     *
     * @return string|null le prompt spécifique ou null
     */
    public function getSystemPrompt(string $key): ?string
    {
        return $this->personas[$key]['system_prompt'] ?? null;
    }
}
