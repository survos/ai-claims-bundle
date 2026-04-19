<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle;

use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Survos\AiClaimsBundle\Service\ClaimAggregator;
use Survos\AiClaimsBundle\Service\ClaimIngestor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosAiClaimsBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('list_predicates')
                    ->info('Predicates the aggregator projects as a list (keywords, places, etc.). Consumers register their own.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        $services->set(ClaimRepository::class);
        $services->set(ClaimIngestor::class);
        $services->set(ClaimAggregator::class)
            ->arg('$listPredicates', $config['list_predicates']);
    }
}
