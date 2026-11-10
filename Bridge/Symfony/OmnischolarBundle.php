<?php

namespace Omnischolar\Bridge\Symfony;

use Omnischolar\Arxiv\ArxivSourceFactory;
use Omnischolar\Collector;
use Omnischolar\Crossref\CrossrefSourceFactory;
use Omnischolar\Export;
use Omnischolar\Hal\HalSourceFactory;
use Omnischolar\Inspire\InspireSourceFactory;
use Omnischolar\Merger;
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\OpenLibrary\OpenLibrarySourceFactory;
use Omnischolar\Orcid\OrcidSourceFactory;
use Omnischolar\Registry;
use Omnischolar\Source\SourceFactoryInterface;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

// The bundle is the only class of the package that needs a framework: symfony/http-kernel,
// symfony/dependency-injection and symfony/config are suggested, not required.
if (!class_exists(AbstractBundle::class)) {
    throw new \LogicException('Omnischolar\Bridge\Symfony\OmnischolarBundle needs symfony/http-kernel, symfony/dependency-injection and symfony/config (composer require symfony/http-kernel symfony/dependency-injection symfony/config); the rest of omnisense/omnischolar needs no framework.');
}

/**
 * Omnischolar in a Symfony application: the source packages installed
 * (omnischolar/openalex, omnischolar/hal...) registered, the sources built
 * from configuration and injectable by their name, the Merger with the
 * site's preferences, the Collector and the Export:
 *
 *     omnischolar:
 *         sources:
 *             openalex: { factory: openalex, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
 *             hal: { factory: hal }
 *             droit: { factory: hal, options: { domains: [shs.droit] } }
 *             openlibrary: { factory: openlibrary, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
 *         merger:
 *             preferences:
 *                 default: [crossref, openalex, hal]
 *                 abstract: [hal, openalex]
 *             editions: true
 *         export:
 *             abstracts: false
 *
 *     public function __construct(SourceInterface $openalex, Collector $collector, Export $export) {}
 *
 * An application's own factories (a SourceFactoryInterface) are registered
 * too, autoconfigured. A source's "policy" and "usage" tighten its usage
 * policy and say what the site is (Omnischolar\Registry); "limiter" names a
 * service (an omnisense/contracts RateLimiterInterface, omnibridge/symfony's
 * over symfony/rate-limiter) where every process counts, the registry's own
 * in-memory one otherwise.
 *
 * @suggested symfony/http-kernel (the Symfony bundle: symfony/dependency-injection and symfony/config with it)
 */
final class OmnischolarBundle extends AbstractBundle
{
    protected string $extensionAlias = 'omnischolar';

    /** The source packages this bundle knows, registered when installed. */
    private const FACTORIES = [
        OpenAlexSourceFactory::class,
        HalSourceFactory::class,
        CrossrefSourceFactory::class,
        OrcidSourceFactory::class,
        OpenLibrarySourceFactory::class,
        ArxivSourceFactory::class,
        InspireSourceFactory::class,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('sources')
                    ->info('The sources, by name: a factory (openalex, hal, crossref, orcid, openlibrary, arxiv, inspire...) and its options.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('factory')->isRequired()->cannotBeEmpty()->end()
                            ->variableNode('options')->defaultValue([])->end()
                            ->variableNode('policy')->info('A tightening of the source\'s usage policy, in omnisense/contracts\' schema (never looser).')->end()
                            ->variableNode('usage')->info('What the site is: {commercial: true, open_source: false, contract: false}.')->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('limiter')->info('A service implementing Omnisense\Contracts\Policy\RateLimiterInterface, shared by the processes; null: in memory.')->defaultNull()->end()
                ->arrayNode('merger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('preferences')
                            ->info('For a field (title, abstract, citations, cover...), the sources in the order they are trusted; "default" for the others.')
                            ->useAttributeAsKey('field')
                            ->arrayPrototype()->scalarPrototype()->end()->end()
                        ->end()
                        ->booleanNode('editions')->defaultTrue()->info('Group the editions of a book under one record.')->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('abstracts')->defaultFalse()->info('Write the abstracts into BibTeX, RIS and CSL-JSON.')->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array{sources: array<string, array{factory: string, options: array<string, mixed>, policy?: mixed, usage?: mixed}>, limiter: ?string, merger: array{preferences: array<string, list<string>>, editions: bool}, export: array{abstracts: bool}} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(SourceFactoryInterface::class)->addTag('omnischolar.source_factory');

        $services = $container->services();
        foreach (self::FACTORIES as $factory) {
            if (class_exists($factory) && is_subclass_of($factory, SourceFactoryInterface::class)) {
                $services->set($factory)->args([service('http_client')->nullOnInvalid()])->tag('omnischolar.source_factory');
            }
        }

        $services->set(Registry::class)
            ->args([tagged_iterator('omnischolar.source_factory'), $config['sources'], null !== $config['limiter'] ? service($config['limiter']) : null])
            ->public();

        foreach (array_keys($config['sources']) as $name) {
            $id = 'omnischolar.source.'.$name;
            $services->set($id, SourceInterface::class)->factory([service(Registry::class), 'get'])->args([$name]);
            $builder->registerAliasForArgument($id, SourceInterface::class, $name);
        }

        $services->set(Merger::class)->args([$config['merger']['preferences'], $config['merger']['editions']]);
        $services->set(Collector::class)->args([service(Registry::class), service(Merger::class)]);
        $services->set(Export::class)->args([$config['export']['abstracts']]);
    }
}
