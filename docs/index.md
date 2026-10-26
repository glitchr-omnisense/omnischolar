# omnisense/omnischolar

Omnischolar is one PHP contract for a researcher's publications and profile, whoever knows them:
OpenAlex, HAL, Crossref, ORCID, Open Library, arXiv and INSPIRE-HEP. The application asks a
source by name for an author, their works, one work or a search, gets neutral models, merges what
several sources say into one list and exports it for a reference manager:

```php
$author = $registry->get('openalex')->author('A5108007452');
$author->metrics->hIndex;                                    // OpenAlex's count

$works = (new Collector($registry))->collect([               // every page of each source, merged
    'openalex' => 'A5108007452',
    'hal' => 'Keitaro Nakatani',
    'crossref' => '0009-0005-1387-7295',
]);
$works[0]->sources;                                          // ['openalex', 'hal']: one record per work
(new Export())->bibtex($works);                              // @article{malletroit2024complete, ...}
```

Each service is a provider package (`omnischolar/openalex`, `omnischolar/hal`,
`omnischolar/crossref`, `omnischolar/orcid`, `omnischolar/openlibrary`, `omnischolar/arxiv`,
`omnischolar/inspire`) with a factory that builds a **source**. Every source answers the same four
questions (`author()`, `works()`, `work()`, `search()`) and says with its `capabilities()` which
it can answer and what its answers hold; an operation it does not do is a
`NotSupportedException`. Identifiers (DOI, ISBN, ISSN, arXiv, HAL, idHAL, ORCID, OpenAlex,
INSPIRE, Open Library, PubMed) are normalised and checked without the network. The **Merger**
makes one record of the works several sources list, each field from the source trusted for it,
and groups the editions of a book; the **Export** writes BibTeX, RIS and CSL-JSON. The core
requires PHP 8.2 and `symfony/http-client-contracts`, nothing of a framework; it ships a Symfony
bundle.

## When to use it

- A researcher's or a lab's site that lists its publications, kept in step with the open
  databases rather than typed by hand, each work once.
- A profile page: positions and degrees (ORCID, INSPIRE), counts (OpenAlex, INSPIRE), books with
  their covers (Open Library).
- A bibliography to download (`.bib`, `.ris`, CSL-JSON for citeproc or pandoc).

It is not a citation database of its own: counts are each source's, and differ from one to the
next. Every service is open and needs no key (OpenAlex takes an optional one).

## Providers

Seven packages. "Capabilities" is what each source's `capabilities()` answers, read from the code
at this commit and printed by the [harness](harness.md)'s `bare` script.

| Package | Factory name | Capabilities | Reads authors by | Called live |
|---|---|---|---|---|
| [`omnischolar/openalex`](https://github.com/glitchr-omnisense/omnischolar-openalex) | `openalex` | author, works, work, search, metrics, citations, abstracts, open_access, affiliations | OpenAlex `A...`, ORCID | 2026-10-26 |
| [`omnischolar/hal`](https://github.com/glitchr-omnisense/omnischolar-hal) | `hal` | author, works, work, search, abstracts, open_access, domains | idHAL, ORCID; works by name too | 2026-10-26 |
| [`omnischolar/crossref`](https://github.com/glitchr-omnisense/omnischolar-crossref) | `crossref` | works, work, search, citations, abstracts | works by ORCID or name | 2026-10-26 |
| [`omnischolar/orcid`](https://github.com/glitchr-omnisense/omnischolar-orcid) | `orcid` | author, works, work, affiliations, abstracts | ORCID | 2026-10-26 |
| [`omnischolar/openlibrary`](https://github.com/glitchr-omnisense/omnischolar-openlibrary) | `openlibrary` | author, works, work, search, covers | Open Library `OL...A`, name | 2026-10-26 |
| [`omnischolar/arxiv`](https://github.com/glitchr-omnisense/omnischolar-arxiv) | `arxiv` | works, work, search, abstracts, open_access, domains | works by name | 2026-10-26 |
| [`omnischolar/inspire`](https://github.com/glitchr-omnisense/omnischolar-inspire) | `inspire` | author, works, work, search, metrics, citations, abstracts, affiliations, domains | INSPIRE record, BAI, ORCID; works by name too | 2026-10-26 |

What each reads, filters and allows: [Sources](sources.md).

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP and its live output.
- [Symfony](symfony.md): the bundle, its configuration tree, its services.
- [Laravel](laravel.md): a service provider written by hand (there is no Laravel bridge).
- [Concepts and models](models.md): the contract, every model, field, constant and enumeration;
  the identifiers.
- Usage: [sources](sources.md) (what each does), [merging](merging.md) (one record per work,
  editions), [export](export.md) (BibTeX, RIS, CSL-JSON).
- [Configuration reference](configuration.md): the registry, every option of every factory.
- [Errors](errors.md): the exceptions, the HTTP statuses behind them, what to catch.
- [Testing](testing.md): testing code that uses the family, the family's own tests.
- [The Docker harness](harness.md): the console, bare PHP, the tests.
- [Writing a provider](providers.md).
- [Verified, and not](verified.md).
