---
title: Writing a provider
order: 80
---

# Writing a provider

A provider is a package of its own, `omnischolar/<service>`, requiring `omnisense/omnischolar` and
`symfony/http-client`, with:

- a **factory** extending `Omnischolar\Source\SourceFactory`: `populate(Config)` sets its name
  (`omnischolar.factory_name`), its required options (`omnischolar.required_options`) and the
  defaults of the others; `build(Config)` returns the source on `$this->http` (the application's
  client, or `HttpClient::create()` when `null`). `SourceFactory::userAgent($c)` gives the
  `User-Agent` to send, with the `mailto`; pass `throttle` on when the service asks for an
  interval;
- a **source** extending `Omnischolar\Source\HttpSource`, overriding the operations its service
  does (`author()`, `works()`, `work()`, `search()`) - the others throw `NotSupportedException`
  already;
- **`capabilities()`** true to the code: the operations it overrides and what its answers hold
  (`METRICS`, `CITATIONS`, `ABSTRACTS`, `OPEN_ACCESS`, `COVERS`, `AFFILIATIONS`, `DOMAINS`);
- **tests** on `MockHttpClient`, with the service's answers in `Tests/Fixtures`.

## What HttpSource gives

| Member | |
|---|---|
| `__construct(HttpClientInterface $http, string $baseUri, array $headers = [], float $throttle = 0.0)` | headers sent with every call; seconds between two calls |
| `getJson(string $path, array $query = [], array $headers = []): ?array` | a GET of JSON; `null` on 404 or 410 |
| `get(string $path, array $query = [], array $headers = []): ?string` | a GET of text (Atom, XML) |
| `identify(Identifier\|string $id, Scheme ...$schemes): ?Identifier` | the identifier when it is of one of those schemes (a string whose shape tells no scheme is tried against each in turn: an INSPIRE record number, an idHAL); `null` otherwise - a name, for the sources that search by name |
| `text(?string $text): ?string` | tags stripped (JATS, HTML), entities decoded, white space collapsed |
| `date($year, $month = null, $day = null): ?string` | `2024`, `2024-09`, `2024-09-25` from date parts; `null` without a numeric year |

A path starting with `http` is used as it is, else appended to `$baseUri`. A query value that is a
list repeats its key (Solr's `fq=...&fq=...`), `null` and `''` are left out, booleans are
`true`/`false`. The errors are mapped once, for every source:
[Errors](errors.md#how-http-becomes-an-answer-or-an-exception).

## A whole provider

A source of a bibliography service that reads a work by DOI and searches titles:

```php
<?php

namespace Acme\Dblp;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

final class DblpSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'dblp',
            'omnischolar.required_options' => [],
            'throttle' => 1.0,
            'base_uri' => 'https://dblp.example/api/',
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new DblpSource($this->http ?? HttpClient::create(), (string) $c->get('base_uri'), ['User-Agent' => self::userAgent($c)], (float) $c->get('throttle', 1.0));
    }
}
```

```php
<?php

namespace Acme\Dblp;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;

final class DblpSource extends HttpSource
{
    public function getName(): string
    {
        return 'dblp';
    }

    public function capabilities(): array
    {
        return [Capability::WORK, Capability::SEARCH];
    }

    public function work(Identifier|string $id): ?Work
    {
        $doi = self::identify($id, Scheme::DOI) ?? throw NotSupportedException::identifier('dblp', (string) $id);
        $data = $this->getJson('publ/doi/'.$doi->value);       // 404: null

        return null === $data ? null : $this->toWork($data);
    }

    public function search(Query $query): Page
    {
        if ($query->domains) {
            throw NotSupportedException::operation('dblp', 'filter by domain');
        }
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(1000, $query->limit));
        $data = $this->getJson('publ/search', ['q' => trim(($query->title ?? '').' '.($query->text ?? '')), 'h' => $limit, 'f' => $offset]) ?? [];
        $works = array_map($this->toWork(...), $data['hits'] ?? []);
        $works = array_values(array_filter($works, static fn (Work $w) => $query->inYears($w->year)));
        $total = $data['total'] ?? null;

        return new Page($works, null !== $total && $offset + $limit < $total ? (string) ($offset + $limit) : null, $total);
    }

    private function toWork(array $d): Work
    {
        return new Work(
            title: self::text($d['title'] ?? null) ?? '',
            type: WorkType::fromName($d['type'] ?? null),
            authors: array_map(static fn (string $name) => Contributor::fromName($name), $d['authors'] ?? []),
            year: isset($d['year']) ? (int) $d['year'] : null,
            date: self::date($d['year'] ?? null),
            venue: isset($d['venue']) ? new Venue((string) $d['venue'], Venue::CONFERENCE) : null,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::DOI, $d['doi'] ?? null)),
            source: 'dblp',
        );
    }
}
```

Used on a `MockHttpClient` that answers a search and a DOI:

```php
$registry = new Registry([new DblpSourceFactory($http)], ['dblp' => ['factory' => 'dblp', 'options' => ['throttle' => 0]]]);
$dblp = $registry->get('dblp');

foreach ($dblp->search(new Query(title: 'attention', from: 2017)) as $work) {
    echo $work->year, ' ', $work->type->value, ' ', $work->title, ' - ', $work->venue->name, ' ', $work->doi(), "\n";
}
echo (new Export())->bibtex($dblp->work('https://doi.org/10.5555/3295222.3295349'));
$dblp->author('Ashish Vaswani');           // NotSupportedException
```

```
2017 conference Attention Is All You Need - NeurIPS 10.5555/3295222.3295349
@inproceedings{vaswani2017attention,
  author = {Vaswani, Ashish},
  title = {{Attention Is All You Need}},
  booktitle = {NeurIPS},
  year = {2017},
  doi = {10.5555/3295222.3295349}
}
The "dblp" source does not know authors.
```

(run on 2026-10-26 in the harness's image, no network)

## Rules the existing providers keep

- Unknown is `null` or an empty page; down or slowed is never `null`.
- `Query::$domains` is refused (`NotSupportedException::operation(..., 'filter by domain')`) by a
  source without `Capability::DOMAINS`; the other criteria a service cannot apply are applied here
  when the answer allows it (ORCID filters years and types itself) or left out.
- A type the service does not have gives an empty page rather than an unfiltered one (arXiv, Open
  Library, INSPIRE).
- Each work's `source` is the factory's name: the Merger ranks the sources by it.
- Names through `Contributor::fromName()`, dates through `date()`, texts through `text()`; the
  identifiers through `Identifier::tryOf()`, so that a malformed one is left out.

## Registering it

- Plain PHP: give the registry an instance.
- Symfony: a service implementing `SourceFactoryInterface` is autoconfigured with the tag
  `omnischolar.source_factory`; the bundle gives the HTTP client to its own seven factories only.
- The Merger's default order (`Merger::DEFAULT_ORDER`) does not know the new source: it comes after
  those listed unless the application's preferences name it.
- The harness: an entry in `docker/harness/plugins.php` and, for the console, in
  `docker/harness/config/sources.php`.
