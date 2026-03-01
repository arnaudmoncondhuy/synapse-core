<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\MissionRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
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
        private MissionRegistry $missionRegistry,
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
        $message = $event->getMessage();
        $options = $event->getOptions();

        // ── Build system message (OpenAI format) ──
        if (isset($options['system_prompt']) && is_string($options['system_prompt'])) {
            $systemMessage = ['role' => 'system', 'content' => $options['system_prompt']];
        } else {
            $toneKey = $options['tone'] ?? null;
            $systemMessage = $this->promptBuilder->buildSystemMessage($toneKey);
        }

        // Support mission override (combine mission prompt + optional tone)
        if (isset($options['mission']) && is_string($options['mission'])) {
            $mission = $this->missionRegistry->get($options['mission']);
            if ($mission !== null && $mission->isActive()) {
                $systemContent = $mission->getSystemPrompt();
                // Fusionner le tone de la mission si défini
                if ($mission->getTone() !== null && $mission->getTone()->isActive()) {
                    $tonePrompt = $mission->getTone()->getSystemPrompt();
                    if (!empty($tonePrompt)) {
                        $systemContent .= "\n\n### TONE INSTRUCTIONS\n" . $tonePrompt;
                    }
                }
                $systemMessage = ['role' => 'system', 'content' => $systemContent];
            }
        }

        // ── Load history ──
        $isStateless = $options['stateless'] ?? false;
        $contents = [];

        if ($isStateless) {
            // Stateless mode: Use provided history in options or empty list
            $providedHistory = $options['history'] ?? [];
            if (!empty($providedHistory)) {
                $contents = $this->sanitizeHistoryForNewTurn($providedHistory);
            }
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'content' => TextUtil::sanitizeUtf8($message)];
            }
        } else {
            // Stateful mode: load history from DB/Handler + add current message
            $rawHistory = $options['history'] ?? [];
            $contents = $this->sanitizeHistoryForNewTurn($rawHistory);
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'content' => TextUtil::sanitizeUtf8($message)];
            }
        }

        // Get config
        $config = $this->configProvider->getConfig();

        // Support preset override via mission (if mission has a preset)
        if (isset($options['mission']) && is_string($options['mission'])) {
            $mission = $this->missionRegistry->get($options['mission']);
            if ($mission !== null && $mission->getPreset() !== null) {
                $config = $this->configProvider->getConfigForPreset($mission->getPreset());
            }
        }

        // Support preset override (for testing or AgentBuilder) - takes precedence over mission preset
        if (isset($options['preset'])) {
            $config = $this->configProvider->getConfigForPreset($options['preset']);
        }

        // Expose mission_id in config for spending limits and token accounting
        if (isset($options['mission']) && is_string($options['mission'])) {
            $mission = $this->missionRegistry->get($options['mission']);
            if ($mission !== null) {
                $config['mission_id'] = $mission->getId();
            }
        }

        // ── Build complete prompt (system message + history) ──
        // System instruction is now the first message in contents (OpenAI canonical format)
        $toolsOverride = $options['tools_override'] ?? null;
        $toolDefinitions = $toolsOverride !== null
            ? $this->toolRegistry->getDefinitions($toolsOverride)
            : ($options['tools'] ?? $this->toolRegistry->getDefinitions());

        $prompt = [
            'contents'        => array_merge([$systemMessage], $contents),
            'toolDefinitions' => $toolDefinitions,
        ];

        // Set on event
        $event->setPrompt($prompt);
        $event->setConfig($config);
    }

    /**
     * Sanitize history before sending to LLM.
     *
     * Expects OpenAI canonical format and ensures UTF-8 validity.
     */
    private function sanitizeHistoryForNewTurn(array $history): array
    {
        $sanitized = [];

        foreach ($history as $message) {
            $role = $message['role'] ?? '';

            // Validate known roles
            if (!in_array($role, ['user', 'assistant', 'tool'], true)) {
                continue;
            }

            if ($role === 'user' || $role === 'assistant') {
                $content = $message['content'] ?? '';

                // Skip user messages with non-string content
                if ($role === 'user' && !is_string($content)) {
                    continue;
                }

                $entry = [
                    'role'    => $role,
                    'content' => is_string($content) ? TextUtil::sanitizeUtf8($content) : $content,
                ];

                // Preserve tool_calls for assistant messages
                if (!empty($message['tool_calls'])) {
                    $entry['tool_calls'] = $message['tool_calls'];
                }

                $sanitized[] = $entry;
            } elseif ($role === 'tool') {
                $sanitized[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $message['tool_call_id'] ?? '',
                    'content'      => TextUtil::sanitizeUtf8($message['content'] ?? ''),
                ];
            }
        }

        return $sanitized;
    }
}
