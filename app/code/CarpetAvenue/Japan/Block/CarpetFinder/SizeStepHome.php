<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Block\CarpetFinder;

use Magento\Framework\View\Element\Template;

/**
 * Home-page size step.
 *
 * Only ever rendered in the CMS (home) context, where there is no category
 * layer to inspect, so it does not depend on the layered-navigation FilterList.
 * The size buttons link to the root category with width2 / height2 range filters.
 */
class SizeStepHome extends AbstractStep
{
    /** @var \Magento\Theme\Block\Html\Pager */
    private $htmlPagerBlock;

    private $levels = [
        "cm" => [
        ],
        "in" => [
        ]
    ];

    public function __construct(
        Template\Context $context,
        \Magento\Theme\Block\Html\Pager $htmlPagerBlock,
        array $data = []
    ) {
        $this->htmlPagerBlock = $htmlPagerBlock;

        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return (int) $this->getData("priority");
    }

    public function shouldRender(): bool
    {
        return true;
    }

    private function getApplicableStepData($unit): array
    {
        $this->levels = [
            "cm" => [
                "<100x150 cm" => [
                    "width" => [0, 100],
                    "length" => [0, 150],
                    "url" => $this->getFilterUrl([0, 100], [0, 150])
                ],
                "140x200 cm" => [
                    "width" => [120, 160],
                    "length" => [180, 220],
                    "url" => $this->getFilterUrl([120, 160], [180, 220])
                ],
                "160x230 cm" => [
                    "width" => [128, 192],
                    "length" => [184, 276],
                    "url" => $this->getFilterUrl([128, 192], [184, 276])
                ],
                "200x300 cm" => [
                    "width" => [180, 220],
                    "length" => [240, 360],
                    "url" => $this->getFilterUrl([180, 220], [240, 360])
                ],
                ">200x300 cm" => [
                    "width" => [200, 10000],
                    "length" => [300, 10000],
                    "url" => $this->getFilterUrl([200, 10000], [300, 10000])
                ]
            ],
            "in" => [
            ]
        ];

        return $this->levels[$unit] ?? $this->levels["cm"];
    }

    public function getSizeItems($unit = "cm"): array
    {
        $level = $this->getApplicableStepData($unit);
        return $level;
    }

    private function getFilterUrl($width, $length): string
    {
        $widthFilter = ($width[0] ?? "") . "-" . ($width[1] ?? "");
        $lengthFilter = ($length[0] ?? "") . "-" . ($length[1] ?? "");

        if ($this->isInCmsContext()) {
            return $this->getFallbackCategory()->getUrl()."?width2=".$widthFilter.'&height2='.$lengthFilter; // yes this is ugly
        }

        return $this->getUrl(
            "*/*/*",
            [
                "_current" => true,
                '_use_rewrite' => true,
                '_query' => [
                    "width2" => $widthFilter,
                    "height2" => $lengthFilter,
                    $this->htmlPagerBlock->getPageVarName() => null // unset pager
                ]
            ]
        );
    }
}
