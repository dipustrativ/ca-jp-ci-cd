<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Adminhtml\Banner\Widget;

use Evalent\Banner\Model\BannerRepository;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * Class BannerColumns
 * @package Evalent\Banner\Block\Adminhtml\Widget\Banners
 *
 * @method setName(string $value): BannerAnimationsEffect
 */
class BannerColumns extends Select
{

    /** @var SearchCriteriaInterface */
    private $searchCriteria;

    /** @var BannerRepository */
    protected $bannersRepository;

    public function __construct(
        Context $context,
        BannerRepository $bannersRepository,
        SearchCriteriaInterface $searchCriteria,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->bannersRepository = $bannersRepository;
        $this->searchCriteria = $searchCriteria;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * Render block HTML
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getBannerColumns());
        }
        return parent::_toHtml();
    }

    /**
     * @return array
     */
    public function getBannerColumns()
    {
        return [
            ['value' => '12', 'label' => __('Default: 100% Width')],
            ['value' => '8', 'label' => __('%1/%2: %3% Width', 2, 3, 66)],
            ['value' => '6', 'label' => __('%1/%2: %3% Width', 1, 2, 50)],
            ['value' => '4', 'label' => __('%1/%2: %3% Width', 1, 3, 33)],
            ['value' => '3', 'label' => __('%1/%2: %3% Width', 1, 4, 25)]
        ];
    }
}
