<?php

namespace Omnischolar;

use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Page;

/**
 * The works several sources list, deduplicated: one record per work, each
 * field taken from the source preferred for it, and the editions of a book
 * grouped under one record.
 *
 * Two records are the same work when they share, in this order: a DOI, an
 * ISBN (books, theses and reports only: a chapter carries its book's), an
 * arXiv id, a HAL id; failing all, the same title in the same year. A
 * stronger identifier that differs keeps them apart: two different DOIs
 * are two works, whatever their titles. A preprint's title also matches
 * the year after its own: the year it is often published. Two books with the same title and
 * first author but no ISBN in common are two editions of one book: they
 * are grouped, the newest first, in the group's $editions.
 *
 * Preferences name, for a field, the sources in the order they are
 * trusted; "default" orders the fields not named. A source not listed comes
 * after those listed, in the order the lists were given:
 *
 *   new Merger(['default' => ['crossref', 'openalex', 'hal'], 'abstract' => ['hal', 'openalex'], 'cover' => ['openlibrary']]);
 *
 * Identifiers, keywords, domains and sources are united; a work is open
 * access when one source says so.
 */
final class Merger
{
    public const DEFAULT_ORDER = ['crossref', 'openalex', 'hal', 'inspire', 'arxiv', 'orcid', 'openlibrary'];

    public const DEFAULT_PREFERENCES = [
        'abstract' => ['hal', 'openalex', 'arxiv', 'inspire', 'crossref'],
        'citations' => ['openalex', 'inspire', 'crossref'],
        'cover' => ['openlibrary'],
        'pdfUrl' => ['hal', 'arxiv', 'openalex'],
        'license' => ['openalex', 'crossref', 'hal'],
        // OpenAlex dates a work known by its year alone on January 1st.
        'date' => ['crossref', 'hal', 'inspire', 'arxiv', 'orcid', 'openlibrary', 'openalex'],
    ];

    /** The fields taken whole from the preferred source that has them. */
    private const FIELDS = ['title', 'subtitle', 'venue', 'publisher', 'volume', 'issue', 'pages', 'edition', 'abstract', 'language', 'url', 'pdfUrl', 'license', 'cover', 'citations', 'note'];

    /** The identifiers that make two records one, strongest first. */
    private const SCHEMES = [Scheme::DOI, Scheme::ISBN, Scheme::ARXIV, Scheme::HAL];

    /** @var array<string, list<string>> */
    private readonly array $preferences;

    /**
     * @param array<string, list<string>> $preferences field => sources, "default" for the others
     * @param bool                        $editions    group the editions of a book
     */
    public function __construct(array $preferences = [], private readonly bool $editions = true)
    {
        $this->preferences = $preferences + self::DEFAULT_PREFERENCES + ['default' => self::DEFAULT_ORDER];
    }

    /**
     * @param iterable<Work>|Page ...$lists each source's works
     *
     * @return list<Work> newest first
     */
    public function merge(iterable ...$lists): array
    {
        $works = [];
        foreach ($lists as $list) {
            foreach ($list as $work) {
                // A group from an earlier merge is taken apart: its editions are grouped again.
                array_push($works, ...($work->editions ?: [$work]));
            }
        }

        $parent = [];
        $members = [];
        $index = [];
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };

        foreach ($works as $i => $work) {
            $parent[$i] = $i;
            $members[$i] = [$i];
            $target = null;
            foreach ($this->identifierKeys($work) as $level => $keys) {
                foreach ($keys as $key) {
                    if (!isset($index[$key])) {
                        continue;
                    }
                    $root = $find($index[$key]);
                    if ($root === $target || $root === $i) {
                        continue;
                    }
                    // Matched below the DOI: a different DOI on both sides still keeps them apart.
                    if (0 !== $level && $this->conflicts($this->collect($members[$root], $works), [$work], [Scheme::DOI])) {
                        continue;
                    }
                    if (null === $target) {
                        $target = $root;
                    } elseif (!$this->conflicts($this->collect($members[$root], $works), $this->collect($members[$target], $works), [Scheme::DOI, Scheme::ARXIV])) {
                        $parent[$root] = $target;
                        array_push($members[$target], ...$members[$root]);
                        unset($members[$root]);
                    }
                }
            }
            if (null === $target) {
                foreach ($this->titleKeys($work) as $key) {
                    if (isset($index[$key]) && !$this->conflicts($this->collect($members[$root = $find($index[$key])], $works), [$work], [Scheme::DOI, Scheme::ISBN, Scheme::ARXIV, Scheme::HAL])) {
                        $target = $root;
                        break;
                    }
                }
            }
            if (null !== $target) {
                $parent[$i] = $target;
                $members[$target][] = $i;
                unset($members[$i]);
            }
            $root = $find($i);
            foreach ([...array_merge(...array_values($this->identifierKeys($work))), ...$this->titleKeys($work)] as $key) {
                $index[$key] ??= $root;
            }
        }

        $merged = [];
        foreach ($members as $cluster) {
            $merged[] = $this->combine($this->collect($cluster, $works));
        }
        if ($this->editions) {
            $merged = $this->groupEditions($merged);
        }

        return self::newestFirst($merged);
    }

    /** Whether the Merger takes two records for the same work (editions aside). */
    public function same(Work $a, Work $b): bool
    {
        return 1 === \count($this->editionless()->merge([$a], [$b]));
    }

    /** @return array<int, list<string>> the identifier keys of a work, by level (DOI, ISBN, arXiv, HAL) */
    private function identifierKeys(Work $work): array
    {
        $keys = [];
        foreach (self::SCHEMES as $level => $scheme) {
            if (Scheme::ISBN === $scheme && !\in_array($work->type, [WorkType::BOOK, WorkType::THESIS, WorkType::REPORT, WorkType::OTHER], true)) {
                continue;
            }
            $keys[$level] = array_map(static fn (Identifier $i) => $i->key(), $work->identifiers->all($scheme));
        }

        return $keys;
    }

    /**
     * @return list<string> the title (and, when long enough, the title without its subtitle) in the year; a
     *                      preprint's in the next year too, when it is often published
     */
    private function titleKeys(Work $work): array
    {
        $years = null === $work->year ? ['?'] : (WorkType::PREPRINT === $work->type ? [$work->year, $work->year + 1] : [$work->year]);
        $keys = [];
        foreach (array_unique([self::fingerprint($work->fullTitle()), self::fingerprint($work->title)]) as $i => $title) {
            if ('' !== $title && (0 === $i || \strlen($title) >= 16)) {
                foreach ($years as $year) {
                    $keys[] = 'title:'.$title.'|'.$year;
                }
            }
        }

        return $keys;
    }

    /**
     * Whether two groups of records carry, for one of the schemes, values
     * on both sides and none in common.
     *
     * @param list<Work>   $a
     * @param list<Work>   $b
     * @param list<Scheme> $schemes
     */
    private function conflicts(array $a, array $b, array $schemes): bool
    {
        foreach ($schemes as $scheme) {
            $left = array_merge(...array_map(static fn (Work $w) => $w->identifiers->values($scheme), $a));
            $right = array_merge(...array_map(static fn (Work $w) => $w->identifiers->values($scheme), $b));
            if ($left && $right && !array_intersect($left, $right)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int>  $cluster
     * @param list<Work> $works
     *
     * @return list<Work>
     */
    private function collect(array $cluster, array $works): array
    {
        return array_map(static fn (int $i) => $works[$i], $cluster);
    }

    /**
     * One record from several: each field from the source preferred for it.
     *
     * @param list<Work> $works
     */
    private function combine(array $works): Work
    {
        if (1 === \count($works)) {
            $work = $works[0];

            return $work->sources ? $work : $work->with(['sources' => array_values(array_filter([$work->source]))]);
        }

        $default = $this->ordered($works, 'default');
        $values = [];
        foreach (self::FIELDS as $field) {
            foreach ($this->ordered($works, $field) as $work) {
                $value = $work->{$field};
                if (null !== $value && '' !== $value) {
                    $values[$field] = $value;
                    break;
                }
            }
        }
        // The title's subtitle goes with it.
        $titled = $this->first($this->ordered($works, 'title'), static fn (Work $w) => '' !== $w->title) ?? $default[0];
        $values['title'] = $titled->title;
        $values['subtitle'] = $titled->subtitle;

        $type = $this->first($this->ordered($works, 'type'), static fn (Work $w) => WorkType::OTHER !== $w->type)?->type ?? WorkType::OTHER;

        // The year and the date from one record; a date only when its year agrees.
        $dated = $this->first($this->ordered($works, 'year'), static fn (Work $w) => null !== $w->year);
        $year = $dated?->year;
        $date = $dated?->date ?? $this->first($this->ordered($works, 'date'), static fn (Work $w) => null !== $w->date && $w->year === $year)?->date;

        $identifiers = new Identifiers();
        $sources = [];
        $keywords = [];
        $domains = [];
        foreach ($default as $work) {
            $identifiers = $identifiers->merge($work->identifiers);
            array_push($sources, ...($work->sources ?: array_filter([$work->source])));
            array_push($keywords, ...$work->keywords);
            array_push($domains, ...$work->domains);
        }

        $open = array_map(static fn (Work $w) => $w->openAccess, $works);

        return new Work(...$values + [
            'type' => $type,
            'authors' => $this->authors($works),
            'year' => $year,
            'date' => $date,
            'openAccess' => \in_array(true, $open, true) ? true : (\in_array(false, $open, true) ? false : null),
            'keywords' => self::unique($keywords),
            'domains' => self::unique($domains),
            'identifiers' => $identifiers,
            'source' => $default[0]->source,
            'sources' => array_values(array_unique($sources)),
        ]);
    }

    /**
     * The preferred list of authors, each completed with the identifiers
     * (an ORCID...) the other sources give the same name.
     *
     * @param list<Work> $works
     *
     * @return list<Contributor>
     */
    private function authors(array $works): array
    {
        $ordered = $this->ordered($works, 'authors');
        $chosen = $this->first($ordered, static fn (Work $w) => [] !== $w->authors)?->authors ?? [];
        // Only names that occur once in a list: two Wangs on one paper are not told apart by name.
        $once = static function (array $contributors): array {
            $keys = array_count_values(array_map(static fn (Contributor $c) => self::fingerprint($c->familyName()), $contributors));

            return array_filter($keys, static fn (int $n) => 1 === $n);
        };
        $known = [];
        foreach ($ordered as $work) {
            $unique = $once($work->authors);
            foreach ($work->authors as $contributor) {
                $key = self::fingerprint($contributor->familyName());
                if (isset($unique[$key])) {
                    $known[$key] = isset($known[$key]) ? $known[$key]->merge($contributor->identifiers) : $contributor->identifiers;
                }
            }
        }
        $unique = $once($chosen);

        return array_map(static function (Contributor $c) use ($known, $unique): Contributor {
            $key = self::fingerprint($c->familyName());
            if (!isset($unique[$key], $known[$key])) {
                return $c;
            }
            // Only the schemes it lacks: an ORCID it has is not doubled by another.
            $missing = array_filter($known[$key]->all(), static fn (Identifier $i) => !$c->identifiers->has($i->scheme));

            return $missing ? new Contributor($c->name, $c->given, $c->family, $c->identifiers->with(...$missing), $c->role, $c->affiliations) : $c;
        }, $chosen);
    }

    /**
     * Books with the same title and first author, no ISBN in common: one
     * record, the newest edition's, with every edition under $editions.
     *
     * @param list<Work> $works
     *
     * @return list<Work>
     */
    private function groupEditions(array $works): array
    {
        $groups = [];
        foreach ($works as $i => $work) {
            $key = WorkType::BOOK === $work->type ? 'book:'.self::fingerprint(self::mainTitle($work)).'|'.self::fingerprint($work->firstAuthor()?->familyName() ?? '') : 'work:'.$i;
            $groups[$key][] = $work;
        }

        $grouped = [];
        foreach ($groups as $group) {
            if (1 === \count($group)) {
                $grouped[] = $group[0];
                continue;
            }
            $editions = self::newestFirst($group);
            $book = $editions[0];
            $changes = ['editions' => $editions];
            foreach (['subtitle', 'abstract', 'cover', 'publisher', 'language', 'url', 'citations'] as $field) {
                if (null === $book->{$field}) {
                    $changes[$field] = $this->first($editions, static fn (Work $w) => null !== $w->{$field})?->{$field};
                }
            }
            $identifiers = new Identifiers();
            $sources = [];
            foreach ($editions as $edition) {
                $identifiers = $identifiers->merge($edition->identifiers);
                array_push($sources, ...$edition->sources);
            }
            $changes['identifiers'] = $identifiers;
            $changes['sources'] = array_values(array_unique($sources));
            $grouped[] = $book->with($changes);
        }

        return $grouped;
    }

    /**
     * The records in the order a field trusts their sources.
     *
     * @param list<Work> $works
     *
     * @return list<Work>
     */
    private function ordered(array $works, string $field): array
    {
        $order = $this->preferences[$field] ?? $this->preferences['default'];
        $rank = static function (Work $w) use ($order): int {
            $ranks = array_map(static fn (?string $s) => false === ($r = array_search($s, $order, true)) ? \PHP_INT_MAX : $r, $w->sources ?: [$w->source]);

            return min($ranks);
        };
        $positions = array_keys($works);
        usort($positions, static fn (int $a, int $b) => [$rank($works[$a]), $a] <=> [$rank($works[$b]), $b]);

        return array_map(static fn (int $i) => $works[$i], $positions);
    }

    /** @param list<Work> $works */
    private function first(array $works, \Closure $test): ?Work
    {
        foreach ($works as $work) {
            if ($test($work)) {
                return $work;
            }
        }

        return null;
    }

    private function editionless(): self
    {
        return new self($this->preferences, false);
    }

    /**
     * @param list<Work> $works
     *
     * @return list<Work> by date, then year, newest first; undated last; stable
     */
    public static function newestFirst(array $works): array
    {
        $positions = array_keys($works);
        usort($positions, static function (int $a, int $b) use ($works): int {
            $da = $works[$a]->date ?? (null !== $works[$a]->year ? (string) $works[$a]->year : '');
            $db = $works[$b]->date ?? (null !== $works[$b]->year ? (string) $works[$b]->year : '');

            return [$db, $a] <=> [$da, $b];
        });

        return array_map(static fn (int $i) => $works[$i], $positions);
    }

    /** "Sowing the Seeds of Geometry, PreK-2", from a title that may carry its subtitle after a colon. */
    public static function mainTitle(Work $work): string
    {
        $parts = preg_split('~\s*[:：]\s+~u', $work->title, 2);

        return null !== $work->subtitle || 2 !== \count($parts ?: []) || \strlen(self::fingerprint($parts[0])) < 8 ? $work->title : $parts[0];
    }

    /** A title or a name as the Merger compares it: lower case, no accents, no punctuation. */
    public static function fingerprint(string $text): string
    {
        $text = strip_tags($text);
        if (\function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $text = strtolower(strtr($text, [
                'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
            ]));
        }
        $text = str_replace('&', ' and ', $text);

        return trim((string) preg_replace('~[^a-z0-9]+~', ' ', $text));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> each once, compared without case
     */
    private static function unique(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[strtolower($value)] ??= $value;
        }

        return array_values($unique);
    }
}
