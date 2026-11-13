---
title: Installation
order: 10
---

# Installation

```sh
composer require omnisense/omnischolar omnischolar/openalex omnischolar/hal omnischolar/openlibrary
```

## Requirements

- PHP 8.2 or later. With `ext-intl`, the Merger compares titles across accents more thoroughly (a
  fallback table covers the Latin accents without it).
- `omnisense/omnischolar` requires `symfony/http-client-contracts` (^3.0) and nothing else: no
  framework, no HTTP client of its own. It replaces `omniscient/omnischolar`: a `composer.json`
  that still requires the old name resolves to this package.
- Each provider requires `omnisense/omnischolar` and `symfony/http-client` (6.4, 7 or 8); arXiv
  needs `ext-dom` (its answers are Atom). A factory takes an `HttpClientInterface` (the
  application's, a `MockHttpClient` in a test) and makes its own with `HttpClient::create()` when
  given none.
- The Symfony bundle needs `symfony/config`, `symfony/dependency-injection` and
  `symfony/http-kernel`; none is required by the package ([Symfony](symfony.md)).

It runs the same in plain PHP, in a worker, in Laravel or Slim, and in Symfony.

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

$author = $registry->get('openalex')->author('A5046427077');
echo $author->name, ': ', $author->metrics->works, ' works, h-index ', $author->metrics->hIndex, "\n";

$works = (new Collector($registry))->collect(['openalex' => 'A5046427077', 'hal' => 'Alain Aspect'], new Query(from: 2022, to: 2022));
foreach ($works as $work) {
    echo $work->date, '  ', $work->title, ' - ', $work->venue?->name, ' [', implode(', ', $work->sources), "]\n";
}
echo (new Export())->bibtex($works[0]);
```

```
$ php bare.php
Alain Aspect: 686 works, h-index 64
2022-11-01  Bichromatic state-dependent disordered potential for Anderson localization of ultracold atoms - The European Physical Journal D [openalex, hal]
2022-08-25  Full counting statistics of interacting lattice gases after an expansion: quantifying many-body coherence from atom correlations - HAL [openalex]
2022-08-25  Towards the 3D Anderson Transition with Ultracold Atoms in Bichromatic Speckles - HAL [openalex, hal]
2022-07-28  Full counting statistics of interacting lattice gases after an expansion: The role of the condensate depletion in the many-body coherence - arXiv (Cornell University) [openalex]
2022-05-02  Ultracold atoms in strong disorder : towards the Anderson transition - Quantum Information Spring School [hal]
2022-04-19  Towards the study of 3D Anderson transition by using a two-wavelength speckle disorder - HAL [openalex, hal]
2022-04-19  Ultracold atoms in strong disorder : towards the Anderson transition - Palaiseau Florence workshop on ultracold atoms [hal]
2022-02-17  Ultracold atoms in strong disorder : towards the Anderson transition - Simons Collaboration on Localization of Waves Annual Meeting 2022 [hal]
2022-02-14  Spectral functions and localization-landscape theory in speckle potentials - Physical Review A [openalex, hal]
@article{lecoutre2022bichromatic,
  author = {Lecoutre, Baptiste and Guo, Yukun and Yu, Xudong and Niranjan, M. and Mukhtar, Musawwadah and Volchkov, Valentin and Aspect, Alain and Josse, Vincent},
  title = {{Bichromatic state-dependent disordered potential for Anderson localization of ultracold atoms}},
  journal = {The European Physical Journal D},
  year = {2022},
  month = {nov},
  volume = {76},
  number = {11},
  pages = {218},
  doi = {10.1140/epjd/s10053-022-00549-6},
  issn = {1434-6060},
  hal_id = {hal-03843937},
  url = {https://doi.org/10.1140/epjd/s10053-022-00549-6},
  language = {en},
  keywords = {Anderson localization, ultracold atoms, disorder potential, Bose-Einstein condensate, spontaneous scattering, quantum transport}
}
```

(called live on 2026-11-13 from the harness's image, with a `mailto`: each work once, from
OpenAlex and HAL merged - a poster HAL holds three times, at three venues, stays three records)

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

$author = $openalex->author('A5046427077');
$author->metrics->hIndex;                    // 64 on 2026-11-13
$author->orcid();                            // 0000-0003-0094-5584

$page = $openalex->works('A5046427077', new Query(from: 2022, to: 2022));
foreach ($page as $work) {
    echo $work->year, ' ', $work->title, ' - ', $work->venue?->name, "\n";
}
$next = $openalex->works('A5046427077', new Query(cursor: $page->next));   // the next page
```

An author or a work is named by an `Identifier` or a string: one of the source's own
identifiers (`'A5046427077'`, `'OL9990001A'`, `'philippe-grangier'`, `'Juan.M.Maldacena.1'`), a
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
$works = $collector->collect(['openalex' => 'A5046427077', 'hal' => 'Alain Aspect']);
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

Not found is `null` or an empty page; everything else is an exception implementing
`Omnischolar\Exception\OmnischolarException`. A service that is down or asks to slow down is an
`UnavailableException` (or `RateLimitedException`), never "nothing": see [Errors](errors.md).
