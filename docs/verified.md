---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-26 with the harness's image against the workspace (the core and its seven
providers from their checkouts), PHP 8.4, PHPUnit 11.5.57, no network: 102 tests, 505 assertions,
green ([Testing](testing.md#the-familys-own-tests)).

- The core: the registry and the Collector, 31 spellings of identifiers with their checksums, the
  Merger's rules (identifiers in order, preferences, preprints, editions, ORCIDs lent to names),
  the three exports, the Symfony bundle in a booted kernel, plain PHP with no class of a framework
  loaded.
- Each provider on `MockHttpClient` and answers recorded from its real service on 2026-10-04 (the
  OpenAlex, HAL and Crossref examples re-recorded on 2026-11-13; Open Library's books invented in
  the shape of its answers).

## What was called live

On 2026-10-26, from the harness's image, without a `mailto` or a key:

- the first script of [Installation](installation.md#plain-php): an OpenAlex author and their works
  of one year, merged with HAL's, exported as BibTeX;
- one call or two of each provider, pasted in its manual: arXiv (hep-th/9711200, a search in
  hep-th), Crossref (a work by DOI), HAL (a search in `shs.droit`, an author by idHAL), INSPIRE
  (Juan Maldacena's profile and most cited papers), OpenAlex (an author, their works of one year),
  Open Library (a book by ISBN), ORCID (Josiah Carberry's record and works).

On 2026-11-13, from the same image, with a `mailto` and without a key: the same first script for
Alain Aspect (A5046427077) and his 2022 works, `bare`, and the answers the OpenAlex, HAL and
Crossref tests now read (Alain Aspect's profile, works and deposits; Philippe Grangier by idHAL),
with Open Library's ISBN 9780521004220.

Every service is open: every provider has been called live. The counts change from one day to the
next.

## What is not verified

- OpenAlex with an `api_key`; ORCID's sandbox; the polite pools' rates (Crossref's three calls a
  second, as its documentation said in 2026).
- The Laravel service provider of [Laravel](laravel.md): written from the classes.
- The known defect found while writing this manual: `throttle` not read by the OpenAlex, Crossref,
  HAL and ORCID factories ([Configuration](configuration.md#options-every-source-takes)), fixed
  since with a test in each.
- The known defect of OpenAlex's `paratext` (and Open Library's `editions`), read with PHP's
  `(bool)` so that the string `'false'` was true: fixed since, both read with `Config::bool()`,
  with a test in each.

## Responsibility

`omnisense/omnischolar` and its `omnischolar/*` providers are independent, unofficial clients:
none is affiliated with, endorsed by or sponsored by OurResearch (OpenAlex), the CCSD (HAL),
Crossref, ORCID, the Internet Archive (Open Library), arXiv or INSPIRE. Each service keeps its own
terms - rates, attribution, licences of the metadata and of the full texts - named with a link in
each provider's README. Whoever installs and runs these packages is responsible for complying with
those terms and for the use made of what they return.
