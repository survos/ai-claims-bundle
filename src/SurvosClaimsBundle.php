<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle;

use Survos\ClaimsBundle\Command\ClaimsExportCommand;
use Survos\ClaimsBundle\Command\ClaimsImportCommand;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\ClaimsBundle\Service\ClaimAggregator;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\ClaimProjector;
use Survos\ClaimsBundle\Twig\Components\ClaimsList;
use Survos\ClaimsBundle\Twig\Components\ClaimsSummary;
use Survos\ClaimsBundle\Twig\ClaimConstantsExtension;
use Survos\ClaimsBundle\Twig\ClaimFunctionsExtension;
use Survos\ClaimsBundle\Twig\Components\OcrClaimsPanel;
use Survos\ClaimsBundle\Twig\Components\SourceClaims;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosClaimsBundle extends AbstractBundle
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
        $services->set(ClaimProjector::class)->autowire()->autoconfigure();
        $services->set(ClaimAggregator::class)
            ->arg('$listPredicates', $config['list_predicates']);
        $services->set(ClaimsExportCommand::class);
        $services->set(ClaimsImportCommand::class);
        $services->set(ClaimsList::class);
        $services->set(ClaimsSummary::class);
        $services->set(OcrClaimsPanel::class);
        $services->set(SourceClaims::class);
        $services->set(ClaimConstantsExtension::class);
        $services->set(ClaimFunctionsExtension::class)->autoconfigure();

        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $services->set(\Survos\ClaimsBundle\Menu\ClaimsMenuSubscriber::class)
                ->autowire()
                ->autoconfigure();
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SurvosClaimsBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/src/Entity',
                        'prefix' => 'Survos\\ClaimsBundle\\Entity',
                        'alias' => 'Claims',
                    ],
                ],
            ],
        ]);

        // Expose bundle templates under @SurvosClaims for component + override.
        $builder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__) . '/templates' => 'SurvosClaims',
            ],
        ]);
    }
}
