<?php
declare(strict_types=1);

namespace IXPF\Syncer\Model\Indexer;

use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\Indexer\ActionInterface;

class Product implements ActionInterface, \Magento\Framework\Mview\ActionInterface
{
    private const CHUNK_SIZE = 500;

    private Webhook $webhookHelper;

    public function __construct(
        Webhook $webhook
    )
    {
        $this->webhookHelper = $webhook;
    }

    public function execute($ids): void
    {
        if (empty($ids)) {
            return;
        }

        $ids = array_values(array_unique(array_filter((array)$ids)));

        // Process in chunks to avoid memory issues with large backlogs
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->webhookHelper->updateOrCreateJob('product', $chunk);
        }
    }

    /**
     * @return void
     */
    public function executeFull(): void
    {
        //$this->webhookHelper->run("product", "indexer");
    }

    /**
     * @param array $ids
     * @return void
     */
    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    /**
     * @param $id
     * @return void
     */
    public function executeRow($id): void
    {
        $this->execute([$id]);
    }


}
