<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Core\ToneRegistry;

/**
 * Constructeur de Prompts Systèmes.
 *
 * Ce service assemble les différentes couches d'instructions pour former le
 * "System Instruction" final envoyé au LLM.
 * Il combine :
 * 1. Le Prompt Technique (interne).
 * 2. Le Prompt Système de l'application (via ContextProvider).
 * 3. Les instructions de ton sélectionnées (optionnel).
 */
class PromptBuilder
{
    public function __construct(
        private ContextProviderInterface $contextProvider,
        private ToneRegistry $toneRegistry,
        private \ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface $configProvider,
    ) {}

    /**
     * Construit un message système au format OpenAI canonical.
     *
     * Retourne un tableau avec role et content, prêt à être utilisé dans le tableau contents.
     *
     * @param string|null $toneKey Clé optionnelle du ton de réponse
     * @return array{role: 'system', content: string} Message système au format OpenAI
     */
    public function buildSystemMessage(?string $toneKey = null): array
    {
        $systemContent = $this->buildSystemInstruction($toneKey);

        return [
            'role'    => 'system',
            'content' => $systemContent,
        ];
    }

    /**
     * Construit l'instruction système brute (texte pur).
     *
     * @param string|null $toneKey Clé optionnelle du ton de réponse
     * @return string Le texte complet du système (contexte + ton)
     */
    public function buildSystemInstruction(?string $toneKey = null): string
    {
        $config = $this->configProvider->getConfig();
        $systemPrompt = $config['system_prompt'] ?? null;

        // Si un prompt système est défini en base de données, on l'interpole avec les variables du ContextProvider
        if ($systemPrompt) {
            $context = $this->contextProvider->getInitialContext();
            $finalPrompt = $this->interpolateVariables($systemPrompt, $context);
        } else {
            $finalPrompt = $this->contextProvider->getSystemPrompt();
        }

        if ($toneKey) {
            $tonePrompt = $this->toneRegistry->getSystemPrompt($toneKey);
            if ($tonePrompt) {
                $finalPrompt .= "\n\n---\n\n### 🎭 TONE INSTRUCTIONS\n";
                $finalPrompt .= "IMPORTANT : Les instructions suivantes s'appliquent UNIQUEMENT à ton TON et ton STYLE d'expression.\n";
                $finalPrompt .= "Elles n'affectent PAS tes capacités de raisonnement, ta logique ou le respect strict des contraintes techniques.\n\n";
                $finalPrompt .= $tonePrompt;
            }
        }

        return $finalPrompt;
    }

    /**
     * Interpole les variables {VAR} dans un template avec les données du contexte.
     *
     * Agnostique : le bundle ne connaît pas les variables, il utilise celles
     * fournies par le ContextProvider via getInitialContext().
     *
     * @param string $template Le template avec variables {DATE}, {EMAIL}, etc.
     * @param array  $context  Le contexte retourné par getInitialContext()
     *
     * @return string Le template avec les variables remplacées
     */
    private function interpolateVariables(string $template, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                // Variables de premier niveau : date, time, etc.
                $replacements['{' . strtoupper($key) . '}'] = (string) $value;
            } elseif (is_array($value) && $key === 'user') {
                // Variables utilisateur : email, nom, prenom, role, groups, etc.
                foreach ($value as $userKey => $userValue) {
                    if (is_scalar($userValue)) {
                        $replacements['{' . strtoupper($userKey) . '}'] = (string) $userValue;
                    }
                }
            }
        }

        return strtr($template, $replacements);
    }
}
