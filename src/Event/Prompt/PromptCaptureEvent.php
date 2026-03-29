<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

/**
 * Phase CAPTURE — Capture du prompt final à des fins de debug/log (lecture seule).
 *
 * Les listeners de cette phase NE DOIVENT PAS modifier le prompt.
 *
 * Remplace SynapsePrePromptEvent priority -200 (DebugLogSubscriber::onPrePrompt).
 */
class PromptCaptureEvent extends AbstractPromptEvent
{
}
