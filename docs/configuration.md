---
title: Configuration reference
order: 40
---

# Configuration reference

Every option below is read from the factories' code at this commit (`populate()` and what `build()`
reads). Options reach a factory through `create($options)`, a `Registry`'s `options`, or the
bundle's `omnischolar.sources.<name>.options` ([Symfony](symfony.md#configuration)).

## The registry

```php
new Registry(iterable $factories, array $config);
// $config: ['openalex' => ['factory' => 'openalex', 'options' => ['mailto' => 'you@example.org']], ...]
```

| Method | |
|---|---|
| `get(string $name): SourceInterface` | the source, built on the first call and kept |
| `has(string $name): bool` | whether it is configured |
| `names(): list<string>` | the configured sources, in the configured order |
| `all(): array<string, SourceInterface>` | every configured source, built |
| `factories(): list<string>` | the factories installed: what `factory` may name |
| `options(string $name): array` | the options it is configured with (the factory's defaults not included) |

A later factory of the same name replaces an earlier one. A name not configured is an
`InvalidConfigException` (`No "x" source; configured: openalex, hal.`), so is a factory not
installed (`No "x" factory for the "y" source; installed: openalex.`). One factory may serve
several names with different options (`hal`, and `droit` filtered on `shs.droit`).

## How options are read

`Omnischolar\Config` (an `ArrayObject`) holds the options given, with the factory's defaults under
them: `defaults()` sets each key not given **or given as `null`**. `get($key, $default)` gives the
default for a key missing or `null`. Before `build()`, `validateNotEmpty()` refuses each key of
`omnischolar.required_options` that is missing, `''` or `[]` (`The "x" source needs: key.`); no
provider requires any option today. The keys every factory sets for itself:
`omnischolar.factory_name` and `omnischolar.required_options`.

## Options every source takes

| Option | Type | Default | |
|---|---|---|---|
| `mailto` | string | `null` | a contact address: added to the `User-Agent` (`omnischolar/1.x (+https://github.com/glitchr-omnisense/omnischolar; mailto:<address>)`), and sent as a parameter by OpenAlex and Crossref (their polite pools) |
| `user_agent` | string | `omnischolar/1.x (+https://github.com/glitchr-omnisense/omnischolar)` and the `mailto` | the `User-Agent` of every call; when set, the `mailto` is not added to it |
| `base_uri` | string | each provider's | the service's address, to point at a copy, a proxy or a sandbox |
| `throttle` | float | each provider's (below) | seconds left between two calls of one source; `0.0` for OpenAlex, Crossref, HAL and ORCID |

A factory's constructor takes the HTTP client: `new OpenAlexSourceFactory(?HttpClientInterface
$http = null)`; with none, `HttpClient::create()`.

## openalex

| Option | Type | Default | |
|---|---|---|---|
| `mailto` | string | `null` | sent with every call as OpenAlex asks of polite callers |
| `api_key` | string | `null` | a free key (openalex.org/settings/api): ten times the keyless daily budget; sent as `api_key` |
| `paratext` | bool | `false` | `true` keeps covers, tables of contents and the like (`is_paratext:false` is filtered otherwise) |
| `base_uri` | string | `https://api.openalex.org/` | |

## hal

| Option | Type | Default | |
|---|---|---|---|
| `domains` | list of strings | `[]` | HAL domain codes every query is filtered on (`shs.droit`, `chim`, `phys.cond`...); `Query::$domains` adds to them |
| `base_uri` | string | `https://api.archives-ouvertes.fr/` | |

## crossref

| Option | Type | Default | |
|---|---|---|---|
| `mailto` | string | `null` | sent with every call and in the `User-Agent`: Crossref's polite pool |
| `base_uri` | string | `https://api.crossref.org/` | |

## orcid

| Option | Type | Default | |
|---|---|---|---|
| `base_uri` | string | `https://pub.orcid.org/v3.0/` | the sandbox: `https://pub.sandbox.orcid.org/v3.0/` |

## openlibrary

| Option | Type | Default | |
|---|---|---|---|
| `editions` | bool | `true` | one record per edition (the Merger groups them); `false`: one per work |
| `throttle` | float | `0.4` | Open Library allows about three calls a second to a named caller |
| `base_uri` | string | `https://openlibrary.org/` | |

## arxiv

| Option | Type | Default | |
|---|---|---|---|
| `throttle` | float | `3.0` | one call every three seconds, as arXiv asks |
| `base_uri` | string | `https://export.arxiv.org/api/` | |

## inspire

| Option | Type | Default | |
|---|---|---|---|
| `throttle` | float | `0.35` | INSPIRE allows 15 calls in 5 seconds |
| `base_uri` | string | `https://inspirehep.net/api/` | |

`paratext` and `editions` are read with PHP's `(bool)`: give a real boolean (the string `'false'`
is true).

## The Merger, the Collector, the Export

| Constructor | |
|---|---|
| `new Merger(array $preferences = [], bool $editions = true)` | `$preferences`: field => source names in the order trusted, `default` for the fields not named; each field given replaces its default order ([Merging](merging.md)) |
| `new Collector(Registry $registry, Merger $merger = new Merger())` | `collect(array $profiles, ?Query $query = null, int $max = 2000)`: source name => an author (or a list of authors) there |
| `new Export(bool $abstracts = false)` | `true` writes the abstracts ([Export](export.md)) |

The bundle's `omnischolar.merger` and `omnischolar.export` set them ([Symfony](symfony.md#configuration)).
