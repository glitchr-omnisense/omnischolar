<?php

namespace Omnischolar\Tests;

use Omnischolar\Merger;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use PHPUnit\Framework\TestCase;

final class MergerTest extends TestCase
{
    private static function work(string $source, string $title, ?int $year, array $identifiers = [], array $fields = []): Work
    {
        return new Work(...$fields + [
            'title' => $title,
            'type' => WorkType::ARTICLE,
            'year' => $year,
            'identifiers' => new Identifiers($identifiers),
            'source' => $source,
            'authors' => [Contributor::fromName('Jeanne Exemple')],
        ]);
    }

    public function testTheSameDoiIsOneWorkEachFieldFromItsPreferredSource(): void
    {
        $doi = Identifier::doi('10.5555/speckle.2024.01');
        $merged = (new Merger())->merge(
            [self::work('hal', 'Speckle traps', 2024, [$doi, Identifier::hal('hal-01234567')], ['abstract' => 'HAL abstract', 'pdfUrl' => 'https://hal.science/hal-01234567/document', 'openAccess' => true, 'date' => '2024-09-25', 'domains' => ['chim']])],
            [self::work('openalex', 'Speckle traps: towards on-demand release', 2024, [$doi, Identifier::openalex('W4402922222')], ['abstract' => 'OpenAlex abstract', 'citations' => 6, 'openAccess' => false, 'date' => '2024-01-01', 'keywords' => ['Ultracold atoms']])],
            [self::work('crossref', 'Speckle traps: towards on-demand release of stored ultracold atoms', 2024, [$doi], ['venue' => new Venue('Journal of Example Physics'), 'citations' => 5, 'keywords' => ['ultracold atoms', 'Energy']])],
        );

        self::assertCount(1, $merged);
        $work = $merged[0];
        self::assertSame('Speckle traps: towards on-demand release of stored ultracold atoms', $work->title, 'Crossref first by default');
        self::assertSame('HAL abstract', $work->abstract, 'HAL first for abstracts');
        self::assertSame(6, $work->citations, 'OpenAlex first for citations');
        self::assertSame('https://hal.science/hal-01234567/document', $work->pdfUrl);
        self::assertTrue($work->openAccess, 'open when one source says so');
        self::assertSame('Journal of Example Physics', $work->venue->name);
        self::assertSame(['doi', 'openalex', 'hal'], array_keys($work->identifiers->toArray()));
        self::assertSame(['crossref', 'openalex', 'hal'], $work->sources);
        self::assertSame(['ultracold atoms', 'Energy'], $work->keywords, 'united in the default order (Crossref first), once regardless of case');
        self::assertSame('2024-09-25', $work->date, 'Crossref has the year alone: the date from HAL, before OpenAlex\'s January 1st');
    }

    public function testPreferencesAreConfigurable(): void
    {
        $doi = Identifier::doi('10.1234/x');
        $merged = (new Merger(['default' => ['hal', 'crossref'], 'citations' => ['crossref']]))->merge(
            [self::work('crossref', 'Crossref title', 2024, [$doi], ['citations' => 5])],
            [self::work('hal', 'HAL title', 2024, [$doi], ['citations' => 9])],
        );
        self::assertSame('HAL title', $merged[0]->title);
        self::assertSame(5, $merged[0]->citations);
    }

    public function testTheOrderOfIdentifiers(): void
    {
        $merger = new Merger();
        // An arXiv id makes the preprint and the article one.
        self::assertTrue($merger->same(
            self::work('arxiv', 'Wormholes', 2023, [Identifier::arxiv('2305.01234')], ['type' => WorkType::PREPRINT]),
            self::work('inspire', 'Traversable wormholes', 2024, [Identifier::arxiv('2305.01234'), Identifier::doi('10.1007/jhep01(2024)001')]),
        ));
        // Two DOIs are two works, whatever the titles.
        self::assertFalse($merger->same(
            self::work('openalex', 'Initiation à la démarche scientifique', 2026, [Identifier::doi('10.5281/zenodo.1')], ['type' => WorkType::DATASET]),
            self::work('openalex', 'Initiation à la démarche scientifique', 2026, [Identifier::doi('10.5281/zenodo.2')], ['type' => WorkType::DATASET]),
        ));
        // A shared arXiv id does not join two different DOIs either.
        self::assertFalse($merger->same(
            self::work('inspire', 'A', 2020, [Identifier::arxiv('2001.00001'), Identifier::doi('10.1234/a')]),
            self::work('crossref', 'B', 2021, [Identifier::arxiv('2001.00001'), Identifier::doi('10.1234/b')]),
        ));
        // A HAL id.
        self::assertTrue($merger->same(
            self::work('hal', 'Un article', 2012, [Identifier::hal('hal-00000042')]),
            self::work('openalex', 'Another title', 2012, [Identifier::hal('hal-00000042'), Identifier::doi('10.1234/c')]),
        ));
        // Failing identifiers, the title and the year, accents and punctuation aside.
        self::assertTrue($merger->same(
            self::work('hal', 'Les cahiers des étudiant.es comme outil d’apprentissage', 2025),
            self::work('openalex', 'Les cahiers des etudiant es comme outil d\'apprentissage', 2025, [Identifier::doi('10.1234/z')]),
        ));
        self::assertFalse($merger->same(self::work('hal', 'Ultracold atoms', 2024), self::work('openalex', 'Ultracold atoms', 2023)));
        // A preprint and its publication a year later.
        self::assertTrue($merger->same(
            self::work('arxiv', 'The no boundary density matrix', 2024, [Identifier::arxiv('2409.12345')], ['type' => WorkType::PREPRINT]),
            self::work('crossref', 'The no boundary density matrix', 2025, [Identifier::doi('10.1007/jhep02(2025)124')]),
        ));
        // Chapters share their book's ISBN: that does not make them one.
        self::assertFalse($merger->same(
            self::work('crossref', 'Chapter one', 2016, [Identifier::isbn('9782999990098')], ['type' => WorkType::CHAPTER]),
            self::work('crossref', 'Chapter two', 2016, [Identifier::isbn('9782999990098')], ['type' => WorkType::CHAPTER]),
        ));
    }

    public function testTheMergedPreprintIsTheArticle(): void
    {
        $merged = (new Merger())->merge(
            [self::work('arxiv', 'Wormholes', 2023, [Identifier::arxiv('2305.01234')], ['type' => WorkType::PREPRINT, 'pdfUrl' => 'https://arxiv.org/pdf/2305.01234'])],
            [self::work('inspire', 'Traversable wormholes', 2024, [Identifier::arxiv('2305.01234'), Identifier::doi('10.1234/w')], ['venue' => new Venue('JHEP')])],
        );
        self::assertCount(1, $merged);
        self::assertSame(WorkType::ARTICLE, $merged[0]->type);
        self::assertSame(2024, $merged[0]->year);
        self::assertSame('https://arxiv.org/pdf/2305.01234', $merged[0]->pdfUrl);
    }

    public function testARecordBridgingTwoGroupsJoinsThem(): void
    {
        $merged = (new Merger())->merge([
            self::work('hal', 'One', 2020, [Identifier::hal('hal-01234567')]),
            self::work('arxiv', 'Two', 2020, [Identifier::arxiv('2001.00002')], ['type' => WorkType::PREPRINT]),
            self::work('openalex', 'Three', 2020, [Identifier::hal('hal-01234567'), Identifier::arxiv('2001.00002')]),
        ]);
        self::assertCount(1, $merged);
        self::assertSame(['openalex', 'hal', 'arxiv'], $merged[0]->sources);
    }

    public function testTheEditionsOfABookAreGrouped(): void
    {
        $book = static fn (string $source, string $title, int $year, array $isbns, array $fields = []) => self::work($source, $title, $year, array_map(Identifier::isbn(...), $isbns), $fields + ['type' => WorkType::BOOK, 'authors' => [Contributor::fromName('Jeanne Exemple')]]);
        $merged = (new Merger())->merge(
            [
                $book('openlibrary', 'Sowing the seeds of geometry, PreK-2', 2012, ['9782999990098'], ['cover' => 'https://covers.openlibrary.org/b/id/90000004-L.jpg']),
                $book('openlibrary', 'Sowing the seeds of geometry, PreK-2', 2012, ['299999009X']),
                $book('openlibrary', 'Sowing the Seeds of Geometry, PreK-2', 2016, ['9782999990067'], ['subtitle' => 'Explorations for the Early Grades', 'authors' => [Contributor::fromName('Jeanne M. Exemple')]]),
                $book('openlibrary', 'Sowing the Seeds of Geometry, 3-5', 2014, ['9782999990081']),
                $book('openlibrary', 'Méthode Exemple CE1 - Fichier de l\'élève 1', 2020, ['9782999990012']),
                $book('openlibrary', 'Méthode Exemple CE1 - Fichier de l\'élève 2', 2020, ['9782999990029']),
            ],
            [$book('crossref', 'Sowing the Seeds of Geometry, PreK-2: Explorations for the Early Grades', 2012, ['9782999990098', '9782999990104'], ['identifiers' => Identifiers::of(Identifier::doi('10.5555/9782999990104'), Identifier::isbn('9782999990098'), Identifier::isbn('9782999990104'))])],
        );

        self::assertCount(4, $merged, 'PreK-2, 3-5, and the two volumes of the Méthode');
        $preK2 = self::titled($merged, 'Sowing the Seeds of Geometry, PreK-2');
        self::assertSame('Sowing the Seeds of Geometry, PreK-2', $preK2->title, 'the newest edition\'s');
        self::assertSame(2016, $preK2->year);
        self::assertCount(2, $preK2->editions);
        [$second, $first] = $preK2->editions;
        self::assertSame(['9782999990067'], $second->isbns());
        self::assertSame(2012, $first->year);
        self::assertSame('10.5555/9782999990104', $first->doi(), 'Crossref\'s record and Open Library\'s two, one edition');
        self::assertSame(['crossref', 'openlibrary'], $first->sources);
        self::assertSame('https://covers.openlibrary.org/b/id/90000004-L.jpg', $first->cover, 'Open Library first for covers');
        self::assertEqualsCanonicalizing(['9782999990067', '9782999990098', '9782999990104'], $preK2->isbns());
        self::assertSame('https://covers.openlibrary.org/b/id/90000004-L.jpg', $preK2->cover, 'the group takes a cover from an edition');

        // Merged again (a weekly sync), the group is taken apart and grouped the same.
        $again = (new Merger())->merge($merged);
        self::assertCount(4, $again);
        self::assertCount(2, self::titled($again, 'Sowing the Seeds of Geometry, PreK-2')->editions);

        self::assertCount(5, (new Merger([], false))->merge($merged), 'editions left apart when asked');
    }

    public function testAnOrcidIsLentToTheSameName(): void
    {
        $doi = Identifier::doi('10.1234/n');
        $merged = (new Merger())->merge(
            [self::work('crossref', 'X', 2024, [$doi], ['authors' => [Contributor::fromName('Paul Martin'), Contributor::fromName('Jeanne Exemple')]])],
            [self::work('openalex', 'X', 2024, [$doi], ['authors' => [new Contributor('Jeanne Exemple', 'Jeanne', 'Exemple', Identifiers::of(Identifier::orcid('0009-0000-0000-0017'), Identifier::openalex('A5000000001')))]])],
        );
        self::assertSame('Paul Martin', $merged[0]->authors[0]->name, 'Crossref\'s list');
        self::assertSame('0009-0000-0000-0017', $merged[0]->authors[1]->orcid(), 'with the ORCID OpenAlex knows');
    }

    public function testNewestFirst(): void
    {
        $merged = (new Merger())->merge([
            self::work('hal', 'Old', 2010),
            self::work('hal', 'Undated', null),
            self::work('hal', 'New', 2024, [], ['date' => '2024-09-25']),
            self::work('hal', 'Newer', 2024, [], ['date' => '2024-10-04']),
        ]);
        self::assertSame(['Newer', 'New', 'Old', 'Undated'], array_map(static fn (Work $w) => $w->title, $merged));
    }

    /** @param list<Work> $works */
    private static function titled(array $works, string $title): Work
    {
        foreach ($works as $work) {
            if ($title === $work->title) {
                return $work;
            }
        }
        self::fail($title.' not found');
    }

    public function testFingerprint(): void
    {
        self::assertSame('methode exemple ce1 fichier de l eleve 1', Merger::fingerprint('Méthode Exemple CE1  - Fichier de l\'élève 1'));
        self::assertSame('the large n limit', Merger::fingerprint('The Large <i>N</i> Limit'));
    }
}
