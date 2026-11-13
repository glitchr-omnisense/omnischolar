<?php

namespace Omnischolar\Model;

/**
 * One identifier, normalised so that two spellings of it compare equal: a
 * DOI lower-cased and without its resolver, an ISBN as its ISBN-13, an
 * arXiv or HAL id without its version, an ORCID with its checksum checked.
 *
 *   Identifier::doi('https://doi.org/10.1140/EPJD/S10053-022-00549-6')->value;   // 10.1140/epjd/s10053-022-00549-6
 *   Identifier::isbn('2-99999-009-X')->value;                                    // 9782999990098
 *   Identifier::parse('arXiv:hep-th/9711200v3');                                 // arxiv:hep-th/9711200
 *   Identifier::parse('A5046427077');                                            // openalex:A5046427077
 *   (string) Identifier::orcid('0000-0003-0094-5584');                           // orcid:0000-0003-0094-5584
 */
final readonly class Identifier implements \Stringable
{
    private const ARXIV_NEW = '~^(\d{4}\.\d{4,5})(v\d+)?$~';
    private const ARXIV_OLD = '~^([a-z][a-z-]*(?:\.[A-Za-z]{2})?/\d{7})(v\d+)?$~';
    private const HAL = '~^([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*[-_]\d{8})(v\d+)?$~';

    private function __construct(
        public Scheme $scheme,
        public string $value,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the value is not a valid identifier of that scheme
     */
    public static function of(Scheme|string $scheme, string $value): self
    {
        $scheme = $scheme instanceof Scheme ? $scheme : Scheme::from(strtolower($scheme));

        return self::tryOf($scheme, $value) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a valid %s.', $value, $scheme->label()));
    }

    /** The identifier, or null when the value is not one of that scheme. */
    public static function tryOf(Scheme|string|null $scheme, ?string $value): ?self
    {
        if (null === $scheme || null === $value) {
            return null;
        }
        $scheme = $scheme instanceof Scheme ? $scheme : Scheme::tryFrom(strtolower($scheme));
        $value = trim($value);
        if (null === $scheme || '' === $value) {
            return null;
        }
        $normalized = match ($scheme) {
            Scheme::DOI => self::normalizeDoi($value),
            Scheme::ISBN => self::normalizeIsbn($value),
            Scheme::ISSN => self::normalizeIssn($value),
            Scheme::ARXIV => self::normalizeArxiv($value),
            Scheme::HAL => self::normalizeHal($value),
            Scheme::IDHAL => preg_match('~^[a-z0-9][a-z0-9._-]*$~', $v = strtolower($value)) ? $v : null,
            Scheme::ORCID => self::normalizeOrcid($value),
            Scheme::OPENALEX => preg_match('~^(?:https?://(?:api\.)?openalex\.org/(?:\w+/)?)?([WASICFPTK]\d+)$~i', $value, $m) ? strtoupper($m[1]) : null,
            Scheme::INSPIRE => preg_match('~^(?:https?://inspirehep\.net/(?:api/)?\w+/)?(\d+)$~', $value, $m) ? $m[1] : null,
            Scheme::INSPIRE_BAI => preg_match('~^[\p{L}\w\'-]+(?:\.[\p{L}\w\'-]+)*\.\d+$~u', $value) ? $value : null,
            Scheme::OPENLIBRARY => preg_match('~^(?:https?://openlibrary\.org/|/)?(?:\w+/)?(OL\d+[WMA])(?:[/.].*)?$~i', $value, $m) ? strtoupper($m[1]) : null,
            Scheme::PMID => preg_match('~^(?:pmid:\s*)?(\d{1,9})$~i', $value, $m) ? $m[1] : (preg_match('~pubmed\.ncbi\.nlm\.nih\.gov/(\d+)~', $value, $m) ? $m[1] : null),
            Scheme::PMCID => preg_match('~(PMC\d+)~i', $value, $m) ? strtoupper($m[1]) : null,
        };

        return null === $normalized ? null : new self($scheme, $normalized);
    }

    /**
     * Reads an identifier from a string: "scheme:value", a resolver URL
     * (doi.org, orcid.org, arxiv.org, hal.science, openalex.org,
     * inspirehep.net, openlibrary.org, pubmed) or a value whose shape tells
     * its scheme. Null when nothing is recognised: a bare number (an INSPIRE
     * record or a PubMed id?) needs its scheme.
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }
        // scheme:value
        if (preg_match('~^([a-z_]+):\s*(.+)$~i', $input, $m) && !str_starts_with($m[2], '//')) {
            $prefix = strtolower($m[1]);
            $scheme = match ($prefix) {
                'ol' => Scheme::OPENLIBRARY,
                'bai' => Scheme::INSPIRE_BAI,
                'isbn10', 'isbn13', 'isbn-10', 'isbn-13' => Scheme::ISBN,
                default => Scheme::tryFrom($prefix),
            };
            if ($scheme) {
                return self::tryOf($scheme, $m[2]);
            }
        }
        // resolver URLs
        if (preg_match('~^https?://~i', $input)) {
            $host = strtolower((string) parse_url($input, \PHP_URL_HOST));
            $path = rawurldecode(ltrim((string) parse_url($input, \PHP_URL_PATH), '/'));

            return match (true) {
                str_ends_with($host, 'doi.org') => self::tryOf(Scheme::DOI, $path),
                str_ends_with($host, 'orcid.org') => self::tryOf(Scheme::ORCID, $path),
                str_ends_with($host, 'arxiv.org') => self::tryOf(Scheme::ARXIV, preg_replace('~^(abs|pdf|html)/|\.pdf$~', '', $path)),
                str_ends_with($host, 'openalex.org') => self::tryOf(Scheme::OPENALEX, basename($path)),
                str_ends_with($host, 'inspirehep.net') => self::tryOf(Scheme::INSPIRE, basename($path)),
                str_ends_with($host, 'openlibrary.org') => self::tryOf(Scheme::OPENLIBRARY, $path),
                str_ends_with($host, 'pubmed.ncbi.nlm.nih.gov') => self::tryOf(Scheme::PMID, trim($path, '/')),
                str_contains($host, 'hal.science') || str_contains($host, 'archives-ouvertes.fr') => self::tryOf(Scheme::HAL, explode('/', $path)[0]),
                default => null,
            };
        }

        return match (true) {
            str_starts_with($input, '10.') => self::tryOf(Scheme::DOI, $input),
            (bool) preg_match('~^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$~i', $input) => self::tryOf(Scheme::ORCID, $input),
            (bool) preg_match('~^[WA]\d{6,}$~i', $input) => self::tryOf(Scheme::OPENALEX, $input),
            (bool) preg_match('~^OL\d+[WMA]$~i', $input) => self::tryOf(Scheme::OPENLIBRARY, $input),
            (bool) preg_match('~^PMC\d+$~i', $input) => self::tryOf(Scheme::PMCID, $input),
            (bool) preg_match(self::ARXIV_NEW, $input), (bool) preg_match(self::ARXIV_OLD, $input) => self::tryOf(Scheme::ARXIV, $input),
            (bool) preg_match(self::HAL, $input) => self::tryOf(Scheme::HAL, $input),
            (bool) preg_match('~^[\d -]{9,17}[\dX]$~i', $input) => self::tryOf(Scheme::ISBN, $input),
            (bool) preg_match('~^\d{4}-\d{3}[\dX]$~i', $input) => self::tryOf(Scheme::ISSN, $input),
            default => null,
        };
    }

    public static function doi(string $value): self
    {
        return self::of(Scheme::DOI, $value);
    }

    public static function isbn(string $value): self
    {
        return self::of(Scheme::ISBN, $value);
    }

    public static function arxiv(string $value): self
    {
        return self::of(Scheme::ARXIV, $value);
    }

    public static function hal(string $value): self
    {
        return self::of(Scheme::HAL, $value);
    }

    public static function orcid(string $value): self
    {
        return self::of(Scheme::ORCID, $value);
    }

    public static function openalex(string $value): self
    {
        return self::of(Scheme::OPENALEX, $value);
    }

    public static function inspire(string $value): self
    {
        return self::of(Scheme::INSPIRE, $value);
    }

    public static function openlibrary(string $value): self
    {
        return self::of(Scheme::OPENLIBRARY, $value);
    }

    /** "doi:10.1140/epjd/s10053-022-00549-6": what the Merger and the collections key on. */
    public function key(): string
    {
        return $this->scheme->value.':'.$this->value;
    }

    public function equals(self $other): bool
    {
        return $this->scheme === $other->scheme && $this->value === $other->value;
    }

    /** The page that resolves it, when there is one. */
    public function url(): ?string
    {
        return match ($this->scheme) {
            Scheme::DOI => 'https://doi.org/'.$this->value,
            Scheme::ARXIV => 'https://arxiv.org/abs/'.$this->value,
            Scheme::HAL => 'https://hal.science/'.$this->value,
            Scheme::IDHAL => 'https://cv.hal.science/'.$this->value,
            Scheme::ORCID => 'https://orcid.org/'.$this->value,
            Scheme::OPENALEX => 'https://openalex.org/'.$this->value,
            Scheme::INSPIRE => null,
            Scheme::INSPIRE_BAI => 'https://inspirehep.net/authors?q='.rawurlencode('ids.value:'.$this->value),
            Scheme::OPENLIBRARY => 'https://openlibrary.org/'.match (substr($this->value, -1)) {'W' => 'works', 'A' => 'authors', default => 'books'}.'/'.$this->value,
            Scheme::PMID => 'https://pubmed.ncbi.nlm.nih.gov/'.$this->value.'/',
            Scheme::PMCID => 'https://www.ncbi.nlm.nih.gov/pmc/articles/'.$this->value.'/',
            Scheme::ISBN, Scheme::ISSN => null,
        };
    }

    /** An ISBN's ISBN-10 form (only 978- ISBNs have one). */
    public function isbn10(): ?string
    {
        if (Scheme::ISBN !== $this->scheme || !str_starts_with($this->value, '978')) {
            return null;
        }
        $core = substr($this->value, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; ++$i) {
            $sum += (10 - $i) * (int) $core[$i];
        }
        $check = (11 - $sum % 11) % 11;

        return $core.(10 === $check ? 'X' : (string) $check);
    }

    public function __toString(): string
    {
        return $this->key();
    }

    private static function normalizeDoi(string $value): ?string
    {
        $value = preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', rawurldecode($value));

        return preg_match('~^10\.\d{4,9}/\S+$~', (string) $value) ? strtolower((string) $value) : null;
    }

    private static function normalizeIsbn(string $value): ?string
    {
        $digits = strtoupper((string) preg_replace('~[^0-9Xx]~', '', $value));
        if (10 === \strlen($digits) && preg_match('~^\d{9}[\dX]$~', $digits)) {
            $sum = 0;
            for ($i = 0; $i < 10; ++$i) {
                $sum += (10 - $i) * ('X' === $digits[$i] ? 10 : (int) $digits[$i]);
            }
            if (0 !== $sum % 11) {
                return null;
            }
            $digits = '978'.substr($digits, 0, 9);

            return $digits.self::isbn13Check($digits);
        }
        if (13 === \strlen($digits) && ctype_digit($digits) && (str_starts_with($digits, '978') || str_starts_with($digits, '979'))) {
            return self::isbn13Check(substr($digits, 0, 12)) === $digits[12] ? $digits : null;
        }

        return null;
    }

    private static function isbn13Check(string $twelve): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $twelve[$i] * (0 === $i % 2 ? 1 : 3);
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    private static function normalizeIssn(string $value): ?string
    {
        $digits = strtoupper((string) preg_replace('~[^0-9Xx]~', '', $value));
        if (!preg_match('~^\d{7}[\dX]$~', $digits)) {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (8 - $i) * (int) $digits[$i];
        }
        $check = (11 - $sum % 11) % 11;

        return (10 === $check ? 'X' : (string) $check) === $digits[7] ? substr($digits, 0, 4).'-'.substr($digits, 4) : null;
    }

    private static function normalizeArxiv(string $value): ?string
    {
        $value = (string) preg_replace('~^(?:https?://(?:export\.)?arxiv\.org/(?:abs|pdf)/|arxiv:\s*)~i', '', $value);
        $value = (string) preg_replace('~\.pdf$~', '', $value);
        if (preg_match(self::ARXIV_NEW, $value, $m)) {
            return $m[1];
        }

        return preg_match(self::ARXIV_OLD, $value, $m) ? $m[1] : null;
    }

    private static function normalizeHal(string $value): ?string
    {
        $value = strtolower((string) preg_replace('~^https?://[^/]+/~i', '', $value));
        $value = explode('/', $value)[0];

        return preg_match(self::HAL, $value, $m) ? $m[1] : null;
    }

    private static function normalizeOrcid(string $value): ?string
    {
        $value = strtoupper((string) preg_replace('~^https?://(?:www\.|sandbox\.)?orcid\.org/~i', '', trim($value)));
        if (!preg_match('~^(\d{4})-?(\d{4})-?(\d{4})-?(\d{3}[\dX])$~', $value, $m)) {
            return null;
        }
        $digits = $m[1].$m[2].$m[3].$m[4];
        $total = 0;
        for ($i = 0; $i < 15; ++$i) {
            $total = ($total + (int) $digits[$i]) * 2;
        }
        $check = (12 - $total % 11) % 11;

        return (10 === $check ? 'X' : (string) $check) === $digits[15] ? "$m[1]-$m[2]-$m[3]-$m[4]" : null;
    }
}
