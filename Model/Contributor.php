<?php

namespace Omnischolar\Model;

/** A name on a work: an author, an editor, a translator... as the source lists it. */
final readonly class Contributor
{
    public const AUTHOR = 'author';
    public const EDITOR = 'editor';
    public const TRANSLATOR = 'translator';

    /**
     * @param list<string> $affiliations the institutions as printed on the work
     */
    public function __construct(
        public string $name,
        public ?string $given = null,
        public ?string $family = null,
        public Identifiers $identifiers = new Identifiers(),
        public string $role = self::AUTHOR,
        public array $affiliations = [],
    ) {
    }

    /** "Exemple, Jeanne" or "Jeanne Exemple": the family name guessed from the comma, else the last word. */
    public static function fromName(string $name, ?Identifiers $identifiers = null, string $role = self::AUTHOR): self
    {
        $name = trim((string) preg_replace('~\s+~u', ' ', $name));
        if (str_contains($name, ',')) {
            [$family, $given] = array_map('trim', explode(',', $name, 2));

            return new self(trim($given.' '.$family), '' === $given ? null : $given, $family, $identifiers ?? new Identifiers(), $role);
        }
        $parts = explode(' ', $name);
        $family = array_pop($parts);

        return new self($name, $parts ? implode(' ', $parts) : null, $family, $identifiers ?? new Identifiers(), $role);
    }

    /** The family name, or the last word of the name when the source gave none. */
    public function familyName(): string
    {
        if (null !== $this->family && '' !== $this->family) {
            return $this->family;
        }
        $parts = explode(' ', trim($this->name));

        return (string) end($parts);
    }

    /** "Exemple, Jeanne", as a bibliography sorts it. */
    public function sortName(): string
    {
        return null !== $this->given && '' !== $this->given ? $this->familyName().', '.$this->given : $this->familyName();
    }

    public function orcid(): ?string
    {
        return $this->identifiers->value(Scheme::ORCID);
    }
}
