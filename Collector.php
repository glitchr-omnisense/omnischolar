<?php

namespace Omnischolar;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Work;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;

/**
 * A researcher's works from every source that knows them, page after page,
 * merged into one list:
 *
 *   $works = $collector->collect(['openalex' => 'A5046427077', 'hal' => 'Alain Aspect', 'crossref' => '0000-0003-0094-5584']);
 *   $collector->incomplete;   // ['hal' => 'unreachable: ...'] the sources skipped: do not cache that answer
 *
 * A source that is down, rate limited, or cannot read the identifier given
 * is skipped and named in $incomplete; the others still answer.
 */
final class Collector
{
    /** @var array<string, string> the sources skipped during the last collect(), and why */
    public array $incomplete = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly Merger $merger = new Merger(),
    ) {
    }

    /**
     * @param array<string, Identifier|string|list<Identifier|string>> $profiles the configured source's name => the author there (one or several)
     * @param int                                                       $max      the most works read from one source
     *
     * @return list<Work> merged, newest first
     */
    public function collect(array $profiles, ?Query $query = null, int $max = 2000): array
    {
        $this->incomplete = [];
        $lists = [];
        foreach ($profiles as $name => $authors) {
            foreach (\is_array($authors) ? $authors : [$authors] as $author) {
                try {
                    $lists[] = self::all($this->registry->get($name), $author, $query, $max);
                } catch (UnavailableException|NotSupportedException $e) {
                    $this->incomplete[$name] = $e->getMessage();
                }
            }
        }

        return $this->merger->merge(...$lists);
    }

    /**
     * Every page of an author's works in one list.
     *
     * @return list<Work>
     */
    public static function all(SourceInterface $source, Identifier|string $author, ?Query $query = null, int $max = 2000): array
    {
        $query ??= new Query(limit: 100);
        $works = [];
        do {
            $page = $source->works($author, $query);
            array_push($works, ...$page->works);
            $query = $query->with(['cursor' => $page->next]);
        } while (null !== $page->next && [] !== $page->works && \count($works) < $max);

        return \array_slice($works, 0, $max);
    }
}
