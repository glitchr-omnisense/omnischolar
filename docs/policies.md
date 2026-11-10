---
title: Usage policies
order: 45
---

# Usage policies

Every source says, as data, what its service allows: how many requests (a second, a minute, a
day; per application, key or address; how many in flight), what may be done with the answers
(commercial use, caching, storage, redistribution), the credit to show and the data's licence,
the key or User-Agent it needs, and where all this was read. That is its `policy.json`, in the
schema of [omnisense/contracts](https://github.com/glitchr-omnisense/contracts/blob/1.x/docs/policies.md),
named in its `composer.json` (`extra.omnisense.policy`) and given by the source
(`HttpSource::usagePolicy()`, `DeclaresUsagePolicyInterface`).

Every HTTP request a source makes goes through that policy (`Policy\SourceGuard`, built by the
factory; `HttpSource::guard()` gives the kernel's `PolicyGuard`):

- **a limit reached waits, when its window ends within `max_wait` seconds** (10 by default): a
  per-second limit, one request every three seconds - the sleep the sources' `throttle` did
  before, now read from the policy. Longer (a minute's, an hour's or a day's budget spent), the
  request is **not sent**: a `RateLimitedException` (an `UnavailableException`, as a 429 always
  was) whose `wasSent()` is false, with `$retryAfter` (when the window ends) and `$limit`;
- a service's 429 is a `RateLimitedException` as before (`wasSent()` true, `$retryAfter` read
  from Retry-After in seconds or as a date), and it holds the next requests for its Retry-After;
  a 503's Retry-After and a `RateLimit` header field with nothing left hold them too - waited
  out within `max_wait`, refused beyond;
- every request sent counts, failed or not (the service counted it too);
- a source that declares nothing has an unknown policy: nothing limited, a 429 still held.

Why wait rather than refuse: callers relied on the sources sleeping between calls (the Collector
walks several sources in one call; arXiv's one request every three seconds), so the short waits
stay waits; a long one would block a page or a worker for minutes, so it refuses, as the service
itself would have answered 429. `max_wait: 0` refuses at once.

```php
$registry = new Registry($factories, [
    'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => 'support@glitchr.io', 'max_wait' => 5], 'usage' => ['commercial' => true]],
    'arxiv' => ['factory' => 'arxiv', 'policy' => ['rateLimits' => [['max' => 500, 'window' => 'day']]]],
], $limiter);   // where requests are counted: in memory by default; shared across processes
                // with omnibridge/symfony's RateLimiter over symfony/rate-limiter

$registry->policy('arxiv')->rights->attribution->text;   // the credit to show
$registry->guard('arxiv')->remaining(HttpSource::REQUEST);
foreach ($registry->policies() as $name => $policy) { /* a "sources and their terms" page */ }
```

- `policy` (beside `options` or in them) tightens the declared policy (`UsagePolicy::tighten()`):
  the site's limits are added (each counts, the strictest wins), one counting the same requests
  with a higher maximum is refused, permissions only go stricter (`allowed` < `unknown` <
  `contract` < `forbidden`), nothing required is lifted, the credit and licence cannot be
  rewritten. Anything looser is an `InvalidPolicyException` at the source's first request.
- `usage` says what the site is (`commercial`, `open_source`, `contract`): a use the terms forbid
  fails at the first request (`UsageNotPermitted`), before anything is sent.
- `throttle` stays: seconds left between two requests, the site's own spacing on top of the
  policy. It can slow a source down, never speed it past its policy.
- An operation is an HTTP request (`HttpSource::REQUEST`, "request"): the services count
  requests. A source calling APIs with limits of their own names them (`operation()`): Crossref's `single` and `list`, OpenAlex's `entity`, `list` and `search` (each at its cost in credits). Some figures depend on the options: Crossref's polite pool with a `mailto`, OpenAlex's with a key, Open Library's with a User-Agent naming a contact (`usagePolicy()` in those sources).

## The sources' policies

Read at each service's own pages on the date given (`provenance` in each file: the terms, the
rate-limit page, notes on what was read and what was not). Nothing is declared that the service
does not publish: an unknown limit is `null`, and a figure seen only elsewhere is in the notes.

| Source | Limits | Licence; commercial use | Credit | Needs | Read |
|---|---|---|---|---|---|
| arxiv | 1 every 3 seconds; 1 in flight | CC0-1.0; commercial allowed | - | - | 2026-11-10 |
| crossref | 5 a second (single), 1 a second (list); 1 in flight | not stated; commercial allowed | - | - | 2026-11-10 |
| hal | unknown | CC0-1.0; commercial allowed | - | - | 2026-11-10 |
| inspire | 15 every 5 seconds per client-ip | CC0-1.0; commercial unknown | asked: Moskovic, M. (2021). The INSPIRE REST API. Zenodo. https://doi.org/10.5281/zenodo.5788550 | - | 2026-11-10 |
| openalex | 10 a second, 1,000 units a day | CC0-1.0; commercial allowed | - | - | 2026-11-10 |
| openlibrary | 1 a second | not stated; commercial unknown | - | - | 2026-11-10 |
| orcid | 12 a second, 25,000 a day per client-ip | not stated; commercial contract | - | - | 2026-11-10 |
