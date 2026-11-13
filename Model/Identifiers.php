<?php

namespace Omnischolar\Model;

/**
 * A record's identifiers, each once, in the order they were learnt.
 *
 * @implements \IteratorAggregate<int, Identifier>
 */
final readonly class Identifiers implements \IteratorAggregate, \Countable
{
    /** @var list<Identifier> */
    private array $identifiers;

    /** @param iterable<Identifier|null> $identifiers nulls (an identifier that did not parse) are skipped */
    public function __construct(iterable $identifiers = [])
    {
        $unique = [];
        foreach ($identifiers as $identifier) {
            if (null !== $identifier) {
                $unique[$identifier->key()] ??= $identifier;
            }
        }
        $this->identifiers = array_values($unique);
    }

    public static function of(?Identifier ...$identifiers): self
    {
        return new self($identifiers);
    }

    /** The first identifier of that scheme. */
    public function get(Scheme $scheme): ?Identifier
    {
        foreach ($this->identifiers as $identifier) {
            if ($identifier->scheme === $scheme) {
                return $identifier;
            }
        }

        return null;
    }

    /** The first value of that scheme: $work->identifiers->value(Scheme::DOI). */
    public function value(Scheme $scheme): ?string
    {
        return $this->get($scheme)?->value;
    }

    /** @return list<Identifier> every identifier, or those of one scheme */
    public function all(?Scheme $scheme = null): array
    {
        return null === $scheme ? $this->identifiers : array_values(array_filter($this->identifiers, static fn (Identifier $i) => $i->scheme === $scheme));
    }

    /** @return list<string> the values of one scheme */
    public function values(Scheme $scheme): array
    {
        return array_map(static fn (Identifier $i) => $i->value, $this->all($scheme));
    }

    public function has(Scheme|Identifier $what): bool
    {
        if ($what instanceof Scheme) {
            return null !== $this->get($what);
        }
        foreach ($this->identifiers as $identifier) {
            if ($identifier->equals($what)) {
                return true;
            }
        }

        return false;
    }

    public function with(?Identifier ...$identifiers): self
    {
        return new self([...$this->identifiers, ...$identifiers]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->identifiers, ...$other->identifiers]);
    }

    /** @return list<string> "doi:10.1140/epjd/s10053-022-00549-6"... */
    public function keys(): array
    {
        return array_map(static fn (Identifier $i) => $i->key(), $this->identifiers);
    }

    /** @return array<string, list<string>> values by scheme, for storage */
    public function toArray(): array
    {
        $array = [];
        foreach ($this->identifiers as $identifier) {
            $array[$identifier->scheme->value][] = $identifier->value;
        }

        return $array;
    }

    /** @param array<string, list<string>|string> $array what toArray() gave */
    public static function fromArray(array $array): self
    {
        $identifiers = [];
        foreach ($array as $scheme => $values) {
            foreach ((array) $values as $value) {
                $identifiers[] = Identifier::tryOf((string) $scheme, (string) $value);
            }
        }

        return new self($identifiers);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->identifiers);
    }

    public function count(): int
    {
        return \count($this->identifiers);
    }
}
