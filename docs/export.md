---
title: Export
order: 34
---

# Export

```php
$export = new Export();                     // new Export(abstracts: true) to include them
$export->bibtex($works);
$export->ris($work);
$export->cslJson($works);                   // a JSON string
$export->csl($works);                       // the same as arrays
$export->format('bibtex', $works);          // bibtex, ris, csl-json
Export::contentType('ris');                 // application/x-research-info-systems; charset=utf-8
```

Each takes one `Work` or a list. A book whose editions were grouped is cited by its newest
edition.

## Citation keys

The first author's family name, the year and the title's first significant word, without accents:
`chocron2024acid`, `neagoy2012planting`, `cotler2026logarithmic`. Within one export, a key taken
twice gets `a`, `b`...

## BibTeX

| Work type | Entry |
|---|---|
| article (in a venue), review, editorial | `@article` |
| book | `@book` |
| chapter | `@incollection` |
| conference | `@inproceedings` |
| thesis | `@phdthesis` |
| report | `@techreport` |
| preprint, dataset, software, other | `@misc` (arXiv: `eprint`, `archiveprefix`, `primaryclass`) |

Fields: `author`, `editor`, `title` (in double braces, its capitals kept), `journal`,
`booktitle`, `series`, `year`, `month`, `volume`, `number`, `pages` (`--`), `edition`,
`publisher`, `school`, `institution`, `doi`, `isbn`, `issn`, `eprint`, `hal_id`, `url`,
`language`, `keywords`, `abstract`, `note`. Special characters are escaped; accents stay UTF-8
(biblatex, or BibTeX with `inputenc`).

## RIS

`TY` (`JOUR`, `BOOK`, `EDBOOK`, `CHAP`, `CPAPER`, `UNPB`, `THES`, `RPRT`, `DATA`, `COMP`, `GEN`),
`ID`, `AU`/`ED`, `TI`, `T2`, `J2`, `PY`, `DA`, `VL`, `IS`, `SP`, `EP`, `ET`, `PB`, `SN`, `DO`, `UR`,
`L1` (the PDF), `LA`, `KW`, `AB`, `N1`, `ER`; lines end with CRLF.

## CSL-JSON

Items for citeproc-js, citeproc-php or pandoc: `type` (`article-journal`, `book`, `chapter`,
`paper-conference`, `article` for preprints with `genre: Preprint` and the arXiv id as `number`,
`thesis`, `report`, `dataset`, `software`, `review`, `document`), `author` and `editor`
(`family`/`given`, or `literal`), `issued.date-parts`, `container-title`, `publisher`,
`volume`, `issue`, `page`, `edition`, `DOI`, `ISBN`, `ISSN`, `URL`, `language`, `keyword`,
`abstract`, `note`.
