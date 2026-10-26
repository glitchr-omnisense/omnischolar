# omnisense/omnischolar

One contract for a researcher's publications: their profile, their works, their books and the
editions of each, their citations - read from the services that know them, merged into one
list, exported as BibTeX, RIS or CSL-JSON.

Published as `omnisense/omnischolar` (formerly `glitchr/omnischolar`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package, never both).
`omnisense/` is the vendor of every family's core; the providers keep theirs (`omnischolar/crossref`...).

```php
$works = $collector->collect([
    'openalex' => 'A5108007452',             // Keitaro Nakatani on OpenAlex
    'hal' => 'Keitaro Nakatani',             // his deposits in HAL, by the name he signs
    'crossref' => '0009-0005-1387-7295',     // the works deposited with his ORCID
]);
$works[0]->title;                            // one record per work, each field from the source trusted for it
$works[0]->pdfUrl;                           // the full text deposited in HAL
$export->bibtex($works);                     // @article{chocron2024acid, ...}

$books = (new Merger())->merge($openlibrary->works('Monica Neagoy'));
$books[0]->editions;                         // the editions of one book, newest first
```

This package holds the contract (`Source\SourceInterface`, `Source\SourceFactory`, `Registry`),
the models (`Work`, `Author`, `Contributor`, `Identifier`, `Venue`, `Metrics`, `Affiliation`), the
`Merger`, the `Collector`, the `Export` and a bridge for Symfony. It needs no framework: it
requires nothing but `symfony/http-client-contracts`, each source package `symfony/http-client`.
Each source is a package of its own:

| Package | Source | Key |
|---|---|---|
| [`omnischolar/openalex`](https://github.com/glitchr-omnisense/omnischolar-openalex) | OpenAlex: profiles and counts (h-index), works, citations, abstracts | none (`mailto`; a free key raises the daily budget) |
| [`omnischolar/hal`](https://github.com/glitchr-omnisense/omnischolar-hal) | HAL: French open archive, full texts, filter by domain (`shs.droit`) | none |
| [`omnischolar/crossref`](https://github.com/glitchr-omnisense/omnischolar-crossref) | Crossref: reference metadata by DOI, works by ORCID | none (`mailto`) |
| [`omnischolar/orcid`](https://github.com/glitchr-omnisense/omnischolar-orcid) | ORCID: identity, CV (positions, degrees, distinctions), declared works | none (public API) |
| [`omnischolar/openlibrary`](https://github.com/glitchr-omnisense/omnischolar-openlibrary) | Open Library: books, one record per edition, covers | none |
| [`omnischolar/arxiv`](https://github.com/glitchr-omnisense/omnischolar-arxiv) | arXiv: preprints | none |
| [`omnischolar/inspire`](https://github.com/glitchr-omnisense/omnischolar-inspire) | INSPIRE-HEP: physics literature, citation summary | none |

A source answers what it can - `capabilities()` says what - and throws `NotSupportedException`
for the rest. Unknown is `null` or an empty page; a service down or rate limited is an
`UnavailableException` (`RateLimitedException`), which a caller never caches as "nothing".

The `Merger` takes one record per work: same DOI, then same ISBN (books), arXiv id, HAL id,
then same title in the same year - a different DOI on both sides always keeps two records apart.
Each field comes from the source preferred for it (configurable), identifiers are united, and
the editions of a book are grouped under the newest one.

## Documentation

- [Installation and first calls](docs/installation.md)
- [Sources: what each can and cannot do](docs/sources.md)
- [Models and identifiers](docs/models.md)
- [Merging: the rules and the preferences](docs/merging.md)
- [Export: BibTeX, RIS, CSL-JSON](docs/export.md)
- [Symfony](docs/symfony.md)
- [The Docker harness](docs/harness.md)

## Plain PHP

```sh
composer require omnisense/omnischolar omnischolar/openalex omnischolar/hal
```

```php
use Omnischolar\Collector;
use Omnischolar\Hal\HalSourceFactory;
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();   // or the application's client; a MockHttpClient in a test
$registry = new Registry([new OpenAlexSourceFactory($http), new HalSourceFactory($http)], [
    'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => 'you@example.org']],
    'hal' => ['factory' => 'hal'],
]);
$openalex = $registry->get('openalex');
$works = (new Collector($registry))->collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani']);
```

No bundle, no container: a factory per source package, the registry built by hand.
[docs/installation.md](docs/installation.md) opens on a whole script that runs as it is, against
the real services.

## Symfony

`Omnischolar\Bridge\Symfony\OmnischolarBundle` does that wiring in a Symfony application
([docs/symfony.md](docs/symfony.md)); its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`) are not required by this package.

```yaml
omnischolar:
    sources:
        openalex: { factory: openalex, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
        hal: { factory: hal }
        droit: { factory: hal, options: { domains: [shs.droit] } }
        openlibrary: { factory: openlibrary, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
    merger:
        preferences: { abstract: [hal, openalex] }
```

```php
public function __construct(SourceInterface $openalex, Collector $collector, Export $export) {}
```

## Docker: every source, for real

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnischolar sources
docker compose run --rm omnischolar works openalex A5108007452 --from 2024 --to 2024
docker compose run --rm omnischolar merge openlibrary:"Monica Neagoy" crossref:"Monica Neagoy"
docker compose run --rm omnischolar bare      # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnischolar test
```

## Responsibility

`omnisense/omnischolar` and its `omnischolar/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (licence of their data, attribution or citation, keys, rate limits), named with a link in the
"Responsibility" section of the README of each provider that reaches one. The MIT licence covers
this code only and grants no right to any service or to its data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
