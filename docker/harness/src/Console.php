<?php

namespace Omnischolar\Harness;

use Omnischolar\Collector;
use Omnischolar\Exception\InvalidConfigException;
use Omnischolar\Exception\OmnischolarException;
use Omnischolar\Export;
use Omnischolar\Merger;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Registry;
use Omnischolar\Source\Capability;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceFactoryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The console that asks every source, for real: sources, author, works,
 * work, search, merge. A source that cannot be reached is skipped, and
 * said so on stderr.
 */
final class Console
{
    /** @var array<string, array{factory: string, needs: list<string>, options: array<string, mixed>}> */
    private array $config;

    /** @var array<string, SourceFactoryInterface> */
    private array $factories = [];

    private Registry $registry;

    private function __construct()
    {
        $this->config = require __DIR__.'/../config/sources.php';
        $http = HttpClient::create(['timeout' => 60]);
        foreach (require __DIR__.'/../plugins.php' as [, $class]) {
            if (class_exists($class)) {
                $factory = new $class($http);
                $this->factories[$factory->getName()] = $factory;
            }
        }
        $usable = array_filter($this->config, fn (array $c) => isset($this->factories[$c['factory']]) && !$this->missing($c));
        $this->registry = new Registry($this->factories, array_map(static fn (array $c) => ['factory' => $c['factory'], 'options' => array_filter($c['options'], static fn ($v) => null !== $v)], $usable));
    }

    public static function create(): Application
    {
        $self = new self();
        $source = new InputArgument('source', InputArgument::REQUIRED, 'A configured source: openalex, hal, droit (HAL on shs.droit), crossref, orcid, openlibrary, arxiv, inspire');
        $filters = [
            new InputOption('from', null, InputOption::VALUE_REQUIRED, 'From that year'),
            new InputOption('to', null, InputOption::VALUE_REQUIRED, 'Up to that year'),
            new InputOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only that kind: '.implode(', ', array_map(static fn (WorkType $t) => $t->value, WorkType::cases()))),
            new InputOption('domain', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A subject domain: a HAL domain (shs.droit), an arXiv category (hep-th)'),
            new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'newest, oldest, relevance, cited', Query::NEWEST),
            new InputOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Works per page', '50'),
        ];
        $output = [
            new InputOption('max', 'm', InputOption::VALUE_REQUIRED, 'The most works read (pages are followed up to it)', '200'),
            new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'table, json, '.implode(', ', Export::FORMATS), 'table'),
        ];
        $app = new Application('omnischolar', '1.x');
        $app->addCommand($self->command('sources', 'Which sources are installed, configured, and what each can do', [], fn ($in, $out) => $self->sources($out)));
        $app->addCommand($self->command('author', 'A researcher\'s profile in one source (JSON)', [$source, new InputArgument('author', InputArgument::REQUIRED, 'An identifier (ORCID, OpenAlex A..., idHAL, BAI, OL...A) or a name')], fn ($in, $out) => $self->author($in, $out)));
        $app->addCommand($self->command('works', 'An author\'s works in one source, every page up to --max', [$source, new InputArgument('author', InputArgument::REQUIRED, 'An identifier or, where the source searches by name, a name'), ...$filters, ...$output], fn ($in, $out) => $self->works($in, $out)));
        $app->addCommand($self->command('work', 'One work by an identifier (JSON)', [$source, new InputArgument('id', InputArgument::REQUIRED, 'A DOI, ISBN, arXiv, HAL, OpenAlex, INSPIRE, Open Library id...')], fn ($in, $out) => $self->work($in, $out)));
        $app->addCommand($self->command('search', 'Works matching a text in one source', [$source, new InputArgument('text', InputArgument::OPTIONAL, 'Free text (INSPIRE: its own query language)'), new InputOption('title', null, InputOption::VALUE_REQUIRED), new InputOption('author', 'a', InputOption::VALUE_REQUIRED), ...$filters, ...$output], fn ($in, $out) => $self->search($in, $out)));
        $app->addCommand($self->command('merge', 'An author\'s works from several sources, merged: editions grouped, each field from its preferred source', [
            new InputArgument('profiles', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'source:author pairs: openalex:A5046427077 hal:"Alain Aspect" openlibrary:"Alain Aspect"'),
            ...$filters, ...$output,
            new InputOption('no-editions', null, InputOption::VALUE_NONE, 'Leave the editions of a book apart'),
        ], fn ($in, $out) => $self->merge($in, $out)));

        return $app;
    }

    /** @param list<InputArgument|InputOption> $definition */
    private function command(string $name, string $description, array $definition, \Closure $code): Command
    {
        $command = new Command($name);
        $command->setDescription($description)->setDefinition($definition);
        $command->setCode(function (InputInterface $in, OutputInterface $out) use ($code): int {
            try {
                return $code($in, $out) ?? Command::SUCCESS;
            } catch (InvalidConfigException|\InvalidArgumentException|\ValueError $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::INVALID;
            } catch (OmnischolarException $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::FAILURE;
            }
        });

        return $command;
    }

    private function sources(OutputInterface $out): void
    {
        $table = new Table($out);
        $table->setHeaders(['Source', 'Factory', 'Installed', 'Configured', 'Can', 'Options']);
        foreach ($this->config as $name => $source) {
            $installed = isset($this->factories[$source['factory']]);
            $missing = $this->missing($source);
            $can = $installed && !$missing ? implode(', ', array_map(static fn (Capability $c) => $c->value, $this->registry->get($name)->capabilities())) : '';
            $options = [];
            foreach ($source['options'] as $key => $value) {
                $options[] = $key.': '.(null === $value ? '-' : (str_contains($key, 'key') ? substr((string) $value, 0, 4).'…' : (\is_array($value) ? implode(', ', $value) : (string) $value)));
            }
            $table->addRow([$name, $source['factory'], $installed ? '<info>yes</info>' : '<comment>no</comment>', $missing ? '<comment>needs '.implode(', ', $missing).'</comment>' : ($installed ? '<info>yes</info>' : ''), $can, implode(', ', $options)]);
        }
        $table->render();
        $out->writeln('Factories installed: '.(implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    private function author(InputInterface $in, OutputInterface $out): int
    {
        $author = $this->registry->get((string) $in->getArgument('source'))->author($this->identifier((string) $in->getArgument('author')));
        if (null === $author) {
            $this->stderr($out)->writeln('<comment>Not found.</comment>');

            return Command::FAILURE;
        }
        $out->writeln(self::json($author));

        return Command::SUCCESS;
    }

    private function works(InputInterface $in, OutputInterface $out): void
    {
        $name = (string) $in->getArgument('source');
        $works = Collector::all($this->registry->get($name), $this->identifier((string) $in->getArgument('author')), $this->query($in), $this->max($in));
        $this->render($in, $out, $works);
        $this->stderr($out)->writeln(\sprintf('<info>%d works from %s.</info>', \count($works), $name));
    }

    private function work(InputInterface $in, OutputInterface $out): int
    {
        $work = $this->registry->get((string) $in->getArgument('source'))->work($this->identifier((string) $in->getArgument('id')));
        if (null === $work) {
            $this->stderr($out)->writeln('<comment>Not found.</comment>');

            return Command::FAILURE;
        }
        $out->writeln(self::json($work));

        return Command::SUCCESS;
    }

    private function search(InputInterface $in, OutputInterface $out): void
    {
        $source = $this->registry->get((string) $in->getArgument('source'));
        $query = $this->query($in)->with(['text' => $in->getArgument('text'), 'title' => $in->getOption('title'), 'author' => $in->getOption('author')]);
        $page = $source->search($query);
        $this->render($in, $out, \array_slice($page->works, 0, $this->max($in)));
        $this->stderr($out)->writeln(\sprintf('<info>%d of %s.</info>', \count($page->works), $page->total ?? '?'));
    }

    private function merge(InputInterface $in, OutputInterface $out): void
    {
        $profiles = [];
        foreach ((array) $in->getArgument('profiles') as $pair) {
            [$source, $author] = explode(':', (string) $pair, 2) + [1 => ''];
            if ('' === $author) {
                throw new \InvalidArgumentException(\sprintf('"%s": a profile is source:author (openalex:A5046427077).', $pair));
            }
            $profiles[$source][] = $this->identifier($author);
        }
        $collector = new Collector($this->registry, new Merger([], !$in->getOption('no-editions')));
        $works = $collector->collect($profiles, $this->query($in), $this->max($in));
        foreach ($collector->incomplete as $name => $why) {
            $this->stderr($out)->writeln(\sprintf('<comment>Incomplete: "%s" skipped (%s); the answer is the other sources\'.</comment>', $name, $why));
        }
        $this->render($in, $out, $works);
        $this->stderr($out)->writeln(\sprintf('<info>%d works, merged from %s.</info>', \count($works), implode(', ', array_keys($profiles))));
    }

    private function query(InputInterface $in): Query
    {
        $year = static fn (?string $v): ?int => null === $v || '' === $v ? null : (int) $v;

        return new Query(
            from: $year($in->getOption('from')),
            to: $year($in->getOption('to')),
            types: array_map(static fn (string $t) => WorkType::from($t), (array) $in->getOption('type')),
            domains: array_values((array) $in->getOption('domain')),
            limit: max(1, (int) $in->getOption('limit')),
            sort: (string) $in->getOption('sort'),
        );
    }

    private function max(InputInterface $in): int
    {
        return max(1, (int) $in->getOption('max'));
    }

    /** An identifier when the string reads as one, the string itself otherwise (a name, an idHAL, a BAI...). */
    private function identifier(string $value): Identifier|string
    {
        return Identifier::parse($value) ?? $value;
    }

    /** @param list<Work> $works */
    private function render(InputInterface $in, OutputInterface $out, array $works): void
    {
        $format = (string) $in->getOption('format');
        if ('json' === $format) {
            $out->writeln(self::json($works));

            return;
        }
        if (\in_array($format, Export::FORMATS, true)) {
            $out->write((new Export())->format($format, $works));

            return;
        }
        $table = new Table($out);
        $table->setHeaders(['Date', 'Type', 'Title', 'Venue / publisher', 'Identifiers', 'Cited', 'Sources']);
        foreach ($works as $work) {
            $table->addRow([$work->date ?? $work->year, $work->type->value, self::cut($work->fullTitle(), 70), self::cut($work->venue?->name ?? $work->publisher ?? '', 32), self::ids($work->identifiers), $work->citations, implode(', ', $work->sources ?: [$work->source])]);
            foreach ($work->editions as $edition) {
                $table->addRow(['', '', '  edition '.($edition->date ?? $edition->year).($edition->edition ? ' ('.$edition->edition.')' : ''), self::cut($edition->publisher ?? '', 32), self::ids($edition->identifiers), '', implode(', ', $edition->sources)]);
            }
        }
        $table->render();
    }

    private static function ids(Identifiers $identifiers): string
    {
        $shown = [];
        foreach ($identifiers as $identifier) {
            if (\in_array($identifier->scheme->value, ['doi', 'isbn', 'arxiv', 'hal'], true)) {
                $shown[] = $identifier->key();
            }
        }

        return self::cut(implode(' ', $shown), 60);
    }

    private static function cut(string $text, int $width): string
    {
        return mb_strlen($text) > $width ? mb_substr($text, 0, $width - 1).'…' : $text;
    }

    /** @param array{needs: list<string>} $source */
    private function missing(array $source): array
    {
        return array_values(array_filter($source['needs'], static fn (string $key) => false === getenv($key) || '' === getenv($key)));
    }

    private function stderr(OutputInterface $out): OutputInterface
    {
        return $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode(self::normalize($value), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** Models as JSON shows them: identifiers by scheme, enums as their value. */
    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Identifiers => $value->toArray(),
            $value instanceof \BackedEnum => $value->value,
            \is_object($value) => array_map(self::normalize(...), get_object_vars($value)),
            \is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }
}
