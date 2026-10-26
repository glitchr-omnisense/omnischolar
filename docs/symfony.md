---
title: Symfony
order: 15
---

# Symfony

Omnischolar runs without a framework ([Installation](installation.md)); in a Symfony application
its bundle does the wiring. The bundle lives in this package (`Omnischolar\Bridge\Symfony`), not
in omnibridge/symfony. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` (6.4, 7 or 8) - are not required by `omnisense/omnischolar`: the application
has them, and nothing of them is loaded outside Symfony.

## Registering the bundle

There is no Flex recipe: add the bundle by hand.

```php
// config/bundles.php
return [
    // ...
    Omnischolar\Bridge\Symfony\OmnischolarBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/omnischolar.yaml
omnischolar:
    sources:                       # by name: a factory and its options
        openalex: { factory: openalex, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%', api_key: '%env(default::OPENALEX_API_KEY)%' } }
        hal: { factory: hal }
        droit: { factory: hal, options: { domains: [shs.droit] } }
        crossref: { factory: crossref, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
        orcid: { factory: orcid }
        openlibrary: { factory: openlibrary, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
    merger:
        preferences:               # field => sources, "default" for the others
            default: [crossref, openalex, hal]
            abstract: [hal, openalex]
        editions: true             # group the editions of a book
    export:
        abstracts: false           # write the abstracts into the exports
```

The whole tree, from `OmnischolarBundle::configure()`:

| Key | Type | Default | |
|---|---|---|---|
| `omnischolar.sources` | map, keyed by the source's name | `{}` | the sources the application uses; one factory may serve several names (`hal`, and `droit` filtered on `shs.droit`) |
| `omnischolar.sources.<name>.factory` | string, required, not empty | - | `openalex`, `hal`, `crossref`, `orcid`, `openlibrary`, `arxiv`, `inspire`, or an application's own factory's `getName()` |
| `omnischolar.sources.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()` ([Configuration](configuration.md)) |
| `omnischolar.merger.preferences` | map: field => list of source names | `{}` | each field given replaces that field's default order; the others keep theirs ([Merging](merging.md#each-field-from-its-preferred-source)) |
| `omnischolar.merger.editions` | bool | `true` | group the editions of a book under one record |
| `omnischolar.export.abstracts` | bool | `false` | write the abstracts into BibTeX, RIS and CSL-JSON |

The options are not validated when the container compiles but when the source is built.

## Services

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnischolar\Registry` | `Registry` | `Registry $registry` | every configured source by name. Public. |
| `omnischolar.source.<name>` | `SourceInterface` | `SourceInterface $<name>` | one source, built by `Registry::get('<name>')` the first time it is injected |
| `Omnischolar\Merger` | `Merger` | `Merger $merger` | with `merger.preferences` and `merger.editions` |
| `Omnischolar\Collector` | `Collector` | `Collector $collector` | over the registry and that merger |
| `Omnischolar\Export` | `Export` | `Export $export` | with `export.abstracts` |
| `Omnischolar\OpenAlex\OpenAlexSourceFactory`, `...\Hal\HalSourceFactory`, `...\Crossref\CrossrefSourceFactory`, `...\Orcid\OrcidSourceFactory`, `...\OpenLibrary\OpenLibrarySourceFactory`, `...\Arxiv\ArxivSourceFactory`, `...\Inspire\InspireSourceFactory` | the provider's factory | by its class | registered when its package is installed; given the `http_client` service (or `null` when the application has none); tagged `omnischolar.source_factory` |

```php
use Omnischolar\Collector;
use Omnischolar\Export;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationsController extends AbstractController
{
    #[Route('/publications.bib')]
    public function bibtex(Collector $collector, Export $export): Response
    {
        $works = $collector->collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani']);

        return new Response($export->bibtex($works), 200, ['Content-Type' => Export::contentType('bibtex')]);
    }
}
```

`SourceInterface $droit` is the source configured as `droit` (HAL on `shs.droit`): the argument's
name selects it. Every source implements the whole `SourceInterface`, so the one alias per source
is enough; what a source does not do is a `NotSupportedException` when it is asked.

Nothing is built when the container compiles: a source is built the first time it is asked for.

## An application's own source

A class of the application that implements `Omnischolar\Source\SourceFactoryInterface` (usually
by extending `Omnischolar\Source\SourceFactory`) is autoconfigured: tagged
`omnischolar.source_factory`, it joins the registry, and its `getName()` can be named as a
`factory` ([Writing a provider](providers.md)).

The bundle registers no command, event, Twig function or route. Cache what the sources answer
(they are remote, and some count their calls), except when `$collector->incomplete` is not empty.

`Tests/Bridge/OmnischolarBundleTest.php` boots a kernel with the bundle: the seven installed
factories and an autoconfigured one of the test's own registered, sources injected by their
argument's name, the collector merging with the configured preferences, abstracts exported as
configured.
