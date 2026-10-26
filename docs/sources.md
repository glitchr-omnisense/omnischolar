---
title: Sources
order: 30
---

# Sources

Every source implements `Omnischolar\Source\SourceInterface`:

| Method | Answers |
|---|---|
| `getName()` | its factory's name: `openalex`, `hal`... |
| `capabilities()` | a list of `Capability`: what it can be asked, what its answers hold |
| `author($id)` | an `Author`, or null |
| `works($author, ?Query)` | a `Page` of `Work`, newest first by default; `$page->next` is the next cursor |
| `work($id)` | a `Work`, or null |
| `search(Query)` | a `Page` of `Work` |

## What each one does

| | openalex | hal | crossref | orcid | openlibrary | arxiv | inspire |
|---|---|---|---|---|---|---|---|
| `author()` by | OpenAlex A..., ORCID | idHAL, ORCID | - | ORCID | OL...A, name | - | INSPIRE record, BAI, ORCID |
| `works()` by | OpenAlex A..., ORCID | idHAL, ORCID, name | ORCID, name | ORCID | OL...A, name | name | BAI, record, ORCID, name |
| `work()` by | OpenAlex W..., DOI, PMID, PMCID | HAL id, DOI, arXiv | DOI | `<orcid>/work/<put-code>` | ISBN, OL...M, OL...W | arXiv | INSPIRE record, DOI, arXiv |
| `search()` | text, title, author | text, title, author | text, title, author | - | text, title, author | text, title, author | INSPIRE query, title, author |
| Metrics (h-index...) | yes | - | - | - | - | - | yes (citation summary) |
| Citations per work | yes | - | yes | - | - | - | yes |
| Abstracts | yes (rebuilt) | yes | some (JATS) | some | some | yes | yes |
| Open access, full text | yes | yes (deposited PDF) | - | - | - | yes (PDF) | via arXiv |
| Covers | - | - | - | - | yes | - | - |
| CV (`Affiliation`) | institutions seen on the works | - | - | employments, education, distinctions... | - | - | positions |
| `Query::$domains` | refused | HAL domains (`shs.droit`) | refused | refused | refused | arXiv categories | arXiv categories |
| Paging (cursor) | OpenAlex cursor | Solr cursorMark | offset | offset | page | offset | page |
| Per page, at most | 100 | 10 000 | 1 000 | 100 | 100 | 2 000 | 1 000 |

## What the services do not allow

- **OpenAlex**: no lookup by arXiv id; dates known by the year only come as January 1st; the
  keyless daily budget is limited (a free key raises it tenfold). No search by subject domain here.
- **HAL**: no citation counts; an author's ORCID per name is not aligned in the search answers
  (the work's contributors carry their idHAL, not their ORCID); `works()` by ORCID finds only the
  deposits where the ORCID was linked; by name, the exact form signed.
- **Crossref**: no author profiles; works by name come from a fuzzy author query, filtered on the
  family name and first given name (or initial); cursors cannot sort by date, so pages are offsets
  (10 000 deep at most); no open-access status.
- **ORCID**: only what the researcher made public; no search of works; works come all at once
  (filtered and paged here), their details one page per call.
- **Open Library**: books only; a work's editions cost one more call each; publication dates are
  free text; no abstracts for most editions.
- **arXiv**: no author profiles nor author identifiers: by name only; one call every three seconds;
  the DOI and journal reference only when the authors added them.
- **INSPIRE-HEP**: physics only; search by ORCID goes through the author's BAI; collaboration
  papers carry thousands of authors (all are read).

## Throttling and politeness

| Source | Default `throttle` | Contact |
|---|---|---|
| openalex | none | `mailto` sent as a parameter |
| crossref | none | `mailto` as a parameter and in the User-Agent (polite pool) |
| openlibrary | 0.4 s | `mailto` in the User-Agent |
| arxiv | 3 s | `mailto` in the User-Agent |
| inspire | 0.35 s | `mailto` in the User-Agent |
| hal, orcid | none | User-Agent |

Every factory takes `mailto`, `user_agent` and `base_uri`; `throttle` (seconds between two calls
of one source instance) is read by arXiv, INSPIRE and Open Library ([Configuration](configuration.md)).

> [!WARNING]
> Known defect: the OpenAlex, Crossref, HAL and ORCID factories never pass `throttle` to their
> source (`throttle: 2.5` gives a source that leaves no interval, seen on 2026-10-26), where every
> factory is said to take it. Space their calls in the application, or with an HTTP client that
> throttles.

## Writing a source

A source of another service: [Writing a provider](providers.md).
