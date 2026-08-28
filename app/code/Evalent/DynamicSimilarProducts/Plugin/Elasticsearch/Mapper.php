<?php
namespace Evalent\DynamicSimilarProducts\Plugin\Elasticsearch;

class Mapper{
    protected \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper;

    public function __construct(
        \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper
    ){
        $this->dataHelper = $dataHelper;
    }
    public function afterBuildQuery(\Magento\Elasticsearch\ElasticAdapter\SearchAdapter\Mapper $subject, $result, $request){
        $isAjaxPage = $this->dataHelper->onProductifyAjaxPage();
        $requestName = $request->getName();

        // Add text search for AJAX in quick_search_container
        if ($isAjaxPage && 'quick_search_container' === $requestName) {
            $searchQuery = $this->dataHelper->getRequest()->getParam('q');
            if ($searchQuery && isset($result['body']['query']['bool'])) {
                // Build the should clause with text matching (same as catalogsearch)
                $result['body']['query']['bool']['should'] = [
                    ['match' => ['_search' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match' => ['name' => ['query' => $searchQuery, 'boost' => 6]]],
                    ['match' => ['sku' => ['query' => $searchQuery, 'boost' => 7]]],
                    ['match' => ['status_value' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match' => ['production_type_value' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match' => ['signature' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match' => ['main_name' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match' => ['_search' => ['query' => $searchQuery, 'boost' => 2]]],
                    ['match_phrase_prefix' => ['name' => ['query' => $searchQuery, 'boost' => 2, 'analyzer' => 'prefix_search']]],
                    ['match_phrase_prefix' => ['sku' => ['query' => $searchQuery, 'boost' => 2, 'analyzer' => 'sku_prefix_search']]]
                ];
                
                $result['body']['query']['bool']['minimum_should_match'] = 1;
                
                // Override sort to match catalogsearch
                $result['body']['sort'] = [
                    ['is_out_of_stock' => ['order' => 'desc']],
                    ['_score' => ['order' => 'desc']]
                ];
                
                // Add missing aggregations to match catalogsearch
                if (!isset($result['body']['aggregations']['price_bucket'])) {
                    $result['body']['aggregations']['price_bucket'] = [
                        'extended_stats' => ['field' => 'price_0_1']
                    ];
                }
                if (!isset($result['body']['aggregations']['width2_bucket'])) {
                    $result['body']['aggregations']['width2_bucket'] = [
                        'extended_stats' => ['field' => 'width2']
                    ];
                }
                if (!isset($result['body']['aggregations']['height2_bucket'])) {
                    $result['body']['aggregations']['height2_bucket'] = [
                        'extended_stats' => ['field' => 'height2']
                    ];
                }
            }
            // Exclude similar product from AJAX search results
            $similarId = $this->dataHelper->getRequest()->getParam('product');
            if ($similarId > 0) {
                if (!isset($result['body']['query']['bool']['must_not'])) {
                    $result['body']['query']['bool']['must_not'] = [];
                }
                $result['body']['query']['bool']['must_not'][] = [
                    'terms' => [
                        '_id' => [$similarId]
                    ]
                ];
            }
        }
        
        if (($this->dataHelper->isSimilarTo() || $this->dataHelper->onProductPage()|| $this->dataHelper->onProductifyAjaxPage()) &&
            ('catalog_view_container' === $request->getName())
        ){
            // Skip transformations for AJAX context - keep all filters as hard requirements
            if ($this->dataHelper->onProductifyAjaxPage()) {
                return $result;
            }

            if (array_key_exists('body', $result) &&
                array_key_exists('query', $result['body']) &&
                array_key_exists('bool', $result['body']['query']) &&
                array_key_exists('must', $result['body']['query']['bool'])
            ){
                $toFilter = [];
                $toShould = [];
                foreach($result['body']['query']['bool']['must'] as $key => $must){
                    if (array_key_exists('range', $must) &&
                        array_key_exists('width2', $must['range'])){
                            $toFilter[] = $must;
                            unset($result['body']['query']['bool']['must'][$key]);
                    }elseif (array_key_exists('range', $must) &&
                        array_key_exists('height2', $must['range'])){
                        $toFilter[] = $must;
                        unset($result['body']['query']['bool']['must'][$key]);
                    }
                    if (($attribute = $this->dataHelper->getMainAttribute())){
                        if('should' === $this->dataHelper->getMainAttributeClause()){
                            if (array_key_exists('terms', $must) && array_key_exists($attribute,$must['terms'])){
                                $toShould[] = $key;
                                $result['body']['query']['bool']['should'][] = $must;
                                $must['terms']['boost'] = $this->dataHelper->getMainAttributeBoost();
                                unset($result['body']['query']['bool']['must'][$key]);
                            }
                        }
                    }
                    if (($attribute = $this->dataHelper->getTertiaryAttribute())){
                        if ('should' === $this->dataHelper->getTertiaryAttributeClause()){
                            if (array_key_exists('term', $must) && array_key_exists($attribute,$must['term'])){
                                $toShould[] = $key;
                                $result['body']['query']['bool']['should'][] = $must;
                                $must['term']['boost'] = $this->dataHelper->getTertiatyAttributeBoost();
                                unset($result['body']['query']['bool']['must'][$key]);
                            }
                        }
                    }
                }
                if(count($toFilter)){
                    $result['body']['query']['bool']['filter'] = $toFilter;
                }
                $result['body']['query']['bool']['minimum_should_match'] = 1;
            }
        }

        if ($this->dataHelper->isColorSortExists()) {
            $similarId = $this->dataHelper->getSimilarId();
            if ($similarId > 0) {
                if (!isset($result['body']['query']['bool']['must_not'])) {
                    $result['body']['query']['bool']['must_not'] = [];
                }
                $result['body']['query']['bool']['must_not'][] = [
                    'terms' => [
                        '_id' => [$similarId]
                    ]
                ];
            }

            $colorSort = $this->dataHelper->getColorSort();
            $colors = explode(',', $colorSort);

            $colorPriority = [];
            foreach ($colors as $index => $colorValue) {
                $colorPriority[intval($colorValue)] = $index;
            }

            $scriptConditions = '';
            foreach ($colorPriority as $colorId => $priority) {
                $scriptConditions .= "if (colorId == {$colorId}) return {$priority}; ";
            }

            $painlessScript = "
                def colors = params._source.color;
                if (colors == null) return 999;
                
                if (colors instanceof Integer) {
                    def colorId = colors;
                    {$scriptConditions}
                    return 999;
                } else if (colors instanceof List) {
                    int minPriority = 999;
                    for (def colorId : colors) {
                        {$scriptConditions}
                    }
                    return minPriority;
                }
                return 999;
            ";

            if (isset($result['body']['sort'])) {
                unset($result['body']['sort']);
            }

            $result['body']['sort'] = [
                [
                    '_script' => [
                        'type' => 'number',
                        'script' => [
                            'lang' => 'painless',
                            'source' => trim($painlessScript)
                        ],
                        'order' => 'asc'
                    ]
                ],
                '_score' // Relevance as secondary sort
            ];
        }
        return $result;
    }
}