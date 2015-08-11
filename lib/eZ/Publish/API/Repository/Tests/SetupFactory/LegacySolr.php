<?php

/**
 * File containing the Test Setup Factory base class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Publish\API\Repository\Tests\SetupFactory;

use eZ\Publish\Core\Base\ServiceContainer;
use eZ\Publish\Core\Base\Container\Compiler;
use PDO;
use RuntimeException;
use eZ\Publish\API\Repository\Tests\SearchServiceTranslationLanguageFallbackTest;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * A Test Factory is used to setup the infrastructure for a tests, based on a
 * specific repository implementation to test.
 */
class LegacySolr extends Legacy
{
    /**
     * Returns a configured repository for testing.
     *
     * @param bool $initializeFromScratch
     *
     * @return \eZ\Publish\API\Repository\Repository
     */
    public function getRepository($initializeFromScratch = true)
    {
        // Load repository first so all initialization steps are done
        $repository = parent::getRepository($initializeFromScratch);

        if ($initializeFromScratch) {
            $this->indexAll();
        }

        return $repository;
    }

    public function getServiceContainer()
    {
        if (!isset(self::$serviceContainer)) {
            $configPath = __DIR__ . '/../../../../../../../vendor/ezsystems/ezpublish-kernel/config.php';
            if (file_exists($configPath)) {
                // If executed from ezsystems/ezplatform-solr-search-engine
                $config = include $configPath;
                $installDir = $config['install_dir'];
                /** @var \Symfony\Component\DependencyInjection\ContainerBuilder $containerBuilder */
                $containerBuilder = include $config['container_builder_path'];
                $settingsPath = __DIR__ . '/../../../../../../eZ/Publish/Core/settings/';
            } else {
                // Else it should run from ezsystems/ezpublish-kernel
                $configPath = __DIR__ . '/../../../../../../../../../../config.php';
                $config = include $configPath;
                $installDir = $config['install_dir'];
                /** @var \Symfony\Component\DependencyInjection\ContainerBuilder $containerBuilder */
                $containerBuilder = include $config['container_builder_path'];
                $settingsPath = $installDir . '/vendor/ezsystems/ezplatform-solr-search-engine/lib/eZ/Publish/Core/settings/';
            }

            $solrLoader = new YamlFileLoader($containerBuilder, new FileLocator($settingsPath));
            $solrLoader->load('search_engines/solr.yml');
            $solrLoader->load($this->getTestConfigurationFile());

            $containerBuilder->addCompilerPass(new Compiler\Search\Solr\AggregateCriterionVisitorPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\Solr\AggregateFacetBuilderVisitorPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\Solr\AggregateFieldValueMapperPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\Solr\AggregateSortClauseVisitorPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\Solr\EndpointRegistryPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\FieldRegistryPass());
            $containerBuilder->addCompilerPass(new Compiler\Search\SignalSlotPass());

            $containerBuilder->setParameter(
                'legacy_dsn',
                self::$dsn
            );

            $containerBuilder->setParameter(
                'io_root_dir',
                self::$ioRootDir . '/' . $containerBuilder->getParameter('storage_dir')
            );

            self::$serviceContainer = new ServiceContainer(
                $containerBuilder,
                $installDir,
                $config['cache_dir'],
                true,
                true
            );
        }

        return self::$serviceContainer;
    }

    /**
     * Indexes all Content objects.
     */
    protected function indexAll()
    {
        // @todo: Is there a nicer way to get access to all content objects? We
        // require this to run a full index here.
        /** @var \eZ\Publish\SPI\Persistence\Handler $persistenceHandler */
        $persistenceHandler = $this->getServiceContainer()->get('ezpublish.spi.persistence.legacy');
        /** @var \eZ\Publish\SPI\Search\Handler $searchHandler */
        $searchHandler = $this->getServiceContainer()->get('ezpublish.spi.search.solr');
        /** @var \eZ\Publish\Core\Persistence\Database\DatabaseHandler $databaseHandler */
        $databaseHandler = $this->getServiceContainer()->get('ezpublish.api.storage_engine.legacy.dbhandler');

        $query = $databaseHandler
            ->createSelectQuery()
            ->select('id', 'current_version')
            ->from('ezcontentobject');

        $stmt = $query->prepare();
        $stmt->execute();

        $contentObjects = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $contentObjects[] = $persistenceHandler->contentHandler()->load(
                $row['id'],
                $row['current_version']
            );
        }

        /** @var \eZ\Publish\Core\Search\Solr\Handler $searchHandler */
        $searchHandler->purgeIndex();
        $searchHandler->setCommit(true);
        $searchHandler->bulkIndexContent($contentObjects);
    }

    protected function getTestConfigurationFile()
    {
        $coresSetup = getenv('CORES_SETUP');

        switch ($coresSetup) {
            case SearchServiceTranslationLanguageFallbackTest::SETUP_DEDICATED:
                return 'tests/solr/multicore_dedicated.yml';
            case SearchServiceTranslationLanguageFallbackTest::SETUP_SHARED:
                return 'tests/solr/multicore_shared.yml';
            case SearchServiceTranslationLanguageFallbackTest::SETUP_SINGLE:
                return 'tests/solr/single_core.yml';
        }

        throw new RuntimeException("Backend cores setup '{$coresSetup}' is not handled");
    }
}
