<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object représentant la consommation de tokens d'un appel LLM.
 */
final readonly class TokenUsage
{
    public int $totalTokens;

    public function __construct(
        public int $promptTokens = 0,
        /** Tokens de completion texte pur (sans la modalité image). */
        public int $completionTokens = 0,
        public int $thinkingTokens = 0,
        /**
         * Tokens de sortie image pour les modèles mixtes type gemini-2.5-flash-image
         * qui renvoient texte + image dans un même appel. Comptés séparément de
         * completionTokens car tarifés différemment (pricing_output_image). 0 si non applicable.
         */
        public int $imageCompletionTokens = 0,
    ) {
        $this->totalTokens = $promptTokens + $completionTokens + $thinkingTokens + $imageCompletionTokens;
    }

    public function add(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->thinkingTokens + $other->thinkingTokens,
            $this->imageCompletionTokens + $other->imageCompletionTokens,
        );
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Construit un TokenUsage à partir d'un usage brut de provider.
     *
     * Convention interne du VO : `completionTokens` = **texte pur**. Les providers
     * renvoient généralement un `completion_tokens` qui inclut déjà les tokens image
     * (ex. Gemini `candidatesTokenCount`). On soustrait donc `image_completion_tokens`
     * pour séparer clairement les deux modalités et permettre une tarification distincte.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $completionRaw = is_numeric($data['completion_tokens'] ?? null) ? (int) $data['completion_tokens'] : 0;
        $imageCompletion = is_numeric($data['image_completion_tokens'] ?? null) ? (int) $data['image_completion_tokens'] : 0;
        $textCompletion = max(0, $completionRaw - $imageCompletion);

        return new self(
            promptTokens: is_numeric($data['prompt_tokens'] ?? null) ? (int) $data['prompt_tokens'] : 0,
            completionTokens: $textCompletion,
            thinkingTokens: is_numeric($data['thinking_tokens'] ?? null) ? (int) $data['thinking_tokens'] : 0,
            imageCompletionTokens: $imageCompletion,
        );
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int, image_completion_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'thinking_tokens' => $this->thinkingTokens,
            'total_tokens' => $this->totalTokens,
            'image_completion_tokens' => $this->imageCompletionTokens,
        ];
    }
}
