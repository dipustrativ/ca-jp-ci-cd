<?php

namespace CarpetAvenue\Productify\Block\Category;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Magento\Cms\Model\Template\FilterProvider;
use Evalent\Banner\Api\BannerRepositoryInterface;
use Magento\Cms\Model\BlockFactory;

class Banner extends Template
{
    /**
     * Request-scoped memo, keyed "{categoryId}:{getter}". Keyed by category
     * because getCurrentCategory() reads the registry, which can change.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        Context $context,
        private Registry $registry,
        private BannerRepositoryInterface $bannerRepository,
        private BlockFactory $blockFactory,
        private FilterProvider $filterProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCurrentCategory()
    {
        return $this->registry->registry('current_category');
    }

    public function getTopBanner()
    {
        // array_key_exists, not ??= : a null result is the case worth remembering.
        $key = $this->memoKey('top_banner');
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->loadBanner('top_banner');
        }
        return $this->memo[$key];
    }

    public function getBottomBanner()
    {
        $key = $this->memoKey('bottom_banner');
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->loadBanner('bottom_banner');
        }
        return $this->memo[$key];
    }

    public function getCustomTopBlockHtml()
    {
        $key = $this->memoKey('custom_top_block');
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->loadCustomBlockHtml('custom_top_block');
        }
        return $this->memo[$key];
    }

    public function getCustomBottomBlockHtml()
    {
        $key = $this->memoKey('custom_bottom_block');
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->loadCustomBlockHtml('custom_bottom_block');
        }
        return $this->memo[$key];
    }

    /**
     * Memo key scoped to the category currently in the registry.
     *
     * @param string $getter
     * @return string
     */
    private function memoKey(string $getter): string
    {
        $category = $this->getCurrentCategory();

        return ($category ? (string)$category->getId() : '0') . ':' . $getter;
    }

    /**
     * Resolve an enabled banner from a category attribute holding its id.
     *
     * @param string $attribute
     * @return \Evalent\Banner\Api\Data\BannerInterface|null
     */
    private function loadBanner(string $attribute)
    {
        $category = $this->getCurrentCategory();
        if (!$category || !$category->getData($attribute)) {
            return null;
        }

        try {
            $banner = $this->bannerRepository->get((int)$category->getData($attribute));
            if ($banner->getEnabled()) {
                return $banner;
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Render the CMS block referenced by a category attribute, or '' if unset.
     *
     * @param string $attribute
     * @return string
     */
    private function loadCustomBlockHtml(string $attribute): string
    {
        $category = $this->getCurrentCategory();
        if (!$category || !$category->getData($attribute)) {
            return '';
        }

        $block = $this->blockFactory->create()->load($category->getData($attribute));
        return $block->getContent() ? $this->filterProvider->getBlockFilter()->filter($block->getContent()) : '';
    }

    public function isHideBannerLink(): bool
    {
        $category = $this->getCurrentCategory();
        return $category ? (bool) $category->getData('hide_bottom_block_link') : false;
    }
}
