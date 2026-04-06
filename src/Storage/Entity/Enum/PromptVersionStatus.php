<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum;

/**
 * États possibles d'un {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion}
 * dans le cycle "Human-in-the-Loop" du Garde-fou #3.
 *
 * Sémantique :
 *
 * - **Pending** : version proposée (typiquement par un agent architecte ou un
 *   outil MCP) qui attend validation humaine. N'est JAMAIS active, n'est PAS
 *   appliquée sur `SynapseAgent::$systemPrompt`. Visible dans la file d'attente
 *   admin.
 * - **Approved** : version qui a transité par l'état `Pending` et qui a été
 *   validée. Passe ensuite à `isActive = true` et est appliquée sur l'agent.
 *   Conserver ce statut permet l'audit "cette version a été auto-générée puis
 *   validée humainement" (vs `null` = live mode direct).
 * - **Rejected** : version qui a transité par l'état `Pending` et qui a été
 *   rejetée. Conservée pour l'audit, jamais active, jamais appliquée. La raison
 *   du refus est stockée dans le champ `reason` de l'entité.
 *
 * ## Pourquoi un champ nullable
 *
 * Les versions créées en **live mode direct** (admin humain qui édite son propre
 * agent via le formulaire) ont `status = null`. C'est intentionnel : le mode
 * HITL ne s'applique qu'aux modifications **auto-générées** ou provenant de
 * canaux automatisés (MCP, agent architecte). Un humain qui édite son propre
 * agent n'a pas besoin de se valider lui-même.
 */
enum PromptVersionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
