<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle;

use Survos\AiClaimsBundle\Command\ClaimsExportCommand;
use Survos\AiClaimsBundle\Command\ClaimsImportCommand;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Survos\AiClaimsBundle\Repository\ClaimRunRepository;
use Survos\AiClaimsBundle\Service\ClaimAggregator;
use Survos\AiClaimsBundle\Service\ClaimIngestor;
use Survos\AiClaimsBundle\Twig\Components\AiClaimsList;
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
        $services->set(ClaimRunRepository::class);
        $services->set(ClaimIngestor::class);
        $services->set(ClaimAggregator::class)
            ->arg('$listPredicates', $config['list_predicates']);
        $services->set(ClaimsExportCommand::class);
        $services->set(ClaimsImportCommand::class);
        $services->set(AiClaimsList::class);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SurvosAiClaimsBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/src/Entity',
                        'prefix' => 'Survos\\AiClaimsBundle\\Entity',
                        'alias' => 'AiClaims',
                    ],
                ],
            ],
        ]);

        // Expose bundle templates under @SurvosAiClaims for component + override.
        $builder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__) . '/templates' => 'SurvosAiClaims',
            ],
        ]);
    }
}
