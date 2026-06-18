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
use Survos\ClaimsBundle\Service\ClaimReader;
use Survos\ClaimsBundle\Twig\Components\ClaimsList;
use Survos\ClaimsBundle\Twig\Components\ClaimsSummary;
use Survos\ClaimsBundle\Twig\ClaimConstantsExtension;
use Survos\ClaimsBundle\Twig\ClaimFunctionsExtension;
use Survos\ClaimsBundle\Twig\Components\OcrClaimsPanel;
use Survos\ClaimsBundle\Twig\Components\SourceClaims;
use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class SurvosClaimsBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('reader_only')
                    ->info('Reader-only consumer: read mediary\'s central claims via ClaimReader, do NOT map the Claim entity (no local claim table) or register the writer services. Default false = writer (entities + ingestor).')
                    ->defaultFalse()
                ->end()
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

        // Always available: read-only access to mediary's central claim store + display
        // helpers that don't touch the ORM. ClaimReader uses the `mediary_ro` connection
        // (registered in prependExtension when MEDIARY_RO_DATABASE_URL is set); injected
        // optionally so ClaimReader::isAvailable() can guard callers when it's absent.
        $services->set(ClaimReader::class)
            ->arg('$connection', service('doctrine.dbal.mediary_ro_connection')->ignoreOnInvalid());
        $services->set(ClaimProjector::class)->autowire()->autoconfigure();
        $services->set(SourceClaims::class);
        $services->set(ClaimConstantsExtension::class);
        $services->set(ClaimFunctionsExtension::class)->autoconfigure();

        // Writer side: the ORM Claim/ClaimRun entities (mapped in prependExtension), the
        // ingestor, repositories, and ORM-backed components/commands. Skipped in reader-only
        // consumers (CLAIMS_READER_ONLY) so they get no `claim` table in their schema — they
        // READ mediary's claims via ClaimReader instead. Default is writer (unchanged for
        // mediary/md/pipeline/ssai).
        if (!self::isReaderOnly()) {
            $services->set(ClaimRepository::class);
            $services->set(ClaimRunRepository::class);
            $services->set(ClaimIngestor::class)
                ->arg('$dataPaths', service(DataPaths::class)->ignoreOnInvalid());
            $services->set(ClaimAggregator::class)
                ->arg('$listPredicates', $config['list_predicates']);
            $services->set(ClaimsExportCommand::class);
            $services->set(ClaimsImportCommand::class);
            $services->set(ClaimsList::class);
            $services->set(ClaimsSummary::class);
            $services->set(OcrClaimsPanel::class);

            if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
                $services->set(\Survos\ClaimsBundle\Menu\ClaimsMenuSubscriber::class)
                    ->autowire()
                    ->autoconfigure();
            }
        }
    }

    /**
     * Reader-only consumers (e.g. folio apps) set CLAIMS_READER_ONLY=1: they read mediary's
     * central claims via ClaimReader and do NOT map the Claim entity (no local claim table).
     * Default (unset) is writer mode — entities + ingestor — so existing apps are unchanged.
     */
    private static function isReaderOnly(): bool
    {
        $v = $_ENV['CLAIMS_READER_ONLY'] ?? $_SERVER['CLAIMS_READER_ONLY'] ?? getenv('CLAIMS_READER_ONLY');

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
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
