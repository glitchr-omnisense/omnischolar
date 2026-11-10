<?php

namespace Omnischolar\Exception;

use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * The service asks to slow down (429), or the day's budget is spent (OpenAlex
 * without a key); or the call was not sent at all (notSent(), wasSent() false):
 * a limit of the source's usage policy is reached, or the service's wait is not
 * over, and what is left to wait is longer than the source may wait
 * (max_wait). $retryAfter says in how many seconds to try again, when known;
 * $limit which limit of the policy, when one did.
 */
final class RateLimitedException extends UnavailableException
{
    public function __construct(string $source, public readonly ?int $retryAfter = null, ?\Throwable $previous = null, public readonly ?RateLimit $limit = null, ?string $reason = null)
    {
        parent::__construct($source, $reason ?? ($retryAfter ? \sprintf('rate limited, retry in %d s', $retryAfter) : 'rate limited'), 429, $previous);
    }

    /** A call refused before it was sent: its previous is omnisense/contracts' UsageLimitReached. */
    public static function notSent(string $source, UsageLimitReached $reached): self
    {
        return new self($source, $reached->retryAfter, $reached, $reached->limit, 'not sent: '.$reached->getMessage());
    }

    /** False when the call was refused before it was sent (notSent()), true for the service's own 429. */
    public function wasSent(): bool
    {
        return !$this->getPrevious() instanceof UsageLimitReached;
    }
}
