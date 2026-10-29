<?php

namespace Omnischolar\Source;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\ProviderException;
use Omnischolar\Exception\RateLimitedException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Http\Redaction;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the sources over HTTP share: the calls, their errors mapped the same
 * way (404 is null; 429 a RateLimitedException; 5xx and transport errors an
 * UnavailableException; any other 4xx a ProviderException), a minimum
 * interval between two calls when the service asks for one (arXiv: 3 s),
 * and the reading of identifiers given as strings.
 */
abstract class HttpSource implements SourceInterface
{
    private float $lastCall = 0.0;

    /**
     * @param array<string, string> $headers sent with every call (User-Agent...)
     * @param float                 $throttle seconds to leave between two calls
     */
    public function __construct(
        protected readonly HttpClientInterface $http,
        protected readonly string $baseUri,
        protected readonly array $headers = [],
        protected readonly float $throttle = 0.0,
    ) {
    }

    public function author(Identifier|string $author): ?\Omnischolar\Model\Author
    {
        throw NotSupportedException::operation($this->getName(), 'know authors');
    }

    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        throw NotSupportedException::operation($this->getName(), 'list an author\'s works');
    }

    public function work(Identifier|string $id): ?\Omnischolar\Model\Work
    {
        throw NotSupportedException::operation($this->getName(), 'look up a work');
    }

    public function search(Query $query): Page
    {
        throw NotSupportedException::operation($this->getName(), 'search');
    }

    /**
     * GETs JSON. Null when the service answers 404 / 410.
     *
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    protected function getJson(string $path, array $query = [], array $headers = []): ?array
    {
        $body = $this->get($path, $query, ['Accept' => 'application/json'] + $headers);
        if (null === $body) {
            return null;
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw Redaction::withoutArguments(new ProviderException($this->getName(), 'the answer is not JSON: '.$e->getMessage(), null, $e));
        }

        return \is_array($data) ? $data : throw Redaction::withoutArguments(new ProviderException($this->getName(), 'the answer is not a JSON object'));
    }

    /**
     * GETs a body as text (Atom, XML...). Null when the service answers 404 / 410.
     *
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $query = [], array $headers = []): ?string
    {
        $this->wait();
        $url = str_starts_with($path, 'http') ? $path : rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
        $pairs = [];
        foreach ($query as $key => $values) {
            // A list repeats its key (Solr's fq=...&fq=...); null and empty are left out.
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (null !== $value && '' !== $value) {
                    $pairs[] = rawurlencode((string) $key).'='.rawurlencode(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                }
            }
        }
        if ($pairs) {
            $url .= (str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
        }
        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers + $this->headers]);
            $status = $response->getStatusCode();
            if (404 === $status || 410 === $status) {
                return null;
            }
            if (429 === $status) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;
                throw Redaction::withoutArguments(new RateLimitedException($this->getName(), null !== $retry && is_numeric($retry) ? (int) $retry : null));
            }
            if ($status >= 500) {
                throw Redaction::withoutArguments(new UnavailableException($this->getName(), \sprintf('the service answered %d', $status), $status));
            }
            if ($status >= 400) {
                throw Redaction::withoutArguments(new ProviderException($this->getName(), \sprintf('the service answered %d: %s', $status, (string) preg_replace('~^(.{0,300}).*$~us', '$1', trim(strip_tags($response->getContent(false))))), $status));
            }

            return $response->getContent();
        } catch (TransportExceptionInterface $e) {
            // The key (the query, a header) masked in the message and the previous, a copy; no trace keeps the arguments.
            $secrets = Redaction::secrets($url, ['headers' => $headers + $this->headers]);

            throw Redaction::withoutArguments(new UnavailableException($this->getName(), 'unreachable: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        } catch (ExceptionInterface $e) {
            // The key (the query, a header) masked in the message and the previous, a copy; no trace keeps the arguments.
            $secrets = Redaction::secrets($url, ['headers' => $headers + $this->headers]);

            throw Redaction::withoutArguments(new ProviderException($this->getName(), Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
    }

    /** Leaves the configured interval since the last call. */
    private function wait(): void
    {
        if ($this->throttle > 0 && $this->lastCall > 0) {
            $left = $this->throttle - (microtime(true) - $this->lastCall);
            if ($left > 0) {
                usleep((int) ($left * 1_000_000));
            }
        }
        $this->lastCall = microtime(true);
    }

    /**
     * An identifier given as an Identifier or a string, when it is of one of
     * the schemes the source reads; null when the string is none of them
     * (a name, for the sources that search by name).
     */
    protected static function identify(Identifier|string $id, Scheme ...$schemes): ?Identifier
    {
        if ($id instanceof Identifier) {
            return \in_array($id->scheme, $schemes, true) ? $id : null;
        }
        $parsed = Identifier::parse($id);
        if (null !== $parsed && \in_array($parsed->scheme, $schemes, true)) {
            return $parsed;
        }
        foreach ($schemes as $scheme) {
            // A value whose shape does not tell its scheme (an INSPIRE record number, an idHAL): the first scheme that reads it.
            if (null === $parsed && null !== ($identifier = Identifier::tryOf($scheme, $id))) {
                return $identifier;
            }
        }

        return null;
    }

    /** Strips tags (JATS, HTML) and collapses the white space of a text from the service. */
    protected static function text(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(/?)(jats:)?(p|sec|title)\b[^>]*>~', ' ', $text)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));

        return '' === $text ? null : $text;
    }

    /** "2024", "2024-09", "2024-09-25" from date parts. */
    protected static function date(int|string|null $year, int|string|null $month = null, int|string|null $day = null): ?string
    {
        if (null === $year || '' === $year || !is_numeric($year)) {
            return null;
        }
        $date = \sprintf('%04d', (int) $year);
        if (null !== $month && '' !== $month && is_numeric($month) && (int) $month >= 1 && (int) $month <= 12) {
            $date .= \sprintf('-%02d', (int) $month);
            if (null !== $day && '' !== $day && is_numeric($day) && (int) $day >= 1 && (int) $day <= 31) {
                $date .= \sprintf('-%02d', (int) $day);
            }
        }

        return $date;
    }
}
