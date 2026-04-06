<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Shared\Exception\InvalidResponseFormatException;

/**
 * Service stateless qui valide et canonicalise un tableau `response_format`
 * passé à {@see ChatService::ask()}.
 *
 * Format attendu (aligné sur OpenAI Structured Outputs) :
 *
 *     [
 *         'type' => 'json_schema',
 *         'json_schema' => [
 *             'name' => 'my_schema',            // optionnel, défaut = 'response'
 *             'schema' => [                     // requis : schéma JSON Schema
 *                 'type' => 'object',
 *                 'properties' => [...],
 *                 'required' => [...],
 *             ],
 *             'strict' => true,                 // optionnel, défaut = true
 *         ],
 *     ]
 *
 * Aucune validation JSON Schema du `schema` lui-même : on fait confiance au
 * provider (OpenAI `strict: true`, Gemini `responseSchema`) pour garantir la
 * conformité structurelle de la sortie.
 */
final class ResponseFormatNormalizer
{
    /**
     * @param array<string, mixed> $responseFormat
     *
     * @throws InvalidResponseFormatException si la forme est invalide
     *
     * @return array{type: string, json_schema: array{name: string, schema: array<string, mixed>, strict: bool}}
     */
    public function normalize(array $responseFormat): array
    {
        $type = $responseFormat['type'] ?? null;
        if ('json_schema' !== $type) {
            throw new InvalidResponseFormatException(\sprintf('response_format.type doit valoir "json_schema", reçu "%s".', \is_string($type) ? $type : get_debug_type($type)));
        }

        $jsonSchema = $responseFormat['json_schema'] ?? null;
        if (!\is_array($jsonSchema)) {
            throw new InvalidResponseFormatException('response_format.json_schema est requis et doit être un tableau.');
        }

        $schema = $jsonSchema['schema'] ?? null;
        if (!\is_array($schema)) {
            throw new InvalidResponseFormatException('response_format.json_schema.schema est requis et doit être un tableau.');
        }

        $name = $jsonSchema['name'] ?? 'response';
        if (!\is_string($name) || '' === $name) {
            throw new InvalidResponseFormatException('response_format.json_schema.name doit être une chaîne non vide.');
        }

        // strict est à true par défaut — c'est tout l'intérêt des structured outputs.
        $strict = $jsonSchema['strict'] ?? true;
        if (!\is_bool($strict)) {
            throw new InvalidResponseFormatException('response_format.json_schema.strict doit être un booléen.');
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $name,
                'schema' => $schema,
                'strict' => $strict,
            ],
        ];
    }
}
