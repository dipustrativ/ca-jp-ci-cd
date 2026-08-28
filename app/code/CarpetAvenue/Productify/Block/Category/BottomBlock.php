<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Cms\Block\Block;
use Magento\Framework\View\Element\Template;

class BottomBlock extends Template
{
    protected Category $currentCategory;
    public function __construct(
        protected LayerResolver $_layerResolver,
        protected Block $_cmsBlock,
        Template\Context $context,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    /**
     * @return Category
     */
    public function getCurrentCategory(): Category
    {
        $this->currentCategory = $this->_layerResolver->get()->getCurrentCategory();
        return $this->currentCategory;
    }

    public function getBlock()
    {
        if (!isset($this->currentCategory)) {
            $category = $this->getCurrentCategory();
        } else {
            $category = $this->currentCategory;
        }
        return $category->getData('custom_bottom_block');
    }

    public function getBottomBlockHtml()
    {
        $block = $this->getBlock();
        if ($block) {
            return $this->_cmsBlock->setBlockId($block)->toHtml();
        }
        return '';
    }

    public function isShowDescriptionOnTop(): bool
    {
        if (!isset($this->currentCategory)) {
            $category = $this->getCurrentCategory();
        } else {
            $category = $this->currentCategory;
        }

        $showDescriptionOnTop = $category->getData('show_description_top');
        if ($showDescriptionOnTop == 1) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public function isBottomCategoryDescriptionHide(): bool
    {
        return $this->isShowDescriptionOnTop();
    }

    /**
     * @return bool
     */
    public function isHideBottomBlockLink(): bool
    {
        if (!isset($this->currentCategory)) {
            $category = $this->getCurrentCategory();
        } else {
            $category = $this->currentCategory;
        }
        return (bool) $category->getData('hide_bottom_block_link');
    }
}