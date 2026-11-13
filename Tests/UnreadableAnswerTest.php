<?php

namespace Omnischolar\Tests;

use Omnischolar\Collector;
use Omnischolar\Exception\ProviderException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Registry;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceFactoryInterface;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A 2xx answer that does not parse: OpenAlex once sent a page of works cut inside a string, which
 * PHP reads as "Control character error, possibly incorrectly encoded" (the end of the input
 * where a string goes on). No answer to read is an UnavailableException saying what came - the
 * status, the content type, the length, the first and last bytes -, and a Collector skips that
 * source while the others still answer.
 */
final class UnreadableAnswerTest extends TestCase
{
    /** A page as OpenAlex writes one, cut inside a title. */
    private const CUT = '{"meta":{"count":383,"db_response_time_ms":41,"page":2,"per_page":100},"results":[{"id":"https://openalex.org/W1","title":"Ultracold atoms in disordered potentials","publication_year":2014},{"id":"https://openalex.org/W2","title":"Expansion of a matter wave pa';

    public function testABodyCutShortIsUnavailableAndSaysWhatCame(): void
    {
        try {
            self::source(new MockResponse(self::CUT, ['response_headers' => ['content-type: application/json']]))->works('A1');
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertSame(200, $e->status);
            self::assertSame('list', $e->source);
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
            self::assertStringStartsWith('[list] the answer is not JSON (Control character error, possibly incorrectly encoded): HTTP 200, application/json, '.\strlen(self::CUT).' bytes, cut short?, begins "{"meta":{"count":383,', $e->getMessage());
            self::assertStringEndsWith('ends "'.substr(self::CUT, -60).'"', $e->getMessage(), 'where it stops');
        }
    }

    public function testAnHtmlPageIsUnavailableAndSaysWhatCame(): void
    {
        try {
            self::source(new MockResponse("<html>\n<title>Maintenance</title></html>", ['response_headers' => ['content-type: text/html']]))->works('A1');
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertSame('[list] the answer is not JSON (Syntax error): HTTP 200, text/html, 40 bytes, "<html>\\x0A<title>Maintenance</title></html>"', $e->getMessage(), 'a new line written \\x0A');
        }
    }

    public function testBytesThatAreNotTextAreWrittenAsBytes(): void
    {
        try {
            self::source(new MockResponse("\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\x03\xab\xae\x05\x00"))->works('A1');
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertStringEndsWith('HTTP 200, no content type, 14 bytes, "\\x1F\\x8B\\x08\\x00\\x00\\x00\\x00\\x00\\x00\\x03\\xAB\\xAE\\x05\\x00"', $e->getMessage(), 'a gzip body nobody decoded');
        }
    }

    public function testAnEmptyBody(): void
    {
        $this->expectExceptionMessage('[list] the answer is not JSON (Syntax error): HTTP 200, application/json, 0 bytes, empty');
        self::source(new MockResponse('', ['response_headers' => ['content-type: application/json']]))->works('A1');
    }

    public function testWhatIsSaidOfTheBodyKeepsTheKeyOut(): void
    {
        try {
            self::source(new MockResponse('<p>Bad request: api_key=scholar-key-1234567 &amp; more</p>'))->works('A1');
            self::fail('unavailable');
        } catch (UnavailableException $e) {
            self::assertStringNotContainsString('scholar-key-1234567', $e->getMessage());
            self::assertStringContainsString('api_key=***', $e->getMessage());
        }
    }

    public function testJsonThatIsNotAnObjectStaysAProviderError(): void
    {
        try {
            self::source(new MockResponse('"busy"', ['response_headers' => ['content-type: application/json']]))->works('A1');
            self::fail('a provider error');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnavailableException::class, $e, 'an answer, of the wrong shape');
            self::assertSame('[list] the answer is not a JSON object: HTTP 200, application/json, 6 bytes, ""busy""', $e->getMessage());
        }
    }

    public function testTheCollectorSkipsTheSourceWhoseAnswerIsCutAndKeepsTheOthers(): void
    {
        $answers = [
            'good' => new MockHttpClient([
                new MockResponse('{"results":[{"title":"Un"},{"title":"Deux"}],"next":"2"}'),
                new MockResponse('{"results":[{"title":"Trois"}],"next":null}'),
            ]),
            'cut' => new MockHttpClient([
                new MockResponse('{"results":[{"title":"Quatre"}],"next":"2"}'),
                new MockResponse(self::CUT, ['response_headers' => ['content-type: application/json']]),
            ]),
        ];
        $factory = new class($answers) implements SourceFactoryInterface {
            /** @param array<string, HttpClientInterface> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function getName(): string
            {
                return 'list';
            }

            public function create(array $options = []): SourceInterface
            {
                $name = \is_string($options['omnischolar.name'] ?? null) ? $options['omnischolar.name'] : 'list';

                return UnreadableAnswerTest::source($this->answers[$name], $name);
            }
        };
        $collector = new Collector(new Registry([$factory], ['good' => ['factory' => 'list'], 'cut' => ['factory' => 'list']]));

        $works = $collector->collect(['good' => 'A1', 'cut' => 'A2']);

        $titles = array_map(static fn (Work $work): string => $work->title, $works);
        sort($titles);
        self::assertSame(['Deux', 'Trois', 'Un'], $titles, 'the source that answered, every page; nothing of the one cut');
        self::assertSame(['cut'], array_keys($collector->incomplete));
        self::assertStringStartsWith('[cut] the answer is not JSON (Control character error, possibly incorrectly encoded): HTTP 200, application/json', $collector->incomplete['cut']);
    }

    /** A source listing works from {"results": [{"title": ...}], "next": ...}. */
    public static function source(MockResponse|HttpClientInterface $answer, string $name = 'list'): HttpSource
    {
        $http = $answer instanceof MockResponse ? new MockHttpClient($answer) : $answer;

        return new class($http, $name) extends HttpSource {
            public function __construct(HttpClientInterface $http, private readonly string $name)
            {
                parent::__construct($http, 'https://list.example.org/');
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function capabilities(): array
            {
                return [Capability::WORKS];
            }

            public function works(Identifier|string $author, ?Query $query = null): Page
            {
                $data = $this->getJson('works', ['author' => (string) $author, 'cursor' => $query?->cursor, 'api_key' => 'scholar-key-1234567']) ?? [];
                $works = [];
                foreach (\is_array($data['results'] ?? null) ? $data['results'] : [] as $result) {
                    $works[] = new Work(\is_array($result) && \is_string($result['title'] ?? null) ? $result['title'] : '', WorkType::ARTICLE, year: 2024, source: $this->name);
                }

                return new Page($works, \is_string($data['next'] ?? null) ? $data['next'] : null);
            }
        };
    }
}
