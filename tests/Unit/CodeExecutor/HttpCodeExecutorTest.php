<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\CodeExecutor;

use ArnaudMoncondhuy\SynapseCore\CodeExecutor\HttpCodeExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\CodeExecutor\HttpCodeExecutor
 */
final class HttpCodeExecutorTest extends TestCase
{
    public function testSuccessfulExecution(): void
    {
        $capturedRequest = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse(json_encode([
                'success' => true,
                'stdout' => "42\n",
                'stderr' => '',
                'return_value' => null,
                'duration_ms' => 15,
                'error_type' => null,
                'error_message' => null,
            ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
        });

        $exec = new HttpCodeExecutor($client, 'http://synapse-sandbox:8000');

        $result = $exec->execute('print(6 * 7)');

        $this->assertTrue($result->success);
        $this->assertSame("42\n", $result->stdout);
        $this->assertSame(15, $result->durationMs);

        $this->assertSame('POST', $capturedRequest['method']);
        $this->assertSame('http://synapse-sandbox:8000/execute', $capturedRequest['url']);
        $body = json_decode((string) $capturedRequest['body'], true);
        $this->assertSame('print(6 * 7)', $body['code']);
        $this->assertSame('python', $body['language']);
    }

    public function testBackendUnreachableReturnsBackendUnavailable(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            // MockHttpClient transforme l'exception à la lecture de la réponse,
            // pas à la requête. Simule un error HTTP transport via status 0.
            return new MockResponse('', [
                'error' => 'Connection refused',
            ]);
        });

        $exec = new HttpCodeExecutor($client, 'http://synapse-sandbox:8000');
        $result = $exec->execute('print(1)');

        $this->assertFalse($result->success);
        $this->assertSame('BackendUnavailable', $result->errorType);
        $this->assertStringContainsString('unreachable', (string) $result->errorMessage);
    }

    public function testUnsupportedLanguageRejectedBeforeHttp(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('');
        });

        $exec = new HttpCodeExecutor($client);
        $result = $exec->execute('fn main() {}', language: 'rust');

        $this->assertFalse($result->success);
        $this->assertSame('UnsupportedLanguage', $result->errorType);
        $this->assertSame(0, $calls, 'No HTTP request should be made for unsupported language');
    }

    public function testIsAvailableReturnsTrueOnHealthOk(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['status' => 'ok']), ['http_code' => 200]));
        $exec = new HttpCodeExecutor($client);
        $this->assertTrue($exec->isAvailable());
    }

    public function testIsAvailableReturnsFalseOnHealthError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $exec = new HttpCodeExecutor($client);
        $this->assertFalse($exec->isAvailable());
    }

    public function testGetSupportedLanguages(): void
    {
        $exec = new HttpCodeExecutor(new MockHttpClient());
        $this->assertSame(['python'], $exec->getSupportedLanguages());
    }

    public function testExecutionResultMapsErrorFieldsFromBackend(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'success' => false,
            'stdout' => '',
            'stderr' => "Traceback...\nSyntaxError",
            'return_value' => null,
            'duration_ms' => 3,
            'error_type' => 'PythonSyntaxError',
            'error_message' => 'invalid syntax',
        ]), ['http_code' => 200]));

        $exec = new HttpCodeExecutor($client);
        $result = $exec->execute('print(');

        $this->assertFalse($result->success);
        $this->assertSame('PythonSyntaxError', $result->errorType);
        $this->assertSame('invalid syntax', $result->errorMessage);
        $this->assertStringContainsString('SyntaxError', $result->stderr);
    }
}
