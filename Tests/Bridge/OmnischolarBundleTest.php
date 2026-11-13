<?php

namespace Omnischolar\Tests\Bridge;

use Composer\InstalledVersions;
use Omnischolar\Bridge\Symfony\OmnischolarBundle;
use Omnischolar\Collector;
use Omnischolar\Export;
use Omnischolar\Merger;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Work;
use Omnischolar\Registry;
use Omnischolar\Source\SourceInterface;
use Omnischolar\Tests\StubSource;
use Omnischolar\Tests\StubSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;

final class OmnischolarBundleTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        StubSource::$log = [];
    }

    protected function tearDown(): void
    {
        if ($this->kernel) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testAKernelBootsWithTheSourcesByName(): void
    {
        $this->kernel = new OmnischolarTestKernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $registry = $container->get(Registry::class);
        self::assertSame(['hal', 'openalex'], $registry->names());
        // The source packages are optional: those installed beside this one (all of them in the harness).
        foreach (['openalex', 'hal', 'crossref', 'orcid', 'openlibrary', 'arxiv', 'inspire'] as $factory) {
            if (InstalledVersions::isInstalled('omnischolar/'.$factory)) {
                self::assertContains($factory, $registry->factories(), 'the packages installed are registered');
            }
        }
        self::assertContains('stub', $registry->factories(), 'the application\'s own, autoconfigured');

        $site = $container->get(Publications::class);
        self::assertSame('hal', $site->hal->getName(), 'one source by its argument name');
        self::assertSame('openalex', $site->openalex->getName());

        $works = $site->collector->collect(['hal' => 'someone', 'openalex' => Identifier::openalex('A5046427077')]);
        self::assertSame(['Un', 'Deux'], array_map(static fn (Work $w) => $w->title, $works));
        self::assertSame(['hal', 'openalex'], array_values(array_filter($works, static fn (Work $w) => 'Deux' === $w->title))[0]->sources, 'the configured preferences: hal first');
        self::assertStringContainsString('abstract = {', $site->export->bibtex(new Work('T', abstract: 'A', year: 2024)), 'abstracts exported, as configured');
    }
}

final class Publications
{
    public function __construct(
        public readonly SourceInterface $hal,
        public readonly SourceInterface $openalex,
        public readonly Collector $collector,
        public readonly Merger $merger,
        public readonly Export $export,
    ) {
    }
}

final class OmnischolarTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new OmnischolarBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register('http_client', MockHttpClient::class);
            $container->register(StubSourceFactory::class)->setAutoconfigured(true);
            $container->register(Publications::class)->setAutowired(true)->setPublic(true);
            $container->loadFromExtension('omnischolar', [
                'sources' => [
                    'hal' => ['factory' => 'stub', 'options' => ['name' => 'hal', 'titles' => ['Un', 'Deux']]],
                    'openalex' => ['factory' => 'stub', 'options' => ['name' => 'openalex', 'titles' => ['Deux']]],
                ],
                'merger' => ['preferences' => ['default' => ['hal', 'openalex']]],
                'export' => ['abstracts' => true],
            ]);
        });
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/omnischolar-bundle-test-'.getmypid();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }
}
