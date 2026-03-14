<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Fournit la liste des rôles disponibles dans l'application.
 *
 * Récupère tous les rôles depuis la hiérarchie de sécurité Symfony
 * pour alimenter l'interface d'administration (sélection de rôles autorisés pour un agent).
 */
final readonly class RoleProvider
{
    public function __construct(
        private ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /**
     * Retourne la liste de tous les rôles disponibles.
     *
     * @return array<string> Liste des rôles (ex: ['ROLE_USER', 'ROLE_ADMIN', ...])
     */
    public function getAvailableRoles(): array
    {
        if (null === $this->roleHierarchy) {
            // Pas de hiérarchie configurée → on retourne les rôles standards Symfony
            return ['ROLE_USER', 'ROLE_ADMIN'];
        }

        // Extraire tous les rôles depuis la hiérarchie
        $roles = [];

        // Utiliser la réflexion pour accéder à la hiérarchie interne
        // (Symfony n'expose pas de méthode publique pour lister tous les rôles)
        $reflection = new \ReflectionClass($this->roleHierarchy);

        try {
            $property = $reflection->getProperty('map');
            $property->setAccessible(true);
            $map = $property->getValue($this->roleHierarchy);

            if (is_array($map)) {
                // Récupérer tous les rôles (clés + valeurs aplaties)
                foreach ($map as $role => $children) {
                    $roles[] = $role;
                    if (is_array($children)) {
                        $roles = array_merge($roles, $children);
                    }
                }
            }
        } catch (\ReflectionException) {
            // Fallback : retourner les rôles standards
            return ['ROLE_USER', 'ROLE_ADMIN'];
        }

        // Dédoublonner et trier
        $roles = array_unique($roles);
        sort($roles);

        // Toujours inclure ROLE_USER et ROLE_ADMIN s'ils ne sont pas déjà présents
        if (!in_array('ROLE_USER', $roles, true)) {
            array_unshift($roles, 'ROLE_USER');
        }
        if (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }
}
