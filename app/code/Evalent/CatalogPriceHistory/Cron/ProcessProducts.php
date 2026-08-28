<?php


namespace Evalent\CatalogPriceHistory\Cron;


class ProcessProducts
{
    /** @var \Psr\Log\LoggerInterface */
    protected $logger;

    /** @var \Evalent\CatalogPriceHistory\Api\ConfigInterface */
    protected  $config;

    /** @var \Evalent\CatalogPriceHistory\Model\ResourceModel\Product\Indexer\Price\HistoryInterface */
    protected  $historyIndexer;

    /**
     * PlaceOrder constructor.
     * @param \Evalent\CatalogPriceHistory\Model\ResourceModel\Product\Indexer\Price\HistoryInterface
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Evalent\CatalogPriceHistory\Api\ConfigInterface $config,
        \Evalent\CatalogPriceHistory\Model\ResourceModel\Product\Indexer\Price\HistoryInterface $historyIndexer
    )
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->historyIndexer = $historyIndexer;
    }

    /**
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isCronEnabled()){
            $this->logger->debug(sprintf('PRICE HISTORY: Cron not enabled'));
            return;
        }
        try{
            $this->logger->info(sprintf('PRICE HISTORY: Cron run'));
            $this->historyIndexer->reindexAll();
        }
        catch (\Exception $exception){
            $this->logger->debug(sprintf('PRICE HISTORY: %1',$exception->getMessage()));
        }
    }
}