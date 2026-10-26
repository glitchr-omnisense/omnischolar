---
title: Merging
order: 32
---

# Merging

```php
$merger = new Merger();                                   // the default preferences
$works = $merger->merge($openalexWorks, $halWorks, $crossrefPage);   // lists or pages, newest first
$merger->same($a, $b);                                    // would they be one record?
```

## When two records are one work

In this order:

1. **the same DOI**;
2. **the same ISBN** - for books, theses, reports and other works only: a chapter or a conference
   paper carries the ISBN of its book;
3. **the same arXiv id**;
4. **the same HAL id**;
5. failing all identifiers, **the same title in the same year** - compared in lower case,
   without accents nor punctuation, with and without its subtitle (when the title alone is long
   enough). A preprint's title also matches the year after its own, when it is often published.

A stronger identifier that differs keeps two records apart:

- matched by ISBN, arXiv or HAL, two records that both carry a DOI and share none are two works;
- matched by title, two records that both carry a DOI, an ISBN, an arXiv id or a HAL id, and
  share none of them, are two works (two Zenodo versions of one dataset, two editions of a book).

A record that matches two groups joins them (an OpenAlex record with the HAL id of one and the
arXiv id of the other), unless the two groups carry different DOIs or arXiv ids.

## Each field from its preferred source

For each field, the sources are ranked; the first record of the group that has a value gives it.
`"default"` ranks the fields not named; a source not listed comes after those listed.

| Field | Default order |
|---|---|
| default | crossref, openalex, hal, inspire, arxiv, orcid, openlibrary |
| `abstract` | hal, openalex, arxiv, inspire, crossref |
| `citations` | openalex, inspire, crossref |
| `cover` | openlibrary |
| `pdfUrl` | hal, arxiv, openalex |
| `license` | openalex, crossref, hal |
| `date` | crossref, hal, inspire, arxiv, orcid, openlibrary, openalex (OpenAlex dates a year-only work on January 1st) |

```php
new Merger([
    'default' => ['hal', 'crossref', 'openalex'],   // a French lab: HAL first
    'abstract' => ['hal'],
    'citations' => ['inspire', 'openalex'],
]);
```

Some fields follow their own rule:

- `title` and `subtitle` come together, from the record preferred for `title`;
- `type`: the first that is not `other` (a published article over its preprint, by the default order);
- `year`, then `date` from the same record, or a date of the same year;
- `authors`: the preferred list, each name completed with the identifiers (ORCID, idHAL...) the
  other records give the same family name - when that name occurs once in each list;
- `identifiers`, `keywords`, `domains`, `sources` are united; `openAccess` is true when one
  record says so.

## Editions

After merging, books with the same title (without its subtitle) and the same first author's
family name, and no ISBN in common, are the editions of one book. The group is one record - the
newest edition's, its missing fields (subtitle, abstract, cover, publisher, language, URL,
citations) taken from the others - with every ISBN of every edition, and the editions themselves
in `$editions`, newest first. `new Merger([], false)` leaves them apart.

Merging a merged list again (a weekly sync) takes the groups apart and groups them the same way.
The exports cite a group by its newest edition.

Open Library's two volumes of the *Méthode de Singapour CE1* stay two books: their titles differ
by their volume number.
