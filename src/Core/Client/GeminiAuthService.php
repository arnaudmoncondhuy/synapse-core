<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'authentification OAuth2 pour Google Cloud / Vertex AI.
 *
 * Gère la génération et le refresh automatique des access tokens.
 * Supporte deux sources de credentials :
 *  - Fichier JSON Service Account (chemin YAML — fallback)
 *  - Contenu JSON injecté depuis la DB via setCredentialsJson()
 */
class GeminiAuthService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private ?string $cachedToken = null;
    private ?int $tokenExpiry = null;

    /** Credentials injectés depuis la DB (prioritaires sur le fichier) */
    private ?array $credentialsOverride = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $serviceAccountJsonPath = null,
    ) {}

    /**
     * Injecte les credentials depuis un contenu JSON (depuis la DB).
     * Invalide le token en cache si les credentials changent.
     */
    public function setCredentialsJson(string $jsonContent): void
    {
        $credentials = json_decode($jsonContent, true);
        if (!is_array($credentials)) {
            return; // JSON invalide — on ne remplace pas
        }

        // Invalider le cache si les credentials ont changé
        if ($this->credentialsOverride !== $credentials) {
            $this->cachedToken = null;
            $this->tokenExpiry = null;
            $this->credentialsOverride = $credentials;
        }
    }

    /**
     * Obtient un access token valide (avec refresh automatique).
     */
    public function getAccessToken(): string
    {
        // Vérifier si le token est encore valide (5 min de marge)
        if ($this->cachedToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 300)) {
            return $this->cachedToken;
        }

        return $this->refreshToken();
    }

    private function refreshToken(): string
    {
        $credentials = $this->loadCredentials();

        // Créer l'assertion JWT
        $jwt = $this->createJwtAssertion($credentials);

        // Échanger le JWT contre un access token
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        $data = $response->toArray();

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    /**
     * Charge les credentials depuis la source disponible.
     *
     * Priorité : DB (setCredentialsJson) > fichier YAML
     *
     * @throws \RuntimeException Si aucune source de credentials n'est disponible
     */
    private function loadCredentials(): array
    {
        // Priorité 1 : credentials injectés depuis la DB
        if ($this->credentialsOverride !== null) {
            return $this->credentialsOverride;
        }

        // Priorité 2 : fichier JSON (chemin YAML)
        if ($this->serviceAccountJsonPath && file_exists($this->serviceAccountJsonPath)) {
            $credentials = json_decode(file_get_contents($this->serviceAccountJsonPath), true);
            if (is_array($credentials)) {
                return $credentials;
            }
            throw new \RuntimeException('Invalid Service Account JSON file: ' . $this->serviceAccountJsonPath);
        }

        throw new \RuntimeException(
            'Google credentials not configured. Add a Gemini provider in the Synapse admin (Providers → Gemini).'
        );
    }

    private function createJwtAssertion(array $credentials): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'iss'   => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $headerEncoded  = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        openssl_sign(
            $signatureInput,
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );

        return $signatureInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
