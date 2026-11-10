# Changelog

## [Unreleased]

### Added

- On omnisense/contracts, the kernel of the omni families, with usage policies. Every HTTP request
  a source makes goes through its usage policy (`Policy\SourceGuard`): the `policy.json` its
  package declares (`extra.omnisense.policy`; `HttpSource::usagePolicy()`, the kernel's
  `DeclaresUsagePolicyInterface`), tightened by the site's (`policy`, never looser), checked
  against what the site is (`usage`: a use the terms forbid fails at the first request,
  `UsageNotPermitted`), counted in the registry's limiter (in memory by default, a shared one as
  `Registry`'s new third argument). `Registry::guard()`, `policy()`, `policies()`;
  `HttpSource::guard()`, `sourceGuard()`, `operation()`; `Policy\PackagePolicy`; the options
  `policy`, `usage`, `max_wait`; the bundle's `policy`, `usage` (per source) and `limiter`.
- `RateLimitedException::notSent()`, `wasSent()`, `$limit`, and a fourth and fifth optional
  constructor argument (the limit, the message).
- Every source declares its policy, read at the service's pages on 2026-11-10 (docs/policies.md):
  arXiv's one request every three seconds, Crossref's public and polite pools by operation
  (single, list), OpenAlex's ten a second and its daily budget in credits (an entity free, a list
  1, a search 10; ten times more with a key), Open Library's one or three a second, INSPIRE's 15
  every five seconds, ORCID's 12 a second and 25,000 a day; licences (CC0 for arXiv's, HAL's,
  INSPIRE's and OpenAlex's metadata), credits, keys.
- `Testing\BareTestCase` (the kernel's bare check, the family's core allowed) and
  `Tests/LoadAllTest`: every class of the core loads with omnisense/contracts and the HTTP client
  contracts alone; the Symfony bundle throws a `LogicException` naming its components when they
  are missing (they stay suggested).
- PHPStan at level max on the core and every source (`phpstan.neon`), each baseline what was there
  on 2026-11-10; the harness runs it (`phpstan`), installs omnisense/contracts from its checkout
  (`OMNISENSE_CONTRACTS`) or GitHub, and runs on Docker's default bridge.

### Changed

- **A limit reached waits or refuses.** A request over a limit of the source's policy waits when
  the limit's window ends within `max_wait` seconds (10 by default) - the sleep the sources'
  default `throttle` did, now read from the policy, and kept for the callers that relied on it
  (the Collector, arXiv's three seconds); longer, it is not sent: a `RateLimitedException` (an
  `UnavailableException`, as the service's 429 was) with `wasSent()` false. A `throttle` of 0 no
  longer sends faster than the service allows: OpenAlex, Crossref and ORCID, at 0 by default, now
  keep to their published figures (Crossref's public pool: one list a second).
- **A 429 holds the next requests** for its Retry-After (and a 503's Retry-After, a `RateLimit`
  field with nothing left): waited out within `max_wait`, refused beyond, where they were sent
  before. Its `retryAfter` reads a Retry-After given as a date too (null before).
- `Registry::get()` gives the factory the options configured plus `omnischolar.name` and
  `omnischolar.limiter`; `options()` is unchanged.
- `OmnischolarException` extends omnisense/contracts' `ExceptionInterface`;
  `InvalidConfigException` its `InvalidConfigException` (an `\InvalidArgumentException`, a
  `\LogicException` as before); `Config` its `Config` (prefix `omnischolar`, the same methods and
  messages); `Http\Redaction` its `Redaction` (the same methods).

### Fixed

- No key in what `HttpSource` throws. An unreachable service's `UnavailableException` quoted the
  HTTP client's message, the whole URL with a key in its query (OpenAlex's `api_key=`), and wrapped
  the client's own exception, and every exception `get()` and `getJson()` threw - unreachable, 429,
  5xx, 4xx, not JSON - kept in its trace the query and headers with the key
  (`zend.exception_ignore_args` off). The message and the previous - now a copy of the client's
  exception, the same class and code - have it masked, and none of them keeps its trace's arguments.

### Added

- `Omnischolar\Http\Redaction`: a request's secrets (`secrets()`), a text without them (`text()`), a
  failure's copy to wrap in its place (`copy()`), a trace without arguments (`withoutArguments()`).
- `Config::bool($key, $default)`: an option read as a boolean whatever its spelling (`'false'`,
  `'0'`, `'no'`, `'off'` false; `'true'`, `'1'`, `'yes'`, `'on'` true), a value that is no boolean
  an `InvalidConfigException`. PHP's `(bool)`, which omnischolar/openalex's `paratext` and
  omnischolar/openlibrary's `editions` were read with, took the string `'false'` for true.
