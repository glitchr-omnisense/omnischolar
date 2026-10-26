---
title: The Docker harness
order: 70
---

# The Docker harness

`docker/` runs this package with every `omnischolar/*` source installed - from GitHub (branch
1.x), or from the checkouts beside this one when `OMNISCHOLAR_PLUGINS=../..` is set in
`docker/.env` - and a console that asks the real services.

```sh
cd docker && cp .env.dist .env       # OMNISCHOLAR_MAILTO, an OpenAlex key if you have one
docker compose run --rm omnischolar sources
```

| Command | |
|---|---|
| `sources` | the sources installed and configured, and what each can do |
| `author <source> <author>` | a profile (JSON) |
| `works <source> <author>` | an author's works, every page up to `--max` |
| `work <source> <id>` | one work (JSON) |
| `search <source> [text]` | `--title`, `--author` |
| `merge <source:author>...` | several sources merged; `--no-editions` |
| `bare` | plain PHP: the registry built by hand, an author's works asked of OpenAlex, what PHP loaded |
| `test` | every package's tests |

`works`, `search` and `merge` take `--from`, `--to`, `--type` (repeatable), `--domain`
(repeatable), `--sort` (`newest`, `oldest`, `relevance`, `cited`), `--limit` (per page),
`--max`, and `--format` (`table`, `json`, `bibtex`, `ris`, `csl-json`).

The configured sources are `openalex`, `hal`, `droit` (HAL filtered on `shs.droit`), `crossref`,
`orcid`, `openlibrary`, `arxiv`, `inspire`.

```sh
docker compose run --rm omnischolar works openalex A5108007452 --from 2024 --to 2024
docker compose run --rm omnischolar works openlibrary "Monica Neagoy"
docker compose run --rm omnischolar merge openlibrary:"Monica Neagoy" crossref:"Monica Neagoy"
docker compose run --rm omnischolar merge openalex:A5108007452 hal:"Keitaro Nakatani" --from 2024 --format bibtex
docker compose run --rm omnischolar search droit "responsabilité civile" --from 2024
docker compose run --rm omnischolar author inspire bai:Juan.M.Maldacena.1
docker compose run --rm omnischolar work crossref 10.1039/d4sc04973j
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the source packages installed, asks each what it can do, asks
OpenAlex for the works of A5108007452 in 2024 - the real service, or with `--recorded` the answer
kept in `docker/harness/recorded/` - then lists what PHP loaded and exits 1 if a class of a
framework is among it (`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`,
`HttpFoundation`, a bundle, Doctrine, Twig):

```
$ docker compose run --rm omnischolar bare
Omnischolar in bare PHP: the registry built by hand, no bundle, no container.

  openalex     author works work search metrics citations abstracts open_access affiliations
  hal          author works work search abstracts open_access domains
  droit        author works work search abstracts open_access domains
  crossref     works work search citations abstracts
  orcid        author works work affiliations abstracts
  openlibrary  author works work search covers
  arxiv        works work search abstracts open_access domains
  inspire      author works work search metrics citations abstracts affiliations domains

OpenAlex, the works of A5108007452 (Keitaro Nakatani) in 2024, asked of api.openalex.org:
  2024-10-04  article  Complete kinetic and photochemical characterization of the multi-step photochromic reaction of donor–acceptor Stenhouse adducts - Physical Chemistry Chemical Physics
  2024-09-25  article  Cascade Fluorescence Modulation in Photochromic Microcapsules - ACS Applied Materials & Interfaces
  2024-09-03  article  Tuning the Thermal Stability of Tetra ‐ o– chloroazobenzene Derivatives by Transforming Push‐Pull to Push‐Push Systems - Chemistry - A European Journal
  2024-01-01  article  Acid-sensitive photoswitches: towards catalytic on-demand release of stored light energy - Chemical Science

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, Doctrine, Twig): none
```

`bare --recorded --json` prints the same whole, every class and file loaded, without a call:
`Tests/BareTest.php` runs it in a process of its own and checks the list.

The image is `php:8.4-cli-alpine` with `intl` and Composer; the harness's packages live in the
`harness` volume of the `omnischolar-harness` project.
