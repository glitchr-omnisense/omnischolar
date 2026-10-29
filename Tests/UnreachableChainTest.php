<?php

namespace Omnischolar\Tests;

use Omnischolar\Exception\OmnischolarException;
use Omnischolar\Source\HttpSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of a source's key anywhere in what a log of a failed call writes: the messages, the
 * previous exception (a copy of the HTTP client's, its class kept, the key's parameter masked),
 * the traces' arguments - the query and headers with the key - when zend.exception_ignore_args
 * is off. Unreachable, and every error status.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['scholar-key-1234567', 'scholar-token-1234567'];

    public function testNoPartOfAFailureChainHoldsTheKey(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $answers = [
            'unreachable' => static fn (string $method, string $url): never => throw new TransportException(\sprintf('Could not resolve host: api.example for "%s".', $url), 6),
            '429' => static fn (): MockResponse => new MockResponse('', ['http_code' => 429]),
            '503' => static fn (): MockResponse => new MockResponse('down', ['http_code' => 503]),
            '400' => static fn (): MockResponse => new MockResponse('bad', ['http_code' => 400]),
            'not JSON' => static fn (): MockResponse => new MockResponse('<html>'),
        ];
        try {
            foreach ($answers as $case => $answer) {
                try {
                    self::source(new MockHttpClient($answer))->read();
                    self::fail("no exception on $case");
                } catch (OmnischolarException $e) {
                    self::assertNotEmpty($e->getTrace());
                    if ('unreachable' === $case) {
                        self::assertInstanceOf(TransportException::class, $e->getPrevious());
                        self::assertSame(6, $e->getPrevious()->getCode());
                        self::assertStringContainsString('api_key=***', $e->getMessage());
                    }
                    for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                        foreach (self::SECRETS as $secret) {
                            self::assertFalse(str_contains($one->getMessage(), $secret), "$case: ".$one->getMessage());
                            self::assertFalse(str_contains(print_r($one->getTrace(), true), $secret), "$case: a trace holds $secret");
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }

    private static function source(MockHttpClient $http): object
    {
        return new class($http, 'https://api.example/', ['Authorization' => 'Bearer scholar-token-1234567']) extends HttpSource {
            public function getName(): string
            {
                return 'example';
            }

            public function capabilities(): array
            {
                return [];
            }

            /** @return array<mixed>|null */
            public function read(): ?array
            {
                return $this->getJson('works', ['api_key' => 'scholar-key-1234567', 'q' => 'x']);
            }
        };
    }
}
