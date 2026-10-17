# Symfony

Omnischolar runs without a framework ([installation](installation.md)); in a Symfony application
its bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` - are not required by `omniscient/omnischolar`: the application has them, and
nothing of them is loaded outside Symfony.

Register `Omnischolar\Bridge\Symfony\OmnischolarBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnischolar\Bridge\Symfony\OmnischolarBundle::class => ['all' => true],
];
```

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

Every `omnischolar/*` package installed registers its factory, on the application's
`http_client`. What is autowired:

| Service | |
|---|---|
| `SourceInterface $openalex` | one source, by the argument's name (the configured name: `$droit` is HAL on `shs.droit`) |
| `Registry` | every configured source by name (`get()`, `names()`, `all()`) |
| `Collector` | `collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani'])` |
| `Merger` | with the configured preferences |
| `Export` | |

An application's own source - a class implementing `SourceFactoryInterface` - is registered too,
autoconfigured, and can be named as a `factory`.

```php
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

Cache what the sources answer (they are remote, and some count their calls), except when
`$collector->incomplete` is not empty.
