<?php

namespace Omnischolar\Tests;

use Omnischolar\Export;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    private static function article(): Work
    {
        return new Work(
            title: 'Speckle traps: towards on-demand release of stored ultracold atoms',
            type: WorkType::ARTICLE,
            authors: [new Contributor('Paul Martin', 'Paul', 'Martin'), new Contributor('Jeanne Exemple', 'Jeanne', 'Exemple', Identifiers::of(Identifier::orcid('0009-0000-0000-0017')))],
            year: 2024,
            date: '2024-09-25',
            venue: new Venue('Journal of Example Physics', Venue::JOURNAL, ['1234-5679'], 'Exemple Press', 'J. Ex. Phys.'),
            volume: '15',
            issue: '39',
            pages: '16034-16039',
            abstract: 'Ultracold atoms stored & release.',
            identifiers: Identifiers::of(Identifier::doi('10.5555/speckle.2024.01'), Identifier::hal('hal-01234567')),
            url: 'https://journal.example.org/article/15/39/16034-16039',
        );
    }

    private static function book(): Work
    {
        return new Work(
            title: 'Sowing the Seeds of Geometry, PreK-2',
            subtitle: 'Explorations for the Early Grades',
            type: WorkType::BOOK,
            authors: [Contributor::fromName('Jeanne Exemple')],
            year: 2012,
            publisher: 'Exemple',
            identifiers: Identifiers::of(Identifier::isbn('299999009X'), Identifier::doi('10.5555/9782999990104')),
        );
    }

    private static function preprint(): Work
    {
        return new Work(
            title: 'Logarithmic gravity from a very slow roll limit of inflation',
            type: WorkType::PREPRINT,
            authors: [Contributor::fromName('Jordan Cotler'), Contributor::fromName('Juan Maldacena')],
            year: 2026,
            date: '2026-09-29',
            venue: new Venue('arXiv', Venue::REPOSITORY),
            domains: ['hep-th', 'gr-qc'],
            identifiers: Identifiers::of(Identifier::arxiv('2609.38052')),
        );
    }

    public function testBibtex(): void
    {
        $bibtex = (new Export())->bibtex([self::article(), self::book(), self::preprint()]);

        self::assertSame(<<<'BIB'
            @article{martin2024speckle,
              author = {Martin, Paul and Exemple, Jeanne},
              title = {{Speckle traps: towards on-demand release of stored ultracold atoms}},
              journal = {Journal of Example Physics},
              year = {2024},
              month = {sep},
              volume = {15},
              number = {39},
              pages = {16034--16039},
              doi = {10.5555/speckle.2024.01},
              issn = {1234-5679},
              hal_id = {hal-01234567},
              url = {https://journal.example.org/article/15/39/16034-16039}
            }

            @book{exemple2012sowing,
              author = {Exemple, Jeanne},
              title = {{Sowing the Seeds of Geometry, PreK-2: Explorations for the Early Grades}},
              year = {2012},
              publisher = {Exemple},
              doi = {10.5555/9782999990104},
              isbn = {9782999990098}
            }

            @misc{cotler2026logarithmic,
              author = {Cotler, Jordan and Maldacena, Juan},
              title = {{Logarithmic gravity from a very slow roll limit of inflation}},
              year = {2026},
              month = {sep},
              eprint = {2609.38052},
              archiveprefix = {arXiv},
              primaryclass = {hep-th}
            }

            BIB, $bibtex);
    }

    public function testBibtexEscapesAndKeysAreUnique(): void
    {
        $export = new Export(abstracts: true);
        $bibtex = $export->bibtex([self::article(), self::article()]);
        self::assertStringContainsString('@article{martin2024speckle,', $bibtex);
        self::assertStringContainsString('@article{martin2024specklea,', $bibtex);
        self::assertStringContainsString('abstract = {Ultracold atoms stored \& release.}', $bibtex);
        self::assertSame('anonymous2024untitled', $export->citationKey(new Work('', year: 2024)));
    }

    public function testAGroupedBookIsCitedByItsNewestEdition(): void
    {
        $older = self::book();
        $newer = new Work('Sowing the Seeds of Geometry, PreK-2', WorkType::BOOK, [Contributor::fromName('Jeanne M. Exemple')], 2016, publisher: 'Éditions Exemple', identifiers: Identifiers::of(Identifier::isbn('9782999990067')));
        $group = $newer->with(['editions' => [$newer, $older], 'identifiers' => $newer->identifiers->merge($older->identifiers)]);

        $bibtex = (new Export())->bibtex($group);
        self::assertStringContainsString('isbn = {9782999990067}', $bibtex);
        self::assertStringNotContainsString('doi =', $bibtex, 'not the 2012 edition\'s DOI');
    }

    public function testRis(): void
    {
        $ris = (new Export())->ris([self::article(), self::book()]);

        self::assertSame(implode("\r\n", [
            'TY  - JOUR',
            'ID  - martin2024speckle',
            'AU  - Martin, Paul',
            'AU  - Exemple, Jeanne',
            'TI  - Speckle traps: towards on-demand release of stored ultracold atoms',
            'T2  - Journal of Example Physics',
            'J2  - J. Ex. Phys.',
            'PY  - 2024',
            'DA  - 2024/09/25',
            'VL  - 15',
            'IS  - 39',
            'SP  - 16034',
            'EP  - 16039',
            'PB  - Exemple Press',
            'SN  - 1234-5679',
            'DO  - 10.5555/speckle.2024.01',
            'UR  - https://journal.example.org/article/15/39/16034-16039',
            'ER  -',
            '',
            'TY  - BOOK',
            'ID  - exemple2012sowing',
            'AU  - Exemple, Jeanne',
            'TI  - Sowing the Seeds of Geometry, PreK-2: Explorations for the Early Grades',
            'PY  - 2012',
            'PB  - Exemple',
            'SN  - 9782999990098',
            'DO  - 10.5555/9782999990104',
            'UR  - https://doi.org/10.5555/9782999990104',
            'ER  -',
        ])."\r\n", $ris);
    }

    public function testCslJson(): void
    {
        $export = new Export();
        $items = $export->csl([self::article(), self::book(), self::preprint()]);

        self::assertSame([
            'id' => 'martin2024speckle',
            'type' => 'article-journal',
            'title' => 'Speckle traps: towards on-demand release of stored ultracold atoms',
            'author' => [['family' => 'Martin', 'given' => 'Paul'], ['family' => 'Exemple', 'given' => 'Jeanne']],
            'container-title' => 'Journal of Example Physics',
            'container-title-short' => 'J. Ex. Phys.',
            'publisher' => 'Exemple Press',
            'issued' => ['date-parts' => [[2024, 9, 25]]],
            'volume' => '15',
            'issue' => '39',
            'page' => '16034-16039',
            'DOI' => '10.5555/speckle.2024.01',
            'ISSN' => '1234-5679',
            'URL' => 'https://journal.example.org/article/15/39/16034-16039',
        ], $items[0]);
        self::assertSame('book', $items[1]['type']);
        self::assertSame('9782999990098', $items[1]['ISBN']);
        self::assertSame(['date-parts' => [[2012]]], $items[1]['issued']);
        self::assertSame(['article', '2609.38052', 'Preprint'], [$items[2]['type'], $items[2]['number'], $items[2]['genre']]);

        $json = $export->format('csl-json', self::article());
        self::assertSame('Paul', json_decode($json, true)[0]['author'][0]['given']);
        self::assertSame('application/x-bibtex; charset=utf-8', Export::contentType('bibtex'));
        $this->expectException(\InvalidArgumentException::class);
        $export->format('endnote', []);
    }
}
