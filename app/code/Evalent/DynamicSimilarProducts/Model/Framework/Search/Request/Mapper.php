<?php
namespace Evalent\DynamicSimilarProducts\Model\Framework\Search\Request;

class Mapper extends \Magento\Framework\Search\Request\Mapper{
    protected \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        array $queries,
        $rootQueryName,
        \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper,
        array $aggregations = [],
        array $filters = []
    ) {
        $this->dataHelper = $dataHelper;
        if (($this->dataHelper->isSimilarTo() || $this->dataHelper->onProductPage()) && 
            ('catalog_view_container' === $rootQueryName)
        ){
            $filters['sku_filter'] = [
                'name' => 'sku_filter',
                'field' => 'sku',
                'value' => $this->dataHelper->getSimilarToProduct()->getSku(),
                'type' => 'termFilter',
                'is_bind' => true
            ];
            if (array_key_exists('color_query',$queries)){
                $queries['color_query']['boost'] = $this->dataHelper->getMainAttributeBoost();
            }
            $queries['sku_query'] = [
                'name' => 'sku_query',
                'filterReference' => [['clause' => 'not','ref'=> 'sku_filter']],
                'type' => 'filteredQuery'
            ];
            if (array_key_exists('catalog_view_container',$queries) && array_key_exists('queryReference',$queries['catalog_view_container'])){
                $queries['catalog_view_container']['queryReference'][] = ['clause' => 'not', 'ref' => 'sku_query'];
            }
            
        }
        parent::__construct($objectManager,$queries,$rootQueryName,$aggregations,$filters);
    }
}