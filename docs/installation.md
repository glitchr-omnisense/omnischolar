# Installation and first calls

```sh
composer require omniscient/omnischolar omnischolar/openalex omnischolar/hal omnischolar/openlibrary
```

PHP 8.2 or later. With `ext-intl`, the Merger compares titles across accents more thoroughly (a
fallback table covers the Latin accents without it).

Omnischolar needs no framework. The core requires nothing but `symfony/http-client-contracts`,
each source package `symfony/http-client`: two libraries that stand alone. It runs the same in
plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle does the wiring
([Symfony](symfony.md)).

## Plain PHP

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnischolar\Collector;
use Omnischolar\Export;
use Omnischolar\Hal\HalSourceFactory;
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\Registry;
use Omnischolar\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry([new OpenAlexSourceFactory($http), new HalSourceFactory($http)], [
    'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => getenv('OMNISCHOLAR_MAILTO') ?: null]],   // your address: OpenAlex asks for one
    'hal' => ['factory' => 'hal'],
]);

$author = $registry->get('openalex')->author('A5108007452');
echo $author->name, ': ', $author->metrics->works, ' works, h-index ', $author->metrics->hIndex, "\n";

$works = (new Collector($registry))->collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani'], new Query(from: 2024, to: 2024));
foreach ($works as $work) {
    echo $work->date, '  ', $work->title, ' - ', $work->venue?->name, ' [', implode(', ', $work->sources), "]\n";
}
echo (new Export())->bibtex($works[0]);
```

```
$ OMNISCHOLAR_MAILTO=you@example.org php bare.php
Keitaro Nakatani: 288 works, h-index 51
2024-10-04  Complete kinetic and photochemical characterization of the multi-step photochromic reaction of donor–acceptor Stenhouse adducts - Physical Chemistry Chemical Physics [openalex, hal]
2024-09-25  Cascade Fluorescence Modulation in Photochromic Microcapsules - ACS Applied Materials & Interfaces [openalex, hal]
2024-09-03  Tuning the Thermal Stability of Tetra ‐ o– chloroazobenzene Derivatives by Transforming Push‐Pull to Push‐Push Systems - Chemistry - A European Journal [openalex, hal]
2024-01-01  Acid-sensitive photoswitches: towards catalytic on-demand release of stored light energy - Chemical Science [openalex, hal]
@article{malletroit2024complete,
  author = {Mallétroit, Julien and Djian, Aurélie and Nakatani, Keitaro and Xie, Juan and Métivier, Rémi and Laurent, Guillaume},
  title = {{Complete kinetic and photochemical characterization of the multi-step photochromic reaction of donor–acceptor Stenhouse adducts}},
  journal = {Physical Chemistry Chemical Physics},
  year = {2024},
  ...
}
```

(as answered on 2026-10-05: each work once, from OpenAlex and HAL merged)

No key, no account: the script asks the real services. That is all there is to it:

- a **factory** per source package (`OpenAlexSourceFactory`, `HalSourceFactory`,
  `CrossrefSourceFactory`, `OrcidSourceFactory`, `OpenLibrarySourceFactory`, `ArxivSourceFactory`,
  `InspireSourceFactory`), which takes the HTTP client to call with - the application's, a
  `MockHttpClient` in a test; with none given it makes its own (`HttpClient::create()`);
- the **registry**, built by hand from the factories and the sources' options, by name;
- the **sources** it gives, and what works on them: the `Collector`, the `Merger`, the `Export`.

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnischolar bare`
([harness](harness.md)).

## One source

```php
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$openalex = (new OpenAlexSourceFactory(HttpClient::create()))->create(['mailto' => 'support@glitchr.io']);

$author = $openalex->author('A5108007452');
$author->metrics->hIndex;                    // 51
$author->orcid();                            // 0009-0005-1387-7295

$page = $openalex->works('A5108007452', new Query(from: 2024, to: 2024));
foreach ($page as $work) {
    echo $work->year, ' ', $work->title, ' - ', $work->venue?->name, "\n";
}
$next = $openalex->works('A5108007452', new Query(cursor: $page->next));   // the next page
```

An author or a work is named by an `Identifier` or a string: one of the source's own
identifiers (`'A5108007452'`, `'OL7092953A'`, `'keitaro-nakatani'`, `'Juan.M.Maldacena.1'`), a
resolver URL (`https://orcid.org/...`, `https://doi.org/...`), or - for the sources that search
by name (HAL, Crossref, Open Library, arXiv, INSPIRE) - a name.

## Several sources

```php
use Omnischolar\Collector;
use Omnischolar\Registry;

$registry = new Registry(
    [new OpenAlexSourceFactory($http), new HalSourceFactory($http), new CrossrefSourceFactory($http)],
    [
        'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => 'support@glitchr.io']],
        'hal' => ['factory' => 'hal'],
        'crossref' => ['factory' => 'crossref', 'options' => ['mailto' => 'support@glitchr.io']],
    ],
);
$collector = new Collector($registry);
$works = $collector->collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani']);
$collector->incomplete;   // the sources skipped (down, rate limited): do not cache this answer
```

`Collector::collect()` follows every page of each source (up to `$max`), then merges. To read one
source's pages into one list, `Collector::all($source, $author, $query, $max)`.

## In a framework

- **Symfony**: `Omnischolar\Bridge\Symfony\OmnischolarBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnischolar.yaml`, and
  autowires each source by its name, the `Collector`, the `Merger` and the `Export`: see
  [Symfony](symfony.md). Its components (`symfony/config`, `symfony/dependency-injection`,
  `symfony/http-kernel`) are not required by this package: a Symfony application has them.
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.

## Errors

| Exception | When |
|---|---|
| `NotSupportedException` | the source does not do that, or cannot read that identifier |
| `RateLimitedException` | 429: slow down (`$retryAfter` seconds, when the service says) |
| `UnavailableException` | 5xx, a timeout, a network error: never "nothing" |
| `ProviderException` | any other error answer (a malformed query: 400) |
| `InvalidConfigException` | a source not configured, a factory not installed, an option missing |

All implement `Omnischolar\Exception\OmnischolarException`. Not found is `null` or an empty page.
