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
docker compose run --rm omnischolar works openalex A5046427077 --from 2022 --to 2022
docker compose run --rm omnischolar works openlibrary "Alain Aspect"
docker compose run --rm omnischolar merge openlibrary:"Alain Aspect" crossref:"Alain Aspect"
docker compose run --rm omnischolar merge openalex:A5046427077 hal:"Alain Aspect" --from 2022 --format bibtex
docker compose run --rm omnischolar search droit "responsabilité civile" --from 2024
docker compose run --rm omnischolar author inspire bai:Juan.M.Maldacena.1
docker compose run --rm omnischolar work crossref 10.1140/epjd/s10053-022-00549-6
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the source packages installed, asks each what it can do, asks
OpenAlex for the works of A5046427077 in 2022 - the real service, or with `--recorded` the answer
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

OpenAlex, the works of A5046427077 (Alain Aspect) in 2022, asked of api.openalex.org:
  2022-11-01  article  Bichromatic state-dependent disordered potential for Anderson localization of ultracold atoms - The European Physical Journal D
  2022-08-25  preprint Full counting statistics of interacting lattice gases after an expansion: quantifying many-body coherence from atom correlations - HAL
  2022-08-25  conference Towards the 3D Anderson Transition with Ultracold Atoms in Bichromatic Speckles - HAL
  2022-07-28  preprint Full counting statistics of interacting lattice gases after an expansion: The role of the condensate depletion in the many-body coherence - arXiv (Cornell University)
  2022-04-19  conference Towards the study of 3D Anderson transition by using a two-wavelength speckle disorder - HAL
  2022-02-14  article  Spectral functions and localization-landscape theory in speckle potentials - Physical Review A

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, Doctrine, Twig): none
```

`bare --recorded --json` prints the same whole, every class and file loaded, without a call:
`Tests/BareTest.php` runs it in a process of its own and checks the list.

The image is `php:8.4-cli-alpine` with `intl` and Composer; the harness's packages live in the
`harness` volume of the `omnischolar-harness` project.

## omnisense/contracts and PHPStan

The kernel the family builds on, omnisense/contracts, comes from GitHub (branch 1.x) unless
`OMNISENSE_CONTRACTS` in `docker/.env` names its checkout (`../../../omnisense-contracts` beside
the family's folder): then that checkout runs, and its tests with the family's.
`docker compose run --rm omnischolar phpstan` runs PHPStan at level max on each package that has a
`phpstan.neon`; each `phpstan-baseline.neon` holds what was there when PHPStan came in
(2026-11-10), and may only shrink. The service runs on Docker's default bridge (no network of its
own); its image may be another harness tagged (PHP 8.4 with zip, intl and Composer).
