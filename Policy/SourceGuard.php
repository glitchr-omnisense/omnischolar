<?php

namespace Omnischolar\Policy;

use Omnischolar\Exception\RateLimitedException;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\Usage;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * A source's usage policy as the site runs it, around every HTTP request the
 * source makes (HttpSource): the policy the source declares (its package's
 * policy.json), tightened by the site's ("policy", never looser), checked
 * against what the site is ("usage": a use the terms forbid fails at once,
 * UsageNotPermitted), counted in a limiter (in memory unless the registry
 * gives a shared one).
 *
 * Wait or refuse: before a request, a limit reached or a wait the service
 * asked for (a 429's or a 503's Retry-After, a RateLimit field with nothing
 * left) is waited out when it ends within $maxWait seconds (10 by default:
 * a per-second limit, arXiv's one request every three seconds) - the sleep
 * the sources did before; longer (a minute, an hour, a day spent), the
 * request is not sent: RateLimitedException::notSent(), with retryAfter.
 * A 429 is a RateLimitedException as before, and holds the next requests
 * for its Retry-After.
 */
final class SourceGuard
{
    /** Seconds a request may wait for the policy or the service. */
    public const DEFAULT_MAX_WAIT = 10.0;

    private ?PolicyGuard $guard = null;

    private readonly RateLimiterInterface $limiter;

    /**
     * @param \Closure(): UsagePolicy $declared the policy the source declares, read on the first request
     * @param array<string, mixed>    $policy   the site's tightening, in the policy schema's shape
     * @param array<string, mixed>    $usage    what the site is: commercial, open_source, contract
     */
    public function __construct(
        private readonly string $source,
        private readonly \Closure $declared,
        private readonly array $policy = [],
        private readonly array $usage = [],
        ?RateLimiterInterface $limiter = null,
        private readonly float $maxWait = self::DEFAULT_MAX_WAIT,
    ) {
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    /**
     * The guard: the policy as the site runs it, the limiter's counts.
     *
     * @throws UsageNotPermitted the source's terms forbid the site's use
     */
    public function guard(): PolicyGuard
    {
        if (null === $this->guard) {
            $declared = ($this->declared)();
            if (UsagePolicy::UNNAMED === $declared->provider) {
                $declared = $declared->named($this->source);
            }
            $effective = $declared->tighten($this->policy);
            if ([] !== $this->usage && [] !== $violations = $effective->violations(Usage::fromArray($this->usage))) {
                throw UsageNotPermitted::of($effective->provider, $violations);
            }
            $this->guard = new PolicyGuard($effective, $this->limiter);
        }

        return $this->guard;
    }

    public function policy(): UsagePolicy
    {
        return $this->guard()->policy();
    }

    /**
     * Before a request: returns when it may be sent, having waited if what
     * is left to wait ends within maxWait; throws otherwise, nothing sent.
     *
     * @throws RateLimitedException not sent (wasSent() false)
     */
    public function admit(string $operation): void
    {
        $guard = $this->guard();
        $deadline = microtime(true) + $this->maxWait;
        while (true) {
            try {
                $guard->check($operation);

                return;
            } catch (UsageLimitReached $reached) {
                $now = microtime(true);
                // retryAfter is rounded up: the window ends within (retryAfter - 1, retryAfter] seconds.
                if (null === $reached->retryAfter || $now >= $deadline || $now + $reached->retryAfter - 1 > $deadline) {
                    throw RateLimitedException::notSent($this->source, $reached);
                }
                usleep((int) (1_000_000 * min($deadline - $now, max(0.05, $reached->retryAfter - 1))));
            }
        }
    }

    /** A request sent, counted (failed or not: the service counted it too). */
    public function record(string $operation): void
    {
        $this->guard()->record($operation);
    }

    /**
     * An answer's status and headers: a Retry-After (on a 429 or a 503) or a
     * RateLimit field with nothing left holds the next requests; a 429 is a
     * RateLimitedException.
     *
     * @param array<string, list<string>> $headers by lower-case name
     *
     * @throws RateLimitedException on a 429
     */
    public function observe(int $status, array $headers): void
    {
        try {
            $this->guard()->backoff()->observe($status, $headers);
        } catch (UsageLimitReached $e) {
            throw new RateLimitedException($this->source, $e->retryAfter);
        }
    }
}
