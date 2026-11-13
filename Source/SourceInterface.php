<?php

namespace Omnischolar\Source;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\ProviderException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Model\Author;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Work;

/**
 * A service that knows publications: OpenAlex, HAL, Crossref, ORCID, Open
 * Library, arXiv, INSPIRE-HEP. A source answers what it can, says what
 * that is (capabilities()), and throws NotSupportedException for the rest:
 * an operation it does not do, an identifier it cannot read.
 *
 * An author or a work is named by an Identifier, or by a string the source
 * reads: one of its own identifiers ("A5046427077", "OL9990001A"), or for
 * the sources that search by name, a name ("Alain Aspect").
 *
 * Unknown is null (or an empty page); a service that is down or refuses
 * is an UnavailableException (RateLimitedException when asked to slow
 * down); any other error answer a ProviderException.
 *
 * @throws NotSupportedException
 * @throws UnavailableException
 * @throws ProviderException
 */
interface SourceInterface
{
    /** The name it is configured as: "openalex", "hal"... */
    public function getName(): string;

    /** @return list<Capability> */
    public function capabilities(): array;

    /** The researcher's profile, or null when unknown. */
    public function author(Identifier|string $author): ?Author;

    /** An author's works, one page at a time: newest first unless the query sorts otherwise. */
    public function works(Identifier|string $author, ?Query $query = null): Page;

    /** One work, or null when unknown. */
    public function work(Identifier|string $id): ?Work;

    /** Works matching the query, one page at a time. */
    public function search(Query $query): Page;
}
