<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Block;

use CarpetAvenue\Japan\Block\CarpetFinder\AbstractStep;
use Evalent\MegaMenu\Helper\Data;
use CarpetAvenue\Japan\Helper\Data as CarpetFinderData;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;

/**
 * Class CarpetFinder
 * @package CarpetAvenue\Japan\Block
 *
 * Note about cache: There is no real use to cache this block in block_html since there is too many
 * variables that can change output
 */
class CarpetFinder extends AbstractBlock
{
    /** @var \Magento\Catalog\Model\CategoryRepository */
    private $categoryRepository;

    /** @var \Evalent\MegaMenu\Helper\Data */
    private $megaMenuHelper;

    private $carpetFinderData;

    public function __construct(
        Context $context,
        CategoryRepository $categoryRepository,
        Data $megaMenuHelper,
        CarpetFinderData $carpetFinderData,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->categoryRepository = $categoryRepository;
        $this->megaMenuHelper = $megaMenuHelper;
        $this->carpetFinderData = $carpetFinderData;
    }

    public function toHtml()
    {
        $steps = $this->getStep();
        $stepsHtml = "";

        foreach ($steps as $step) {
            $stepsHtml .= $step->toHtml();
        }

        // check if there is any steps to output
        if ($stepsHtml !== "") {
            return '<div class="carpetfinder-widget">' . $stepsHtml . '</div>';
        }

        return null;
    }

    /**
     * Find the step that should be shown, or null if none is applicable
     * @return \CarpetAvenue\Japan\Block\CarpetFinder\AbstractStep[]|null
     */
    private function getStep(): ?array
    {
        $priority = 0;
        $returnBlocks = []; // Multiple steps in one

        foreach ($this->getChildNames() as $childName) {
            $block = $this->getChildBlock($childName);
            if (!($block instanceof AbstractStep)) {
                throw new \LogicException("Invalid child block type for ".$childName);
            }

            if ($block->shouldRender()) {
                if ($block->getPriority() > $priority) {
                    $priority = $block->getPriority();
                    $returnBlocks[0] = $block;
                }
                if ($block->getPriority() === 0) {
                    $priority = $block->getPriority();
                    $returnBlocks[] = $block;
                }
            }
        }

        if (count($returnBlocks) > 0 && $returnBlocks[0]->isInCmsContext()) {
            try {
                if (empty($this->megaMenuHelper->getRootCategoryId())) {
                    throw new \RuntimeException("Mega Menu Root category has not been set, I kinda need that");
                }
                foreach ($returnBlocks as $b) {
                    $b->setFallbackCategory(
                        $this->categoryRepository->get($this->megaMenuHelper->getRootCategoryId())
                    );
                }
            } catch (\Exception $e) {
                $this->_logger->error(__METHOD__. " ". $e->getMessage());
            }
        }

        if (count($returnBlocks) > 0) {
            foreach ($returnBlocks as $b) {
                $b->setData("buttons_color", $this->getData("buttons_color"));
                $b->setData("mobile_hide", $this->carpetFinderData->getMobileHide());
            }
        }

        return $returnBlocks;
    }
}
