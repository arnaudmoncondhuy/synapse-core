<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptBuildEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use ArnaudMoncondhuy\SynapseCore\ToneRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Builds the complete prompt (system instruction + history) for the LLM.
 *
 * Listens to PromptBuildEvent (BUILD phase) and populates:
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
        private ToneRegistry $toneRegistry,
        private SynapseProfiler $profiler,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PromptBuildEvent::class => ['onPrePrompt', 0],
        ];
    }

    /**
     * Construit le prompt de base : system message, historique, config, tool definitions.
     */
    public function onPrePrompt(PromptBuildEvent $event): void
    {
        $this->profiler->start('Context', 'Context Builder CPU', 'Temps de préparation des informations système, recherche des instructions et formatage.');

        $message = $event->getMessage();
        $options = $event->getOptions();

        // ── 1. SOCLE (Défaut) ──
        $config = $this->configProvider->getConfig();
        $toneKeyMixed = $options['tone'] ?? null;
        $toneKey = is_string($toneKeyMixed) ? $toneKeyMixed : null;
        $systemMessage = $this->promptBuilder->buildSystemMessage($toneKey);
        if (null !== $toneKey && '' !== $toneKey) {
            $config = $config->withActiveTone($toneKey);
        }

        // ── 2. METIER (Agent) ──
        $effectiveToneKey = $toneKey;
        if (isset($options['agent']) && is_string($options['agent'])) {
            $agent = $this->agentRegistry->get($options['agent']);
            if (null !== $agent && $agent->isActive()) {
                // Si pas de ton spécifié dans le chat (null ou vide), on utilise celui de l'agent
                if (empty($effectiveToneKey) && null !== $agent->getTone() && $agent->getTone()->isActive()) {
                    $effectiveToneKey = $agent->getTone()->getKey();
                }

                // Surcharge le prompt système par celui de l'agent
                $systemContent = $agent->getSystemPrompt();

                // Fusionner le tone effectif s'il existe (Chat > Agent)
                if (null !== $effectiveToneKey) {
                    $tonePrompt = $this->toneRegistry->getSystemPrompt($effectiveToneKey);
                    if (!empty($tonePrompt)) {
                        $systemContent .= "\n\n---\n\n### 🎭 TONE INSTRUCTIONS\n";
                        $systemContent .= "IMPORTANT : Les instructions suivantes s'appliquent UNIQUEMENT à ton TON et ton STYLE d'expression.\n";
                        $systemContent .= "Elles n'affectent PAS tes capacités de raisonnement, ta logique ou le respect strict des contraintes techniques.\n\n";
                        $systemContent .= $tonePrompt;
                    }
                }
                $systemMessage = ['role' => 'system', 'content' => $systemContent];

                // Surcharge la config technique par le PresetModel de l'agent (s'il y en a un)
                if (null !== $agent->getModelPreset()) {
                    $config = $this->configProvider->getConfigForPreset($agent->getModelPreset());
                    // On s'assure de ré-injecter le ton actif dans la nouvelle config
                    if (null !== $effectiveToneKey && '' !== $effectiveToneKey) {
                        $config = $config->withActiveTone($effectiveToneKey);
                    }
                }
                $config = $config->withAgentInfo($agent->getId(), $agent->getName(), $agent->getEmoji());

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
                    $config = $config->withAgentInfo($agent->getId(), $agent->getName(), $agent->getEmoji());
                }
            }
        }

        // ── 4. Load history ──
        $modelId = $config->model ?? '';
        $supportsVision = '' === $modelId || $this->capabilityRegistry->supports($modelId, 'vision');

        $contents = [];
        if (isset($options['history']) && is_array($options['history'])) {
            /** @var array<int, array<string, mixed>> $history */
            $history = array_values(array_filter($options['history'], fn ($v) => is_array($v)));
            $contents = $this->sanitizeHistoryForNewTurn($history, $supportsVision);
        }

        // Vision: construire un content multipart si des images sont attachées ET que le modèle supporte la vision
        $images = $supportsVision ? $event->getImages() : [];

        // Récupérer les images générées précédemment (trailing) à injecter dans le message courant
        $trailingGeneratedImages = ($supportsVision && is_array($options['_trailing_generated_images'] ?? null))
            ? $options['_trailing_generated_images']
            : [];

        if (!empty($images) || !empty($trailingGeneratedImages)) {
            $parts = [];
            if ('' !== $message) {
                $parts[] = ['type' => 'text', 'text' => $message];
            }
            foreach ($trailingGeneratedImages as $imgPart) {
                $parts[] = $imgPart;
            }
            foreach ($images as $image) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$image['mime_type'].';base64,'.$image['data']],
                ];
            }
            $contents[] = ['role' => 'user', 'content' => $parts];
        } else {
            $contents[] = ['role' => 'user', 'content' => $message];
        }

        // System instruction is now the first message in contents (OpenAI canonical format)
        $toolsOptionRaw = $options['tools'] ?? null;
        /** @var list<string>|null $toolsOption */
        $toolsOption = is_array($toolsOptionRaw) ? array_values(array_filter($toolsOptionRaw, 'is_string')) : (is_string($toolsOptionRaw) ? [$toolsOptionRaw] : null);
        $toolsOverrideRaw = $options['tools_override'] ?? null;
        /** @var list<string>|null $toolsOverride */
        $toolsOverride = is_array($toolsOverrideRaw) ? array_values(array_filter($toolsOverrideRaw, 'is_string')) : null;

        $toolDefinitions = !$config->isFunctionCallingEnabled()
            ? []
            : (null !== $toolsOverride
                ? $this->toolRegistry->getDefinitions($toolsOverride)
                : (is_array($toolsOption) ? $this->toolRegistry->getDefinitions($toolsOption) : $this->toolRegistry->getDefinitions()));

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
    private function sanitizeHistoryForNewTurn(array $history, bool $supportsVision = true): array
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
                // Accept string (text) or array (multipart parts for vision)
                $content = is_string($contentRaw) ? $contentRaw : (is_array($contentRaw) ? $contentRaw : null);

                // If model doesn't support vision, strip image parts from multipart content
                if (!$supportsVision && is_array($content)) {
                    $textParts = array_values(array_filter($content, fn ($p) => is_array($p) && ($p['type'] ?? '') === 'text'));
                    $content = 1 === count($textParts) ? ($textParts[0]['text'] ?? '') : (empty($textParts) ? '' : implode(' ', array_column($textParts, 'text')));
                }

                // Skip user messages with neither string nor array content
                if ('user' === $role && null === $content) {
                    continue;
                }

                /** @var array{role: string, content: string|null, tool_calls?: array<mixed>} $entry */
                $entry = [
                    'role' => $role,
                    'content' => is_string($content) ? TextUtil::sanitizeUtf8($content) : $content,
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
