<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Core\PersonaRegistry;

/**
 * Constructeur de Prompts Systèmes.
 *
 * Ce service assemble les différentes couches d'instructions pour former le
 * "System Instruction" final envoyé à Gemini.
 * Il combine :
 * 1. Le Prompt Technique (interne, thinking natif).
 * 2. Le Prompt Système de l'application (via ContextProvider).
 * 3. Le Prompt de la Personnalité sélectionnée (optionnel).
 */
class PromptBuilder
{
    /**
     * Instructions techniques pour le mode thinking natif de Gemini.
     * Le système capture automatiquement la réflexion via thinkingConfig.
     */
    private const TECHNICAL_PROMPT = <<<PROMPT
### CADRE TECHNIQUE DE RÉPONSE

Ta réponse à l'utilisateur doit impérativement respecter ce format :
- Format Markdown propre.
- URLs au format [Texte](url) uniquement.

### MÉMORISATION D'INFORMATIONS UTILISATEUR

Quand l'utilisateur partage une information personnelle utile à retenir (nom, préférence, contrainte, etc.), TU DOIS :
1. Appeler l'outil `propose_to_remember` avec le fait à mémoriser.
2. Puis, continuer avec ta réponse conversationnelle normale.

Ne demande pas la permission : utilise directement l'outil si le contexte l'indique.
PROMPT;

    public function __construct(
        private ContextProviderInterface $contextProvider,
        private PersonaRegistry $personaRegistry,
        private \ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface $configProvider,
    ) {
    }

    /**
     * Construit un message système au format OpenAI canonical.
     *
     * Retourne un tableau avec role et content, prêt à être utilisé dans le tableau contents.
     *
     * @param string|null $personaKey Clé optionnelle de la personnalité
     * @return array{role: 'system', content: string} SynapseMessage système au format OpenAI
     */
    public function buildSystemMessage(?string $personaKey = null): array
    {
        $systemContent = $this->buildSystemInstruction($personaKey);

        return [
            'role'    => 'system',
            'content' => $systemContent,
        ];
    }

    /**
     * Construit l'instruction système brute (texte pur).
     *
     * @param string|null $personaKey Clé optionnelle de la personnalité
     * @return string Le texte complet du système (techniques + contexte + persona)
     */
    public function buildSystemInstruction(?string $personaKey = null): string
    {
        $config = $this->configProvider->getConfig();
        $systemPrompt = $config['system_prompt'] ?? null;

        // Si un prompt système est défini en base de données, on l'interpole avec les variables du ContextProvider
        if ($systemPrompt) {
            $context = $this->contextProvider->getInitialContext();
            $basePrompt = $this->interpolateVariables($systemPrompt, $context);
        } else {
            $basePrompt = $this->contextProvider->getSystemPrompt();
        }

        // Ajout d'un séparateur horizontal pour couper la hiérarchie Markdown
        $finalPrompt = self::TECHNICAL_PROMPT."\n\n---\n\n".$basePrompt;

        if ($personaKey) {
            $personaPrompt = $this->personaRegistry->getSystemPrompt($personaKey);
            if ($personaPrompt) {
                // On ajoute une section claire pour la personnalité pour éviter les conflits de ROLE
                $finalPrompt .= "\n\n---\n\n### 🎭 PERSONALITY INSTRUCTIONS\n";
                $finalPrompt .= "IMPORTANT : La personnalité suivante s'applique UNIQUEMENT à ton TON et ton STYLE d'expression.\n";
                $finalPrompt .= "Elle n'affecte PAS tes capacités de raisonnement, ta logique ou le respect strict des contraintes techniques.\n\n";
                $finalPrompt .= $personaPrompt;
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
                $replacements['{'.strtoupper($key).'}'] = (string) $value;
            } elseif (is_array($value) && $key === 'user') {
                // Variables utilisateur : email, nom, prenom, role, groups, etc.
                foreach ($value as $userKey => $userValue) {
                    if (is_scalar($userValue)) {
                        $replacements['{'.strtoupper($userKey).'}'] = (string) $userValue;
                    }
                }
            }
        }

        return strtr($template, $replacements);
    }
}
