<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Block\CarpetFinder;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\View\Element\Template;

abstract class AbstractStep extends Template
{
    /** @var ?CategoryInterface */
    private $fallbackCategory = null;

    /**
     * Get priority between steps
     * Highest value gets priority
     * @return int
     */
    abstract public function getPriority(): int;

    /**
     * Get if this step should be rendered according to current state
     * @return bool
     */
    abstract public function shouldRender(): bool;

    /**
     * Check if carpet-finder is on homepage
     * @return bool
     */
    public function isInCmsContext(): bool
    {
        return $this->_request->getModuleName() === "cms";
    }

    public function getFallbackCategory(): ?CategoryInterface
    {
        return $this->fallbackCategory;
    }

    public function setFallbackCategory(CategoryInterface $category): AbstractStep
    {
        if ($this->fallbackCategory !== null) {
            throw new \RuntimeException("Can't reset category after the step has been initialized");
        }

        if (!$this->isInCmsContext()) {
            throw new \RuntimeException("Can only set category in cms context");
        }

        $this->fallbackCategory = $category;
        return $this;
    }
}
