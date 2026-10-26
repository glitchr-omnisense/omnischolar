---
title: Testing
order: 60
---

# Testing

Testing an application that lists publications through omnischolar, and what the family's own
tests cover. The core ships no fake source and no conformance suite: its test doubles
(`Tests/StubSource.php`, `Tests/StubSourceFactory.php`) are autoloaded only in its own development
(`autoload-dev`). An application tests its code against a real provider on a `MockHttpClient`, or
with a source of its own; the `Merger` and the `Export` need no network at all.

## A provider on a MockHttpClient

Every factory takes its HTTP client. Given Symfony's `MockHttpClient`, the provider runs whole -
its requests, its mapping, its errors - and nothing leaves the machine:

```php
<?php

use Omnischolar\Crossref\CrossrefSourceFactory;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Export;
use Omnischolar\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The application's code: a DOI as a RIS record to download, or null when Crossref is down. */
final class Cite
{
    public function __construct(private readonly Registry $registry, private readonly Export $export = new Export())
    {
    }

    public function ris(string $doi): ?string
    {
        try {
            $work = $this->registry->get('crossref')->work($doi);
        } catch (UnavailableException) {
            return null;
        }

        return null === $work ? null : $this->export->ris($work);
    }
}

final class CiteTest extends TestCase
{
    private function registry(MockHttpClient $http): Registry
    {
        return new Registry([new CrossrefSourceFactory($http)], ['crossref' => ['factory' => 'crossref', 'options' => ['mailto' => 'test@example.org']]]);
    }

    public function testADoiBecomesARisRecord(): void
    {
        $answer = new JsonMockResponse(['message' => [
            'DOI' => '10.1039/D4SC04973J', 'type' => 'journal-article',
            'title' => ['Acid-sensitive photoswitches'], 'container-title' => ['Chemical Science'],
            'author' => [['given' => 'Keitaro', 'family' => 'Nakatani']],
            'issued' => ['date-parts' => [[2024, 9, 25]]], 'volume' => '15', 'page' => '16034-16039',
        ]]);
        $ris = (new Cite($this->registry(new MockHttpClient($answer))))->ris('https://doi.org/10.1039/D4SC04973J');

        self::assertStringStartsWith("TY  - JOUR\r\nID  - nakatani2024acid\r\nAU  - Nakatani, Keitaro\r\nTI  - Acid-sensitive photoswitches\r\n", (string) $ris);
        self::assertStringContainsString("DO  - 10.1039/d4sc04973j\r\n", (string) $ris);
        self::assertSame('https://api.crossref.org/works/10.1039/d4sc04973j?mailto=test%40example.org', $answer->getRequestUrl());
    }

    public function testNothingWhenCrossrefIsDown(): void
    {
        self::assertNull((new Cite($this->registry(new MockHttpClient(new MockResponse('', ['http_code' => 503])))))->ris('10.1039/d4sc04973j'));
    }
}
```

```
$ vendor/bin/phpunit --testdox tests/
Cite
 ✔ A doi becomes a ris record
 ✔ Nothing when crossref is down

OK (2 tests, 4 assertions)
```

(run on 2026-10-26 in the harness's image, PHPUnit 11.5.57, no network)

- Each provider's `Tests/Fixtures` holds answers recorded from the real service on 2026-10-04 to
  start from (OpenAlex's Keitaro Nakatani, INSPIRE's Juan Maldacena, ORCID's Josiah Carberry...);
  the harness keeps one OpenAlex answer in `docker/harness/recorded/`.
- Give arXiv, INSPIRE and Open Library `throttle: 0` in a test: their default throttle sleeps
  between two calls.
- A 503 tests "down" (`UnavailableException`), a 429 with `Retry-After` the slowed path, a 404
  "not found".

## A source of the application's own

For code that only needs works back, a class extending `HttpSource` (or implementing
`SourceInterface` whole) that answers from memory, given to the registry through a factory - the
core's own `Tests/StubSourceFactory.php` is such a double, to copy rather than depend on. The
`Collector` then runs on it as on any source: its `incomplete` list is tested by making the
source throw an `UnavailableException`.

## Merger and Export

Both are plain objects: build `Work`s by hand and check what comes out.

```php
$a = new Work('Cascade Fluorescence Modulation', WorkType::ARTICLE, year: 2024, identifiers: Identifiers::of(Identifier::doi('10.1021/acsami.4c09186')), source: 'openalex', citations: 5);
$b = new Work('Cascade fluorescence modulation', WorkType::ARTICLE, year: 2024, identifiers: Identifiers::of(Identifier::doi('10.1021/ACSAMI.4C09186'), Identifier::hal('hal-04722222')), source: 'hal');

$merged = (new Merger())->merge([$a], [$b]);
// one Work: sources ['openalex', 'hal'], the DOI and the HAL id, citations 5 (OpenAlex's)
```

## Symfony

In a functional test, the registry is the public service `Omnischolar\Registry`. Replace the HTTP
client the factories receive by configuring `framework.http_client.mock_response_factory` in the
test environment, or configure the source under test with a factory of the application's own
(autoconfigured). Not run in an application for this page.

## The family's own tests

The workspace (`~/Sites/omnischolar`, every package from its checkout) runs 102 tests, 505
assertions ([Verified, and not](verified.md)):

| Test case | What it checks |
|---|---|
| `RegistryTest` (4) | sources built once by name; a required option; the Collector reading every page, merging and naming what it skipped; `Collector::all()` stopping at `$max` |
| `IdentifierTest` (33) | 31 spellings parsed; normalisation (and checksums) making spellings equal; a collection keeping each once |
| `MergerTest` (9) | one work per DOI, each field from its preferred source; configurable preferences; the order of identifiers (a stronger one that differs keeps works apart); a preprint merged into its article; a record bridging two groups; the editions of a book grouped; an ORCID lent to the same name; newest first; the fingerprint |
| `ExportTest` (5) | BibTeX; its escapes and unique keys; a grouped book cited by its newest edition; RIS; CSL-JSON |
| `OmnischolarBundleTest` (1) | a kernel with the bundle: factories registered, sources by argument name, the merger's preferences, the export's option |
| `BareTest` (3) | `bare --recorded --json` in a process of its own: no framework class loaded, works from a recorded answer; loading the bundle does name the framework |
| `OpenAlexSourceTest` (10), `HalSourceTest` (7), `OrcidSourceTest` (7), `CrossrefSourceTest` (6), `OpenLibrarySourceTest` (6), `ArxivSourceTest` (6), `InspireSourceTest` (5) | each provider on `MockHttpClient` and its fixtures (their manuals) |
