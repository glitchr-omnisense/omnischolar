<?php

namespace Omnischolar\Source;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\ProviderException;
use Omnischolar\Exception\RateLimitedException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Http\Redaction;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Omnischolar\Policy\PackagePolicy;
use Omnischolar\Policy\SourceGuard;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the sources over HTTP share: the calls, their errors mapped the same
 * way (404 is null; 429 a RateLimitedException; 5xx and transport errors an
 * UnavailableException; any other 4xx a ProviderException), a minimum
 * interval between two calls (the throttle option: the site's spacing),
 * and the reading of identifiers given as strings.
 *
 * Every request goes through the source's usage policy (SourceGuard): the
 * policy.json of its package (usagePolicy()), as the site tightens it; a
 * limit reached waits when its window ends within max_wait seconds, else
 * the request is not sent (RateLimitedException::notSent()). A request is
 * counted as the operation operation() names ("request" unless a source
 * calls several APIs with their own limits).
 */
abstract class HttpSource implements SourceInterface, DeclaresUsagePolicyInterface
{
    /** The operation every request counts as, unless operation() names another. */
    public const REQUEST = 'request';

    private float $lastCall = 0.0;

    private ?UsagePolicy $declared = null;

    private ?SourceGuard $sourceGuard = null;

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

    /**
     * The usage policy the source declares: its package's policy.json
     * (extra.omnisense.policy in its composer.json), unknown when it has
     * none. A source whose limits depend on its options (a key) overrides it.
     */
    public function usagePolicy(): UsagePolicy
    {
        return $this->declared ??= PackagePolicy::of(static::class) ?? UsagePolicy::unknown($this->getName());
    }

    /**
     * The guard the source's requests go through: its factory gives it the
     * site's tightening, usage and limiter; built on first use otherwise.
     */
    public function sourceGuard(): SourceGuard
    {
        return $this->sourceGuard ??= new SourceGuard($this->getName(), fn (): UsagePolicy => $this->usagePolicy());
    }

    /** The kernel's guard: the policy as the site runs it, remaining(), cacheTtl(). */
    public function guard(): PolicyGuard
    {
        return $this->sourceGuard()->guard();
    }

    /**
     * Set by the factory once the source is built: the guard its requests
     * go through, the policy its package declares (that of the factory's
     * package, for a source class another package also builds).
     *
     * @internal
     */
    public function governedBy(SourceGuard $guard, ?UsagePolicy $declared = null): static
    {
        $this->sourceGuard = $guard;
        if (null !== $declared) {
            $this->declared = $declared;
        }

        return $this;
    }

    /** The operation a request counts as in the policy: "request", or the API it goes to. */
    protected function operation(string $method, string $url): string
    {
        return self::REQUEST;
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
        $operation = $this->operation('GET', $url);
        $guard = $this->sourceGuard();
        try {
            $guard->admit($operation);
        } catch (RateLimitedException $e) {
            throw Redaction::withoutArguments($e);
        }
        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers + $this->headers]);
            $status = $response->getStatusCode();
            try {
                $guard->observe($status, $response->getHeaders(false));
            } catch (RateLimitedException $e) {
                throw Redaction::withoutArguments($e);
            }
            if (404 === $status || 410 === $status) {
                return null;
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
        } finally {
            $guard->record($operation);
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
