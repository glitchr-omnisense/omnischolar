<?php

namespace Omnischolar;

use Omnischolar\Model\Contributor;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;

/**
 * Works as a reference manager imports them: BibTeX, RIS, CSL-JSON.
 *
 *   $export->bibtex($works);            // @article{lecoutre2022bichromatic, ...}
 *   $export->ris($work);                // TY  - JOUR ...
 *   $export->cslJson($works);           // [{"type": "article-journal", ...}]
 *   $export->format('bibtex', $works);  // by name: bibtex, ris, csl-json
 *
 * Citation keys are the first author's family name, the year and the
 * title's first word ("lecoutre2022bichromatic"), made unique within one export.
 * A book whose editions the Merger grouped is cited by its newest edition.
 */
final class Export
{
    public const BIBTEX = 'bibtex';
    public const RIS = 'ris';
    public const CSL_JSON = 'csl-json';

    public const FORMATS = [self::BIBTEX, self::RIS, self::CSL_JSON];

    /** The words a citation key skips. */
    private const STOP_WORDS = ['a', 'an', 'the', 'of', 'on', 'in', 'and', 'for', 'to', 'with', 'from', 'by', 'at', 'as', 'is', 'are', 'le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'et', 'en', 'au', 'aux', 'pour', 'sur', 'der', 'die', 'das', 'ein', 'eine', 'und', 'zur', 'zum', 'von'];

    public function __construct(private readonly bool $abstracts = false)
    {
    }

    /** @param Work|iterable<Work> $works */
    public function format(string $format, Work|iterable $works): string
    {
        return match ($format) {
            self::BIBTEX => $this->bibtex($works),
            self::RIS => $this->ris($works),
            self::CSL_JSON, 'csl', 'json' => $this->cslJson($works),
            default => throw new \InvalidArgumentException(\sprintf('No "%s" export; there are %s.', $format, implode(', ', self::FORMATS))),
        };
    }

    /** The media type a format is served with. */
    public static function contentType(string $format): string
    {
        return match ($format) {
            self::BIBTEX => 'application/x-bibtex; charset=utf-8',
            self::RIS => 'application/x-research-info-systems; charset=utf-8',
            default => 'application/vnd.citationstyles.csl+json; charset=utf-8',
        };
    }

    /** @param Work|iterable<Work> $works */
    public function bibtex(Work|iterable $works): string
    {
        $entries = [];
        foreach ($this->keyed($works) as $key => $work) {
            $type = match ($work->type) {
                WorkType::ARTICLE, WorkType::REVIEW, WorkType::EDITORIAL => null !== $work->venue ? 'article' : 'misc',
                WorkType::BOOK => 'book',
                WorkType::CHAPTER => 'incollection',
                WorkType::CONFERENCE => 'inproceedings',
                WorkType::THESIS => 'phdthesis',
                WorkType::REPORT => 'techreport',
                default => 'misc',
            };
            $container = $work->venue?->name;
            $arxiv = $work->id(Scheme::ARXIV);
            $fields = [
                'author' => $this->bibNames($work->authorsOnly() ?: (WorkType::BOOK === $work->type ? [] : $work->authors)),
                'editor' => $this->bibNames($work->editors()),
                'title' => $work->fullTitle(),
                'journal' => 'article' === $type ? $container : null,
                'booktitle' => \in_array($type, ['incollection', 'inproceedings'], true) ? $container : null,
                'series' => 'book' === $type && null !== $container && $container !== $work->publisher ? $container : null,
                'howpublished' => 'misc' === $type && null === $arxiv ? $container : null,
                'year' => null !== $work->year ? (string) $work->year : null,
                'month' => null !== $work->date && \strlen($work->date) >= 7 ? strtolower(date('M', (int) mktime(0, 0, 0, (int) substr($work->date, 5, 2), 1))) : null,
                'volume' => $work->volume,
                'number' => $work->issue,
                'pages' => null !== $work->pages ? (string) preg_replace('~\s*[-–—]+\s*~u', '--', $work->pages) : null,
                'edition' => $work->edition,
                'publisher' => \in_array($type, ['book', 'incollection', 'inproceedings', 'misc'], true) ? ($work->publisher ?? $work->venue?->publisher) : null,
                'school' => 'phdthesis' === $type ? $work->publisher : null,
                'institution' => 'techreport' === $type ? $work->publisher : null,
                'doi' => $work->doi(),
                'isbn' => $work->isbns()[0] ?? null,
                'issn' => $work->venue?->issn[0] ?? null,
                'eprint' => $arxiv,
                'archiveprefix' => null !== $arxiv ? 'arXiv' : null,
                'primaryclass' => null !== $arxiv ? ($work->domains[0] ?? null) : null,
                'hal_id' => $work->id(Scheme::HAL),
                'url' => $work->url ?? $work->pdfUrl,
                'language' => $work->language,
                'keywords' => $work->keywords ? implode(', ', $work->keywords) : null,
                'abstract' => $this->abstracts ? $work->abstract : null,
                'note' => $work->note,
            ];
            $lines = [];
            foreach ($fields as $name => $value) {
                if (null !== $value && '' !== $value) {
                    $lines[] = \sprintf('  %s = {%s}', $name, \in_array($name, ['url', 'doi', 'eprint', 'hal_id'], true) ? $this->bibUrl($value) : $this->bibText($value, 'title' === $name));
                }
            }
            $entries[] = '@'.$type.'{'.$key.",\n".implode(",\n", $lines)."\n}\n";
        }

        return implode("\n", $entries);
    }

    /** @param Work|iterable<Work> $works */
    public function ris(Work|iterable $works): string
    {
        $records = [];
        foreach ($this->keyed($works) as $key => $work) {
            $lines = [['TY', match ($work->type) {
                WorkType::ARTICLE, WorkType::REVIEW, WorkType::EDITORIAL => 'JOUR',
                WorkType::BOOK => $work->editors() && !$work->authorsOnly() ? 'EDBOOK' : 'BOOK',
                WorkType::CHAPTER => 'CHAP',
                WorkType::CONFERENCE => 'CPAPER',
                WorkType::PREPRINT => 'UNPB',
                WorkType::THESIS => 'THES',
                WorkType::REPORT => 'RPRT',
                WorkType::DATASET => 'DATA',
                WorkType::SOFTWARE => 'COMP',
                WorkType::OTHER => 'GEN',
            }], ['ID', $key]];
            foreach ($work->authors as $contributor) {
                $lines[] = [Contributor::EDITOR === $contributor->role ? 'ED' : 'AU', $contributor->sortName()];
            }
            $lines[] = ['TI', $work->fullTitle()];
            $lines[] = ['T2', $work->venue?->name];
            $lines[] = ['J2', $work->venue?->abbreviation];
            $lines[] = ['PY', null !== $work->year ? (string) $work->year : null];
            $lines[] = ['DA', null !== $work->date ? str_replace('-', '/', $work->date) : null];
            $lines[] = ['VL', $work->volume];
            $lines[] = ['IS', $work->issue];
            if (null !== $work->pages) {
                $pages = preg_split('~\s*[-–—]+\s*~u', $work->pages, 2) ?: [$work->pages];
                $lines[] = ['SP', $pages[0]];
                $lines[] = ['EP', $pages[1] ?? null];
            }
            $lines[] = ['ET', $work->edition];
            $lines[] = ['PB', $work->publisher ?? $work->venue?->publisher];
            foreach ([...$work->isbns(), ...($work->venue->issn ?? [])] as $number) {
                $lines[] = ['SN', $number];
            }
            $lines[] = ['DO', $work->doi()];
            $lines[] = ['UR', $work->url ?? $work->identifiers->get(Scheme::DOI)?->url()];
            $lines[] = ['L1', $work->pdfUrl];
            $lines[] = ['LA', $work->language];
            foreach ($work->keywords as $keyword) {
                $lines[] = ['KW', $keyword];
            }
            $lines[] = ['AB', $this->abstracts ? $work->abstract : null];
            $lines[] = ['N1', $work->note];
            $lines[] = ['ER', ''];
            $records[] = implode("\r\n", array_map(
                static fn (array $l) => rtrim(\sprintf('%s  - %s', $l[0], str_replace(["\r", "\n"], ' ', (string) $l[1]))),
                array_filter($lines, static fn (array $l) => null !== $l[1] && ('' !== $l[1] || 'ER' === $l[0])),
            ));
        }

        return $records ? implode("\r\n\r\n", $records)."\r\n" : '';
    }

    /** @param Work|iterable<Work> $works */
    public function cslJson(Work|iterable $works): string
    {
        return json_encode($this->csl($works), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * CSL-JSON items, as arrays (citeproc-js, citeproc-php, pandoc read them).
     *
     * @param Work|iterable<Work> $works
     *
     * @return list<array<string, mixed>>
     */
    public function csl(Work|iterable $works): array
    {
        $items = [];
        foreach ($this->keyed($works) as $key => $work) {
            $names = static fn (array $contributors): array => array_map(static fn (Contributor $c) => null !== $c->family && '' !== $c->family
                ? array_filter(['family' => $c->family, 'given' => $c->given], static fn ($v) => null !== $v && '' !== $v)
                : ['literal' => $c->name], $contributors);
            $date = null;
            if (null !== $work->date) {
                $date = ['date-parts' => [array_map('intval', explode('-', $work->date))]];
            } elseif (null !== $work->year) {
                $date = ['date-parts' => [[$work->year]]];
            }
            $item = [
                'id' => $key,
                'type' => match ($work->type) {
                    WorkType::ARTICLE, WorkType::EDITORIAL => 'article-journal',
                    WorkType::BOOK => 'book',
                    WorkType::CHAPTER => 'chapter',
                    WorkType::CONFERENCE => 'paper-conference',
                    WorkType::PREPRINT => 'article',
                    WorkType::THESIS => 'thesis',
                    WorkType::REPORT => 'report',
                    WorkType::DATASET => 'dataset',
                    WorkType::SOFTWARE => 'software',
                    WorkType::REVIEW => 'review',
                    WorkType::OTHER => 'document',
                },
                'title' => $work->fullTitle(),
                'author' => $names($work->authorsOnly()),
                'editor' => $names($work->editors()),
                'container-title' => $work->venue?->name,
                'container-title-short' => $work->venue?->abbreviation,
                'publisher' => $work->publisher ?? $work->venue?->publisher,
                'issued' => $date,
                'volume' => $work->volume,
                'issue' => $work->issue,
                'page' => $work->pages,
                'edition' => $work->edition,
                'DOI' => $work->doi(),
                'ISBN' => $work->isbns()[0] ?? null,
                'ISSN' => $work->venue?->issn[0] ?? null,
                'URL' => $work->url ?? $work->identifiers->get(Scheme::DOI)?->url(),
                'language' => $work->language,
                'keyword' => $work->keywords ? implode(', ', $work->keywords) : null,
                'abstract' => $this->abstracts ? $work->abstract : null,
                'note' => $work->note,
                'number' => WorkType::PREPRINT === $work->type ? $work->id(Scheme::ARXIV) : null,
                'genre' => WorkType::PREPRINT === $work->type ? 'Preprint' : null,
            ];
            $items[] = array_filter($item, static fn ($v) => null !== $v && '' !== $v && [] !== $v);
        }

        return $items;
    }

    /**
     * The works by their citation key, unique within the export.
     *
     * @param Work|iterable<Work> $works
     *
     * @return array<string, Work>
     */
    private function keyed(Work|iterable $works): array
    {
        $keyed = [];
        foreach ($works instanceof Work ? [$works] : $works as $work) {
            // A book whose editions were grouped is cited by its newest edition, as that edition describes itself.
            $work = $work->editions[0] ?? $work;
            $base = $this->citationKey($work);
            $key = $base;
            for ($suffix = 'a'; isset($keyed[$key]); ++$suffix) {
                $key = $base.$suffix;
            }
            $keyed[$key] = $work;
        }

        return $keyed;
    }

    /** "lecoutre2022bichromatic": the first author's family name, the year, the title's first significant word. */
    public function citationKey(Work $work): string
    {
        $family = Merger::fingerprint($work->firstAuthor()?->familyName() ?? 'anonymous');
        $word = 'untitled';
        foreach (explode(' ', Merger::fingerprint($work->title)) as $candidate) {
            if ('' !== $candidate && !\in_array($candidate, self::STOP_WORDS, true)) {
                $word = $candidate;
                break;
            }
        }

        return str_replace(' ', '', $family).($work->year ?? 'nd').$word;
    }

    /** @param list<Contributor> $contributors */
    private function bibNames(array $contributors): ?string
    {
        return $contributors ? implode(' and ', array_map(static fn (Contributor $c) => null !== $c->family && '' !== $c->family ? $c->sortName() : '{'.$c->name.'}', $contributors)) : null;
    }

    /** A value with BibTeX's special characters escaped; a title's capitals kept in braces. */
    private function bibText(string $value, bool $keepCase = false): string
    {
        $value = strtr(trim((string) preg_replace('~\s+~u', ' ', $value)), ['\\' => '\textbackslash{}', '{' => '\{', '}' => '\}', '&' => '\&', '%' => '\%', '$' => '\$', '#' => '\#', '_' => '\_', '~' => '\textasciitilde{}', '^' => '\textasciicircum{}']);

        return $keepCase ? '{'.$value.'}' : $value;
    }

    /** A URL or an identifier: only its braces and percent signs matter. */
    private function bibUrl(string $value): string
    {
        return strtr($value, ['{' => '%7B', '}' => '%7D']);
    }
}
