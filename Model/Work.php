<?php

namespace Omnischolar\Model;

/**
 * A publication: an article, a book, a chapter, a communication, a
 * preprint... as one source describes it, or as the Merger assembled it
 * from several ($sources). A book whose editions were grouped carries them
 * in $editions, newest first.
 *
 * Dates are ISO 8601, as precise as the source knows them: "2024",
 * "2024-09", "2024-09-25".
 */
final readonly class Work
{
    /**
     * @param list<Contributor> $authors   in the order printed, editors included (their role says so)
     * @param list<string>      $keywords
     * @param list<string>      $domains   subject codes: HAL domains (shs.droit), arXiv / INSPIRE categories (hep-th)
     * @param list<string>      $sources   the sources merged into this record
     * @param list<Work>        $editions  a book's editions, newest first (Merger)
     */
    public function __construct(
        public string $title,
        public WorkType $type = WorkType::OTHER,
        public array $authors = [],
        public ?int $year = null,
        public ?string $date = null,
        public ?string $subtitle = null,
        public ?Venue $venue = null,
        public ?string $publisher = null,
        public ?string $volume = null,
        public ?string $issue = null,
        public ?string $pages = null,
        public ?string $edition = null,
        public ?string $abstract = null,
        public ?string $language = null,
        public ?bool $openAccess = null,
        public ?string $url = null,
        public ?string $pdfUrl = null,
        public ?string $license = null,
        public ?string $cover = null,
        public ?int $citations = null,
        public array $keywords = [],
        public array $domains = [],
        public Identifiers $identifiers = new Identifiers(),
        public ?string $source = null,
        public array $sources = [],
        public array $editions = [],
        public ?string $note = null,
    ) {
    }

    /** A copy with some fields changed: $work->with(['cover' => $url]). */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** "Sowing the Seeds of Geometry, PreK-2: Explorations for the Early Grades" */
    public function fullTitle(): string
    {
        return null !== $this->subtitle && '' !== $this->subtitle && !str_contains($this->title, $this->subtitle) ? $this->title.': '.$this->subtitle : $this->title;
    }

    public function id(Scheme $scheme): ?string
    {
        return $this->identifiers->value($scheme);
    }

    public function doi(): ?string
    {
        return $this->identifiers->value(Scheme::DOI);
    }

    /** @return list<string> */
    public function isbns(): array
    {
        return $this->identifiers->values(Scheme::ISBN);
    }

    /** @return list<Contributor> the authors only, without editors and translators */
    public function authorsOnly(): array
    {
        return array_values(array_filter($this->authors, static fn (Contributor $c) => Contributor::AUTHOR === $c->role));
    }

    /** @return list<Contributor> */
    public function editors(): array
    {
        return array_values(array_filter($this->authors, static fn (Contributor $c) => Contributor::EDITOR === $c->role));
    }

    public function firstAuthor(): ?Contributor
    {
        return $this->authorsOnly()[0] ?? $this->authors[0] ?? null;
    }

    /** The best link to read it: the open-access PDF, else the landing page, else the DOI. */
    public function link(): ?string
    {
        return $this->pdfUrl ?? $this->url ?? $this->identifiers->get(Scheme::DOI)?->url();
    }
}
