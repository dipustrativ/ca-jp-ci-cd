<?php
namespace CarpetAvenue\Productify\ProductStatus\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class Reindex extends Command
{
    const ENTITY_ID = 'entity_id';

    protected \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry;

    public function __construct(
        string $name,
        \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry
    ) {
        $this->indexerRegistry = $indexerRegistry;
        parent::__construct($name);
    }
    protected function configure()
    {
        $this->setName('productstatus:reindex');
        $this->setDescription('Product reindex test/debug');
        $this->addOption(
            self::ENTITY_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'product entity_id'
        );

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($entityId = $input->getOption(self::ENTITY_ID)) {
            $output->writeln('<info>Product reindex test/debug for entity_id: ' . $entityId . '</info>');
            /*$indexer = $this->indexerRegistry->get('catalog_category_product');
            $indexer->reindexList([$entityId]);
            $indexer = $this->indexerRegistry->get('catalog_product_category');
            $indexer->reindexList([$entityId]);
            $indexer = $this->indexerRegistry->get('catalog_product_attribute');
            $indexer->reindexList([$entityId]);*/
            $indexer = $this->indexerRegistry->get('catalogsearch_fulltext');
            $indexer->reindexList([$entityId]);
        }

        return Command::SUCCESS;
    }
}