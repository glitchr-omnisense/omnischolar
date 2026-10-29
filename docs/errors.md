---
title: Errors
order: 50
---

# Errors

Not found is `null` (`author()`, `work()`) or an empty page (`works()`, `search()`). Everything
else is an exception implementing `Omnischolar\Exception\OmnischolarException` (an interface
extending `\Throwable`):

| Exception | Extends | Thrown when |
|---|---|---|
| `InvalidConfigException` | `\LogicException` | a source not configured, a factory not installed, a required option missing |
| `NotSupportedException` | `\LogicException` | the source does not do that (`author()` of arXiv or Crossref, `search()` of ORCID), cannot read that identifier, or cannot filter by domain |
| `ProviderException` | `\RuntimeException` | the service answered with an error: any 4xx but 404, 410 and 429 (401 and 403 included); an answer that is not JSON or not a JSON object; arXiv's "Error" entry; an Atom answer that does not parse |
| `UnavailableException` | `ProviderException` | the service could not be reached or is down: 5xx, a transport error, a timeout |
| `RateLimitedException` | `UnavailableException` | 429: slow down, or OpenAlex's keyless daily budget spent |

`ProviderException` and its children keep what the service said:

| Property | |
|---|---|
| `source` | the source's name |
| `status` | the HTTP status, when there was one |
| `retryAfter` | `RateLimitedException` only: the seconds of `Retry-After`, when numeric |
| `getMessage()` | `[<source>] <message>`: `the service answered 503`, `the service answered 400: <the body's first 300 characters, tags stripped>`, `unreachable: <transport error>`, `rate limited, retry in 30 s` |
| `getPrevious()` | the HTTP client's exception, for a transport error |

The messages of `NotSupportedException`: `The "crossref" source does not know authors.`, `The
"orcid" source does not search.`, `The "openalex" source does not filter by domain.`, `The
"arxiv" source cannot look up "x".`.

## How HTTP becomes an answer or an exception

`HttpSource` (every provider) maps each answer the same way:

| Answer | Result |
|---|---|
| 2xx | the body |
| 404, 410 | `null`: not found (an empty page for a list) |
| 429 | `RateLimitedException`, `Retry-After` kept |
| 5xx | `UnavailableException` |
| any other 4xx | `ProviderException` |
| a transport error, a timeout | `UnavailableException` |

## What to catch

```php
use Omnischolar\Exception\ProviderException;
use Omnischolar\Exception\RateLimitedException;
use Omnischolar\Exception\UnavailableException;

try {
    $page = $registry->get('openalex')->works('A5108007452');
} catch (RateLimitedException $e) {
    $retry = $e->retryAfter;                                   // later, or with an api_key
} catch (UnavailableException) {
    // down: show what was cached, never "no publications", and cache nothing
} catch (ProviderException $e) {
    $logger->error($e->getMessage(), ['status' => $e->status]);
}
```

`Collector::collect()` catches `UnavailableException` (and so `RateLimitedException`) and
`NotSupportedException` for each source: the source is skipped, named in
`$collector->incomplete` with the message, and the others still answer. A `ProviderException`
that is not an `UnavailableException` is not caught: it reaches the caller.

`InvalidConfigException` and `NotSupportedException` are programming errors (`LogicException`s):
test `capabilities()` rather than catch them.

## Outside the family

- `InvalidArgumentException`: `Identifier::of()` and its shortcuts with a value that is not of the
  scheme; `Export::format()` with a format that is not one (`No "x" export; there are bibtex, ris,
  csl-json.`).
- `ValueError`: `Identifier::of()` with a scheme name that is not a `Scheme`.
- `JsonException`: `Export::cslJson()` on a value JSON cannot encode (an invalid UTF-8 string).

## No secret in a failure

A source keeps its key out of what `HttpSource` throws: in the query (OpenAlex's `api_key`) or a
header (a bearer), it is masked in the HTTP client's message (`Http\Redaction::text()`), the previous
exception is a copy of the client's (`Redaction::copy()`: the same class and code), and no trace of
the chain keeps its arguments, where the query and headers stood (`zend.exception_ignore_args` off).
