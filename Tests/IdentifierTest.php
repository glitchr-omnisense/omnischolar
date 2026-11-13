<?php

namespace Omnischolar\Tests;

use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    /** @return iterable<array{string, string|null}> */
    public static function parsed(): iterable
    {
        yield ['10.1140/EPJD/S10053-022-00549-6', 'doi:10.1140/epjd/s10053-022-00549-6'];
        yield ['https://doi.org/10.1140/epjd/s10053-022-00549-6', 'doi:10.1140/epjd/s10053-022-00549-6'];
        yield ['doi:10.1023/A:1026654312961', 'doi:10.1023/a:1026654312961'];
        yield ['https://dx.doi.org/10.5555/9782999990104', 'doi:10.5555/9782999990104'];
        yield ['2-99999-009-X', 'isbn:9782999990098'];
        yield ['978-2-99999-009-8', 'isbn:9782999990098'];
        yield ['isbn:2999990065', 'isbn:9782999990067'];
        yield ['2999990090', null];
        yield ['arXiv:hep-th/9711200v3', 'arxiv:hep-th/9711200'];
        yield ['2609.38052v1', 'arxiv:2609.38052'];
        yield ['https://arxiv.org/abs/2401.12345', 'arxiv:2401.12345'];
        yield ['https://arxiv.org/pdf/math.GT/0309136v2', 'arxiv:math.GT/0309136'];
        yield ['hal-03843937', 'hal:hal-03843937'];
        yield ['https://hal.science/hal-03843937v1/document', 'hal:hal-03843937'];
        yield ['halshs-01234567', 'hal:halshs-01234567'];
        yield ['tel-04012345v2', 'hal:tel-04012345'];
        yield ['0000-0003-0094-5584', 'orcid:0000-0003-0094-5584'];
        yield ['https://orcid.org/0000-0002-1825-0097', 'orcid:0000-0002-1825-0097'];
        yield ['0000-0002-1825-0098', null];
        yield ['A5046427077', 'openalex:A5046427077'];
        yield ['https://openalex.org/W7133342466', 'openalex:W7133342466'];
        yield ['OL9990001A', 'openlibrary:OL9990001A'];
        yield ['https://openlibrary.org/works/OL9990016W', 'openlibrary:OL9990016W'];
        yield ['bai:Juan.M.Maldacena.1', 'inspire_bai:Juan.M.Maldacena.1'];
        yield ['inspire:451647', 'inspire:451647'];
        yield ['https://inspirehep.net/literature/451647', 'inspire:451647'];
        yield ['PMC1234567', 'pmcid:PMC1234567'];
        yield ['2041-6520', 'issn:2041-6520'];
        yield ['451647', null];
        yield ['Jeanne Exemple', null];
        yield ['philippe-grangier', null];
    }

    #[DataProvider('parsed')]
    public function testParse(string $input, ?string $key): void
    {
        self::assertSame($key, Identifier::parse($input)?->key());
    }

    public function testNormalisationMakesSpellingsEqual(): void
    {
        self::assertTrue(Identifier::doi('10.1140/EPJD/S10053-022-00549-6')->equals(Identifier::doi('https://doi.org/10.1140/epjd/s10053-022-00549-6')));
        self::assertTrue(Identifier::isbn('299999009X')->equals(Identifier::isbn('978-2-99999-009-8')));
        self::assertSame('299999009X', Identifier::isbn('9782999990098')->isbn10());
        self::assertNull(Identifier::isbn('9791032305690')->isbn10(), 'a 979 ISBN has no ISBN-10');
        self::assertSame('https://doi.org/10.1140/epjd/s10053-022-00549-6', Identifier::doi('10.1140/epjd/s10053-022-00549-6')->url());
        self::assertSame('https://openlibrary.org/books/OL9990026M', Identifier::openlibrary('OL9990026M')->url());
        self::assertSame('philippe-grangier', Identifier::of('idhal', 'Philippe-Grangier')->value);
        self::assertNull(Identifier::tryOf(Scheme::DOI, 'not a doi'));
        $this->expectException(\InvalidArgumentException::class);
        Identifier::orcid('0000-0002-1825-0098');
    }

    public function testACollectionKeepsEachOnce(): void
    {
        $ids = Identifiers::of(Identifier::doi('10.1234/A'), Identifier::isbn('299999009X'), null, Identifier::doi('10.1234/a'), Identifier::isbn('9782999990104'));

        self::assertCount(3, $ids);
        self::assertSame('10.1234/a', $ids->value(Scheme::DOI));
        self::assertSame(['9782999990098', '9782999990104'], $ids->values(Scheme::ISBN));
        self::assertTrue($ids->has(Identifier::isbn('978-2-99999-010-4')));
        self::assertFalse($ids->has(Scheme::ARXIV));
        self::assertSame(['doi' => ['10.1234/a'], 'isbn' => ['9782999990098', '9782999990104']], $ids->toArray());
        self::assertEquals($ids, Identifiers::fromArray($ids->toArray()));
        self::assertCount(4, $ids->with(Identifier::arxiv('2401.12345')));
    }
}
