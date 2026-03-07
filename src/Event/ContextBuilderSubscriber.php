<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Builds the complete prompt (system instruction + history) for the LLM.
 *
 * Listens to SynapsePrePromptEvent and populates:
 * - System instruction (tone-based)
 * - SynapseMessage history (from options or loaded via handler)
 * - Generation config (from active preset)
 */
class ContextBuilderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PromptBuilder $promptBuilder,
        private ConfigProviderInterface $configProvider,
        private ToolRegistry $toolRegistry,
        private AgentRegistry $agentRegistry,
        private SynapseModelPresetRepository $modelPresetRepository,
        private SynapseProfiler $profiler,
    ) {}

    /**
     * Décrit l'événement écouté : SynapsePrePromptEvent avec haute priorité (100).
     *
     * @return array<string, array{0: string, 1: int}> Mapping : {eventClass: [methodName, priority]}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 100], // High priority
        ];
    }

    /**
     * Traite l'événement SynapsePrePromptEvent pour construire le prompt final.
     * Peuple l'événement avec : système instruction, contenu message (stateless/stateful),
     * configuration, et définitions d'outils.
     *
     * @param SynapsePrePromptEvent $event L'événement contenant message et options
     */
    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $this->profiler->start('Context', 'Context Builder CPU', 'Temps de préparation des informations système, recherche des instructions et formatage.');

        $message = $event->getMessage();
        $options = $event->getOptions();

        // ── 1. SOCLE (Défaut) ──
        $config = $this->configProvider->getConfig();
        $toneKeyMixed = $options['tone'] ?? null;
        $toneKey = is_string($toneKeyMixed) ? $toneKeyMixed : null;
        $systemMessage = $this->promptBuilder->buildSystemMessage($toneKey);

        // ── 2. METIER (Agent) ──
        if (isset($options['agent']) && is_string($options['agent'])) {
            $agent = $this->agentRegistry->get($options['agent']);
            if (null !== $agent && $agent->isActive()) {
                // Surcharge le prompt système par celui de l'agent
                $systemContent = $agent->getSystemPrompt();

                // Fusionner le tone de l'agent si défini
                if (null !== $agent->getTone() && $agent->getTone()->isActive()) {
                    $tonePrompt = $agent->getTone()->getSystemPrompt();
                    if (!empty($tonePrompt)) {
                        $systemContent .= "\n\n### TONE INSTRUCTIONS\n" . $tonePrompt;
                    }
                }
                $systemMessage = ['role' => 'system', 'content' => $systemContent];

                // Surcharge la config technique par le PresetModel de l'agent (s'il y en a un)
                if (null !== $agent->getModelPreset()) {
                    $config = $this->configProvider->getConfigForPreset($agent->getModelPreset());
                }
                $config['agent_id'] = $agent->getId();

                // Injecter les outils autorisés de l'agent
                // (sauf si le développeur a déjà défini tools_override)
                if (!isset($options['tools_override'])) {
                    $options['tools_override'] = $agent->getAllowedToolNames();
                }
            }
        }

        // ── 3. DÉVELOPPEUR (Overrides) ──
        // Le développeur a toujours le dernier mot via les options de ask()

        // a. Override du Prompt Système
        if (isset($options['system_prompt']) && is_string($options['system_prompt'])) {
            $systemMessage = ['role' => 'system', 'content' => $options['system_prompt']];
        }

        // b. Override du Preset Modèle
        $presetOption = $options['model_preset'] ?? ($options['preset'] ?? null); // Fallback temporaire sur 'preset'
        if (is_string($presetOption)) {
            $overridePreset = $this->modelPresetRepository->findByKey($presetOption);
            if (null !== $overridePreset) {
                $config = $this->configProvider->getConfigForPreset($overridePreset);
                // Conserver l'ID de l'agent pour le tracking si on avait un agent
                if (isset($options['agent']) && isset($agent)) {
                    $config['agent_id'] = $agent->getId();
                }
            }
        }

        // ── 4. Load history ──
        $contents = [];
        if (isset($options['history']) && is_array($options['history'])) {
            $contents = $this->sanitizeHistoryForNewTurn($options['history']);
        }
        $contents[] = ['role' => 'user', 'content' => $message];

        // System instruction is now the first message in contents (OpenAI canonical format)
        $toolsOptionRaw = $options['tools'] ?? null;
        /** @var list<string>|null $toolsOption */
        $toolsOption = is_array($toolsOptionRaw) ? array_values(array_filter($toolsOptionRaw, 'is_string')) : (is_string($toolsOptionRaw) ? [$toolsOptionRaw] : null);
        $toolsOverrideRaw = $options['tools_override'] ?? null;
        /** @var list<string>|null $toolsOverride */
        $toolsOverride = is_array($toolsOverrideRaw) ? array_values(array_filter($toolsOverrideRaw, 'is_string')) : null;

        $toolDefinitions = null !== $toolsOverride
            ? $this->toolRegistry->getDefinitions($toolsOverride)
            : (is_array($toolsOption) ? $this->toolRegistry->getDefinitions($toolsOption) : $this->toolRegistry->getDefinitions());

        $prompt = [
            'contents' => array_merge([$systemMessage], $contents),
            'toolDefinitions' => $toolDefinitions,
        ];

        // Set on event
        $event->setPrompt($prompt);
        $event->setConfig($config);
        $this->profiler->stop('Context', 'Context Builder CPU', 0);
    }

    /**
     * Sanitize history before sending to LLM.
     *
     * Expects OpenAI canonical format and ensures UTF-8 validity.
     *
     * @param array<int, array<string, mixed>> $history
     *
     * @return array<int, array{role: string, content: string|null, tool_call_id?: string, tool_calls?: array<mixed>}>
     */
    private function sanitizeHistoryForNewTurn(array $history): array
    {
        $sanitized = [];

        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = is_string($message['role'] ?? null) ? (string) $message['role'] : '';

            // Validate known roles
            if (!in_array($role, ['user', 'assistant', 'tool'], true)) {
                continue;
            }

            if ('user' === $role || 'assistant' === $role) {
                $contentRaw = $message['content'] ?? '';
                $content = is_string($contentRaw) ? $contentRaw : null;

                // Skip user messages with non-string content
                if ('user' === $role && null === $content) {
                    continue;
                }

                /** @var array{role: string, content: string|null, tool_calls?: array<mixed>} $entry */
                $entry = [
                    'role' => $role,
                    'content' => null !== $content ? TextUtil::sanitizeUtf8($content) : null,
                ];

                // Preserve tool_calls for assistant messages
                $toolCalls = $message['tool_calls'] ?? null;
                if (is_array($toolCalls) && !empty($toolCalls)) {
                    $entry['tool_calls'] = $toolCalls;
                }

                $sanitized[] = $entry;
            } elseif ('tool' === $role) {
                $sanitized[] = [
                    'role' => 'tool',
                    'tool_call_id' => is_string($message['tool_call_id'] ?? null) ? (string) $message['tool_call_id'] : '',
                    'content' => TextUtil::sanitizeUtf8(is_string($message['content'] ?? null) ? (string) $message['content'] : ''),
                ];
            }
        }

        return $sanitized;
    }
}
