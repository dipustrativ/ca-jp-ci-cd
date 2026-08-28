<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Adminhtml\Banner\Widget;

use Evalent\Banner\Model\BannerRepository;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * Class Options
 * @package Evalent\Banner\Block\Adminhtml\Widget\Banners
 *
 * @method setName(string $value): BannerAnimationsEffect
 */
class Options extends Select
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
            $this->setOptions($this->getBanners());
        }
        return parent::_toHtml();
    }

    /**
     * @return array
     */
    public function getBanners()
    {
        $bannerCollection = $this->bannersRepository
            ->getList($this->searchCriteria)
            ->getItems();
        $arr = [];
        $i = 0;
        foreach ($bannerCollection as $item) {
            $i++;
            $arr[$i] = [
                'value' => $item->getEntityId(),
                'label' => $item->getName() ? $item->getName() : "ID: " . $item->getEntityId()
            ];
        }

        return $arr;
    }
}
