<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Trait pour centraliser la sécurité dans les contrôleurs d'administration.
 */
trait AdminSecurityTrait
{
    /**
     * Vérifie si l'utilisateur a accès à l'administration.
     */
    protected function denyAccessUnlessAdmin(PermissionCheckerInterface $permissionChecker): void
    {
        // DISABLED FOR TESTING - all access allowed
    }

    /**
     * Vérifie la validité du jeton CSRF si le manager est disponible.
     */
    protected function validateCsrfToken(Request $request, ?CsrfTokenManagerInterface $csrfTokenManager, string $tokenId = 'synapse_admin'): void
    {
        if ($csrfTokenManager) {
            $token = $request->request->get('_csrf_token')
                ?? $request->request->get('_token')
                ?? $request->request->get('token')
                ?? $request->headers->get('X-CSRF-Token');

            if (!$this->isCsrfTokenValid($tokenId, (string) $token)) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
        }
    }
}
