<?php

namespace Omnischolar\Model;

/**
 * The kinds of identifiers a scholarly record carries. An ISBN is kept as
 * its ISBN-13 (an ISBN-10 is converted; Identifier::isbn10() gives it back).
 */
enum Scheme: string
{
    case DOI = 'doi';
    case ISBN = 'isbn';
    case ISSN = 'issn';
    case ARXIV = 'arxiv';
    /** A HAL deposit: hal-01234567, tel-..., halshs-... */
    case HAL = 'hal';
    /** A HAL author: their idHAL (philippe-grangier). */
    case IDHAL = 'idhal';
    case ORCID = 'orcid';
    /** W... (work), A... (author), S... (source), I... (institution). */
    case OPENALEX = 'openalex';
    /** An INSPIRE-HEP record number: literature or author. */
    case INSPIRE = 'inspire';
    /** An INSPIRE author's BAI: Juan.M.Maldacena.1 */
    case INSPIRE_BAI = 'inspire_bai';
    /** OL...W (work), OL...M (edition), OL...A (author). */
    case OPENLIBRARY = 'openlibrary';
    case PMID = 'pmid';
    case PMCID = 'pmcid';

    public function label(): string
    {
        return match ($this) {
            self::DOI => 'DOI',
            self::ISBN => 'ISBN',
            self::ISSN => 'ISSN',
            self::ARXIV => 'arXiv',
            self::HAL => 'HAL',
            self::IDHAL => 'idHAL',
            self::ORCID => 'ORCID',
            self::OPENALEX => 'OpenAlex',
            self::INSPIRE => 'INSPIRE',
            self::INSPIRE_BAI => 'INSPIRE BAI',
            self::OPENLIBRARY => 'Open Library',
            self::PMID => 'PubMed',
            self::PMCID => 'PubMed Central',
        };
    }
}
