---
title: Concepts and models
order: 20
---

# Concepts and models

## The contract

A **source** is one service, configured. Every source implements the whole
`Omnischolar\Source\SourceInterface`; what it cannot do throws a `NotSupportedException`
(`HttpSource` throws it for each operation a provider does not override):

| Method | Answers |
|---|---|
| `getName(): string` | the name of its factory: `openalex`, `hal`... |
| `capabilities(): list<Capability>` | what it can be asked, and what its answers hold |
| `author(Identifier\|string $author): ?Author` | the researcher's profile, or `null` when unknown |
| `works(Identifier\|string $author, ?Query $query = null): Page` | an author's works, one page at a time, newest first unless the query sorts otherwise |
| `work(Identifier\|string $id): ?Work` | one work, or `null` |
| `search(Query $query): Page` | works matching the query, one page at a time |

An author or a work is named by an `Identifier`, or a string the source reads: one of its own
identifiers (`'A5108007452'`, `'OL7092953A'`, `'keitaro-nakatani'`, `'Juan.M.Maldacena.1'`), a
resolver URL (`https://orcid.org/...`, `https://doi.org/...`), or - for the sources that search by
name (HAL, Crossref, Open Library, arXiv, INSPIRE) - a name. Unknown is `null` or an empty page; a
service down or asking to slow down is an `UnavailableException`; an identifier the source cannot
read is a `NotSupportedException` ([Errors](errors.md)).

`Capability` (string-backed):

| Case | Value | |
|---|---|---|
| `AUTHOR` | `author` | `author()`: a profile |
| `WORKS` | `works` | `works()`: an author's works, page by page |
| `WORK` | `work` | `work()`: one work by an identifier |
| `SEARCH` | `search` | `search()`: works by text, title, author, year, type |
| `METRICS` | `metrics` | an author's counts: works, citations, h-index |
| `CITATIONS` | `citations` | each work's citation count |
| `ABSTRACTS` | `abstracts` | |
| `OPEN_ACCESS` | `open_access` | open-access status and full-text links |
| `COVERS` | `covers` | book covers |
| `AFFILIATIONS` | `affiliations` | a CV: positions, degrees, distinctions |
| `DOMAINS` | `domains` | filtering by subject domain (`Query::$domains`) |

A **factory** (`SourceFactoryInterface`: `getName()`, `create(array $options = [])`) builds a
source; `Omnischolar\Registry` holds them by name ([Configuration](configuration.md#the-registry)).

## Query and Page

`Omnischolar\Source\Query` (`final readonly`) is what `works()` and `search()` are asked:

| Parameter | Type | Default | |
|---|---|---|---|
| `text` | ?string | `null` | free text (INSPIRE: its own query language) |
| `title` | ?string | `null` | words of the title |
| `author` | ?string | `null` | an author's name (`search()`) |
| `from`, `to` | ?int | `null` | years, inclusive; either end may be open |
| `types` | list of `WorkType` | `[]` | only these kinds of works |
| `domains` | list of strings | `[]` | subject codes: a HAL domain (`shs.droit`), an arXiv or INSPIRE category (`hep-th`) |
| `openAccess` | ?bool | `null` | only open-access works (`true`), or only closed ones (`false`) |
| `limit` | int | `25` | works per page; each source caps it |
| `cursor` | ?string | `null` | the `Page::$next` of the page before |
| `sort` | string | `Query::NEWEST` | `newest`, `oldest` (`Query::OLDEST`), `relevance` (`Query::RELEVANCE`), `cited` (`Query::CITED`) |

A source applies the criteria it understands and ignores the others, except `domains`, which a
source without `Capability::DOMAINS` refuses (`NotSupportedException`) rather than answer
unfiltered ([Sources](sources.md#what-each-one-does) lists what each applies).

- `with(array $changes): Query` - a copy: `$query->with(['cursor' => $page->next])`.
- `hasYears(): bool` - `from` or `to` is set.
- `inYears(?int $year): bool` - whether a year falls in the range (an unknown year does only when
  there is no range).

`Omnischolar\Source\Page` is one page, iterable and countable:

| Property | Type | Default | |
|---|---|---|---|
| `works` | list of `Work` | `[]` | |
| `next` | ?string | `null` | the cursor of the next page; `null` on the last one |
| `total` | ?int | `null` | how many works the whole query matches, when the source says |

`isLast(): bool`.

## Models

All are `final readonly` classes in `Omnischolar\Model`, built with named arguments. Dates are
ISO 8601 strings as precise as the source knows them: `2024`, `2024-09`, `2024-09-25`.

### Work

| Property | Type | Default | |
|---|---|---|---|
| `title` | string | required | |
| `type` | `WorkType` | `OTHER` | |
| `authors` | list of `Contributor` | `[]` | in the order printed, editors included (their `role` says so) |
| `year` | ?int | `null` | |
| `date` | ?string | `null` | ISO 8601, as precise as known |
| `subtitle` | ?string | `null` | |
| `venue` | ?Venue | `null` | where it appeared |
| `publisher` | ?string | `null` | |
| `volume`, `issue`, `pages`, `edition` | ?string | `null` | |
| `abstract` | ?string | `null` | plain text |
| `language` | ?string | `null` | |
| `openAccess` | ?bool | `null` | |
| `url` | ?string | `null` | the landing page |
| `pdfUrl` | ?string | `null` | the full text, open access |
| `license` | ?string | `null` | |
| `cover` | ?string | `null` | a cover image's address (Open Library) |
| `citations` | ?int | `null` | the count of the source it came from |
| `keywords` | list of strings | `[]` | |
| `domains` | list of strings | `[]` | HAL domains, arXiv / INSPIRE categories |
| `identifiers` | `Identifiers` | empty | |
| `source` | ?string | `null` | the source of the record |
| `sources` | list of strings | `[]` | after a merge, every source merged into it |
| `editions` | list of `Work` | `[]` | a book's editions, newest first (Merger) |
| `note` | ?string | `null` | arXiv's journal reference or comment |

| Method | |
|---|---|
| `with(array $changes): Work` | a copy with some fields changed: `$work->with(['cover' => $url])` |
| `fullTitle(): string` | `title: subtitle`, unless the title already holds the subtitle |
| `id(Scheme): ?string`, `doi(): ?string`, `isbns(): list<string>` | its identifiers |
| `authorsOnly()`, `editors()`: list of `Contributor` | by role |
| `firstAuthor(): ?Contributor` | the first author, else the first contributor |
| `link(): ?string` | the best link to read it: the PDF, else the landing page, else the DOI's |

`WorkType` (string-backed; `label()` gives the English label):

| Case | Value | `label()` | |
|---|---|---|---|
| `ARTICLE` | `article` | Article | a journal article (a review article is one) |
| `BOOK` | `book` | Book | a book, or a whole edited volume |
| `CHAPTER` | `chapter` | Book chapter | a chapter, an encyclopedia entry |
| `CONFERENCE` | `conference` | Conference paper | a conference paper, a talk, a poster |
| `PREPRINT` | `preprint` | Preprint | a preprint, a working paper |
| `THESIS` | `thesis` | Thesis | a doctoral thesis, a habilitation, a dissertation |
| `REPORT` | `report` | Report | |
| `DATASET` | `dataset` | Dataset | |
| `SOFTWARE` | `software` | Software | |
| `REVIEW` | `review` | Review | a book review, a peer review |
| `EDITORIAL` | `editorial` | Editorial | an editorial, a letter, an erratum, a cover |
| `OTHER` | `other` | Other | |

`WorkType::fromName(?string $name)` reads a Crossref, CSL, OpenAlex or ORCID type name
(`journal-article`, `book-chapter`, `proceedings-article`, `posted-content`, `dissertation`...;
`_` and spaces read as `-`); an unknown name is `OTHER`.

### Contributor

A name on a work.

| Property | Type | Default | |
|---|---|---|---|
| `name` | string | required | as the source prints it |
| `given`, `family` | ?string | `null` | |
| `identifiers` | `Identifiers` | empty | ORCID, idHAL, OpenAlex, BAI... |
| `role` | string | `Contributor::AUTHOR` | `author`, `editor` (`EDITOR`), `translator` (`TRANSLATOR`), or HAL's own code |
| `affiliations` | list of strings | `[]` | the institutions as printed on the work |

- `Contributor::fromName(string $name, ?Identifiers $identifiers = null, string $role = AUTHOR)`
  - `Nakatani, Keitaro` or `Keitaro Nakatani`: the family name from the comma, else the last word.
- `familyName(): string` - the family name, or the last word of the name.
- `sortName(): string` - `Nakatani, Keitaro`, as a bibliography sorts it.
- `orcid(): ?string`.

### Venue

| Property | Type | Default | |
|---|---|---|---|
| `name` | string | required | |
| `type` | string | `Venue::JOURNAL` | `journal`, `conference` (`CONFERENCE`), `book` (`BOOK`), `book_series` (`BOOK_SERIES`), `repository` (`REPOSITORY`), `other` (`OTHER`) |
| `issn` | list of strings | `[]` | print and electronic, normalised (`2041-6520`) |
| `publisher` | ?string | `null` | |
| `abbreviation` | ?string | `null` | |
| `identifiers` | `Identifiers` | empty | OpenAlex's source id |
| `url` | ?string | `null` | |

### Author

A researcher's profile, as one source knows it.

| Property | Type | Default | |
|---|---|---|---|
| `name` | string | required | |
| `given`, `family` | ?string | `null` | |
| `identifiers` | `Identifiers` | empty | |
| `alternativeNames` | list of strings | `[]` | how the name is also spelt |
| `affiliations` | list of `Affiliation` | `[]` | positions, degrees, distinctions; or institutions seen on the works |
| `metrics` | ?Metrics | `null` | |
| `urls` | list of strings | `[]` | their pages |
| `biography` | ?string | `null` | |
| `keywords` | list of strings | `[]` | research topics |
| `country` | ?string | `null` | |
| `source` | ?string | `null` | |

`affiliations(string $kind): list<Affiliation>` (those of one kind), `orcid(): ?string`.

### Affiliation

A line of a CV. Dates as precise as the source knows them (`2001`, `1930-02`).

| Property | Type | Default | |
|---|---|---|---|
| `organization` | string | required | |
| `kind` | string | `Affiliation::EMPLOYMENT` | `employment`, `education` (`EDUCATION`), `qualification` (`QUALIFICATION`), `invited-position` (`INVITED_POSITION`), `distinction` (`DISTINCTION`), `membership` (`MEMBERSHIP`), `service` (`SERVICE`), `observed` (`OBSERVED`: an institution seen on the works, without a role nor dates the source vouches for) |
| `role`, `department` | ?string | `null` | |
| `start`, `end` | ?string | `null` | |
| `city`, `country` | ?string | `null` | |
| `ror` | ?string | `null` | the organisation's ROR |
| `url` | ?string | `null` | |
| `years` | list of ints | `[]` | for `OBSERVED`: the years the institution appears on the works |
| `current` | ?bool | `null` | |

`isCurrent(): bool` - `current` when the source says, else a start and no end.

### Metrics

An author's counts, as one source computes them: they differ from one source to the next.

| Property | Type | Default |
|---|---|---|
| `works`, `citations`, `hIndex`, `i10Index` | ?int | `null` |
| `twoYearMeanCitedness` | ?float | `null` |
| `byYear` | `array<int, array{works?: ?int, citations?: ?int}>` | `[]`, oldest first |
| `source` | ?string | `null` |

## Identifiers

`Omnischolar\Model\Identifier` normalises an identifier so that two spellings compare equal, and
checks it on its shape (and checksum) without the network:

```php
Identifier::parse('https://doi.org/10.1039/D4SC04973J');   // doi:10.1039/d4sc04973j
Identifier::parse('1-4129-9660-0');                         // isbn:9781412996600
Identifier::parse('arXiv:hep-th/9711200v3');                // arxiv:hep-th/9711200
Identifier::parse('bai:Juan.M.Maldacena.1');                // inspire_bai:Juan.M.Maldacena.1
Identifier::of('idhal', 'keitaro-nakatani');
Identifier::tryOf(Scheme::ORCID, '0000-0002-1825-0098');    // null: bad checksum
```

| Scheme (`Scheme::`) | Value | `label()` | Normalised as | `url()` |
|---|---|---|---|---|
| `DOI` | `doi` | DOI | lower case, without `https://doi.org/` or `doi:` | `https://doi.org/<doi>` |
| `ISBN` | `isbn` | ISBN | ISBN-13, checksum checked; an ISBN-10 converted (`isbn10()` gives it back for a 978 ISBN) | - |
| `ISSN` | `issn` | ISSN | `NNNN-NNNC`, checksum checked | - |
| `ARXIV` | `arxiv` | arXiv | without its version: `2401.12345`, `hep-th/9711200` | `https://arxiv.org/abs/<id>` |
| `HAL` | `hal` | HAL | a deposit without its version: `hal-04772417` | `https://hal.science/<id>` |
| `IDHAL` | `idhal` | idHAL | a HAL author, lower case: `keitaro-nakatani` | `https://cv.hal.science/<idhal>` |
| `ORCID` | `orcid` | ORCID | `0000-0000-0000-000X`, checksum checked | `https://orcid.org/<orcid>` |
| `OPENALEX` | `openalex` | OpenAlex | `W...` (work), `A...` (author), `S...` (source), `I...` (institution)... upper case | `https://openalex.org/<id>` |
| `INSPIRE` | `inspire` | INSPIRE | a record number | - |
| `INSPIRE_BAI` | `inspire_bai` | INSPIRE BAI | an author's BAI as written: `Juan.M.Maldacena.1` | INSPIRE's author search |
| `OPENLIBRARY` | `openlibrary` | Open Library | `OL...W` (work), `OL...M` (edition), `OL...A` (author) | the work's, edition's or author's page |
| `PMID` | `pmid` | PubMed | the number | `https://pubmed.ncbi.nlm.nih.gov/<id>/` |
| `PMCID` | `pmcid` | PubMed Central | `PMC...` | `https://www.ncbi.nlm.nih.gov/pmc/articles/<id>/` |

`parse()` reads `scheme:value` (also `ol:`, `bai:`, `isbn10:`, `isbn13:`), the resolver URLs of
each service (doi.org, orcid.org, arxiv.org, openalex.org, inspirehep.net, openlibrary.org,
pubmed, hal.science and archives-ouvertes.fr), and the values whose shape tells their scheme (a
`10.` DOI, an ORCID, `W`/`A` followed by six digits or more, `OL...`, `PMC...`, an arXiv id, a HAL
id, an ISBN, an ISSN); a bare number (an INSPIRE record? a PubMed id?) needs its scheme.

| Method | |
|---|---|
| `Identifier::of(Scheme\|string, string)` | normalised, or `InvalidArgumentException` (`"x" is not a valid ORCID.`); PHP's `ValueError` for an unknown scheme name |
| `Identifier::tryOf(Scheme\|string\|null, ?string)` | the same, `null` instead |
| `doi()`, `isbn()`, `arxiv()`, `hal()`, `orcid()`, `openalex()`, `inspire()`, `openlibrary()` | `of()` with that scheme |
| `key(): string` | `scheme:value`: what the Merger and the collections key on; also `__toString()` |
| `equals(Identifier): bool`, `url(): ?string`, `isbn10(): ?string` | |

`Identifiers` keeps each identifier once, in the order learnt (`null`s skipped): `get(Scheme)`,
`value(Scheme)`, `all(?Scheme)`, `values(Scheme)`, `has(Scheme|Identifier)`, `with(...)`,
`merge(Identifiers)`, `keys()`, `toArray()` / `Identifiers::fromArray()` for storage; iterable and
countable.
