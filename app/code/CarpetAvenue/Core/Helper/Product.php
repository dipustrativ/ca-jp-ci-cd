<?php

namespace CarpetAvenue\Core\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Image\ParamsBuilder;
use Magento\Catalog\Model\View\Asset\ImageFactory as AssetImageFactory;
use Magento\Framework\View\ConfigInterface as ViewConfig;

class Product extends AbstractHelper
{
    /**
     * view.xml display area the hover image is rendered into. Must match the
     * main grid thumbnail so the swap doesn't reflow the tile.
     */
    private const HOVER_IMAGE_DISPLAY_AREA = 'category_page_grid';

    private ?array $hoverMiscParams = null;

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection,
        protected ViewConfig $viewConfig,
        protected ParamsBuilder $paramsBuilder,
        protected AssetImageFactory $assetImageFactory
    )
    {
        parent::__construct($context);
    }

    /**
     * Resize params for the hover thumbnail, resolved once per request.
     */
    private function getHoverMiscParams(): array
    {
        if ($this->hoverMiscParams === null) {
            $imageArguments = $this->viewConfig->getViewConfig()->getMediaAttributes(
                'Magento_Catalog',
                ImageHelper::MEDIA_TYPE_CONFIG_NODE,
                self::HOVER_IMAGE_DISPLAY_AREA
            );
            $this->hoverMiscParams = $this->paramsBuilder->build($imageArguments);
        }

        return $this->hoverMiscParams;
    }

    /**
     * Batch-fetch structured image data for all products in one SQL query.
     *
     * Returns: [ entityId => ['main' => path, 'second' => path|null, 'aivz' => path|null, 'special' => path] ]
     * Priority for 'special': _aivz image > second image > main image
     *
     * @param array $productIds
     * @return array
     */
    public function getProductImageMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $tGallery   = $connection->getTableName('catalog_product_entity_media_gallery');
        $tValue     = $connection->getTableName('catalog_product_entity_media_gallery_value');
        $tEntity    = $connection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $connection->select()
            ->from(['mg'  => $tGallery], ['value'])
            ->join(['mge' => $tEntity],  'mg.value_id = mge.value_id', ['entity_id'])
            ->joinLeft(['mgv' => $tValue], 'mg.value_id = mgv.value_id AND mgv.store_id = 0', ['position', 'disabled'])
            ->where('mge.entity_id IN (?)', $productIds)
            ->where('mg.media_type = ?', 'image')
            ->where('IFNULL(mgv.disabled, 0) = 0')
            ->order(['mge.entity_id ASC', 'IFNULL(mgv.position, 999) ASC', 'mg.value_id ASC']);

        $grouped = [];
        foreach ($connection->fetchAll($select) as $row) {
            $grouped[(int)$row['entity_id']][] = $row['value'];
        }

        $map = [];
        foreach ($grouped as $eid => $images) {
            $main   = $images[0] ?? null;
            $second = $images[1] ?? null;
            $aivz   = null;
            foreach ($images as $img) {
                if (stripos(basename($img), '_aivz') !== false) {
                    $aivz = $img;
                    break;
                }
            }
            $map[$eid] = [
                'main'    => $main,
                'second'  => $second,
                'aivz'    => $aivz,
                'special' => $aivz ?? $main,
            ];
        }

        return $map;
    }

    /**
     * @param array $productIds
     * @param bool $returnSingleProduct
     * @return array|mixed|string
     */
    public function getProductSecondImage(array $productIds, bool $returnSingleProduct = false): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $tGallery   = $connection->getTableName('catalog_product_entity_media_gallery');
        $tValue     = $connection->getTableName('catalog_product_entity_media_gallery_value');
        $tEntity    = $connection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $connection->select()
            ->from(['mg'    => $tGallery], ['value'])
            ->join(  ['mge' => $tEntity],  'mg.value_id = mge.value_id', ['entity_id'])
            ->joinLeft(['mgv' => $tValue], 'mg.value_id = mgv.value_id AND mgv.store_id = 0', ['position', 'disabled'])
            ->where('mge.entity_id IN (?)', $productIds)
            ->where('mg.media_type = ?', 'image')
            ->where('IFNULL(mgv.disabled, 0) = 0')
            ->order(['mge.entity_id ASC', 'IFNULL(mgv.position, 999) ASC', 'mg.value_id ASC']);

        $grouped = [];
        $result = $connection->fetchAll($select);
        foreach ($result as $row) {
            $grouped[(int) $row['entity_id']][] = $row['value'];
        }

        // Resolve the resize params ONCE. UrlBuilder::getUrl() re-reads view.xml
        // and rebuilds miscParams on every call, which cost ~19ms across a
        // 40-product grid; only the per-file asset below actually varies.
        $miscParams = $this->getHoverMiscParams();

        $hoverImageMap = [];
        foreach ($grouped as $eid => $images) {
            if (isset($images[1])) {   // index 1 = second image
                // Resized cache URL, not the raw original. Serving the original
                // here shipped ~340KB per tile (up to 561KB) on first hover.
                $hoverImageMap[$eid] = $this->assetImageFactory->create([
                    'miscParams' => $miscParams,
                    'filePath'   => $images[1],
                ])->getUrl();
            }
        }
        if ($returnSingleProduct){
            return $hoverImageMap[$productIds[0]] ?? [];
        }

        return $hoverImageMap;
    }

    public function isEnableHoverImage($storeId = null): bool
    {
        return $this->isEnableListHoverImage($storeId);
        //return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_HOVER_IMAGE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isEnableAiThumbnail($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('productify/smart_thumbnail/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isEnableListHoverImage($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('productify/smart_thumbnail/hover_enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }
}