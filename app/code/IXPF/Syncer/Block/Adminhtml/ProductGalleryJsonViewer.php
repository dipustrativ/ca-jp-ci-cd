<?php

namespace IXPF\Syncer\Block\Adminhtml;

use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use IXPF\Syncer\Model\Source\ProductGalleryWebhookStatus;
use Magento\Backend\Block\Template;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\UrlInterface;

/**
 * Photo Studio queue job detail page: readable summary plus raw JSON of every stored column.
 */
class ProductGalleryJsonViewer extends Template
{
    private const BUCKET_IMAGES_TABLE = 'ixpf_photo_studio_bucket_images';

    private ?array $queue = null;

    public function __construct(
        Template\Context $context,
        protected ResourceConnection $resourceConnection,
        protected ProductGalleryWebhookStatus $statusSource,
        array            $data = [],
        ?JsonHelper      $jsonHelper = null,
        ?DirectoryHelper $directoryHelper = null
    )
    {
        parent::__construct($context, $data, $jsonHelper, $directoryHelper);
    }

    /**
     * The raw queue row, or an empty array when the id is unknown.
     */
    public function getQueue(): array
    {
        if ($this->queue === null) {
            $connection = $this->resourceConnection->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE))
                    ->where('entity_id = ?', (int)$this->getRequest()->getParam('entity_id'))
            );
            $this->queue = $row ?: [];
        }

        return $this->queue;
    }

    /**
     * Gallery items IXPF asked for (decoded payload column).
     */
    public function getPayload(): ?array
    {
        return $this->decode($this->getQueue()['payload'] ?? null);
    }

    /**
     * What Magento did / reports back in the webhook (decoded response_result column).
     */
    public function getResult(): ?array
    {
        return $this->decode($this->getQueue()['response_result'] ?? null);
    }

    /**
     * IXPF's answer to the webhook call (decoded ixpf_response column).
     */
    public function getIxpfResponse(): mixed
    {
        $raw = $this->getQueue()['ixpf_response'] ?? null;
        $decoded = $this->decode($raw);

        return $decoded ?? $raw;
    }

    /**
     * Bucket download rows queued for this job.
     */
    public function getBucketImages(): array
    {
        $queue = $this->getQueue();
        if (!$queue) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::BUCKET_IMAGES_TABLE))
                ->where('photo_studio_queue_id = ?', (int)$queue['entity_id'])
                ->order('entity_id ASC')
        );
    }

    /**
     * SKU and admin edit URL of the job's product; sku is null when the product no longer exists.
     *
     * @return array{id: int, sku: ?string, url: string}
     */
    public function getProductInfo(): array
    {
        $productId = (int)($this->getQueue()['object_id'] ?? 0);
        $connection = $this->resourceConnection->getConnection();
        $sku = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['sku'])
                ->where('entity_id = ?', $productId)
        );

        return [
            'id' => $productId,
            'sku' => $sku !== false ? (string)$sku : null,
            'url' => $this->getUrl('catalog/product/edit', ['id' => $productId]),
        ];
    }

    public function getStatusLabel(string $status): string
    {
        foreach ($this->statusSource->toOptionArray() as $option) {
            if ($option['value'] === $status) {
                return (string)$option['label'];
            }
        }

        return ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Public URL of a gallery file path such as "/s/2/s26756_0_.jpg".
     */
    public function getMediaUrl(string $path): string
    {
        return $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . 'catalog/product/' . ltrim($path, '/');
    }

    public function getViewUrl(int $queueId): string
    {
        return $this->getUrl('*/*/view', ['entity_id' => $queueId]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    /**
     * Every stored column with the JSON columns decoded — the "whole record" JSON view.
     */
    public function getJsonData(): array
    {
        $result = $this->getQueue();
        if (!$result) {
            return [];
        }

        $result['payload'] = $this->getPayload() ?? $result['payload'];
        $result['response_result'] = $this->getResult() ?? $result['response_result'];
        $result['ixpf_response'] = $this->getIxpfResponse();
        $result['bucket_images'] = $this->getBucketImages();

        return $result;
    }

    private function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
