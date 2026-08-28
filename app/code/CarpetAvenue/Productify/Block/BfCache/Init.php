<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\BfCache;

use CarpetAvenue\Productify\Helper\BfCacheHelper;
use Magento\Framework\View\Element\Template;

class Init extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly BfCacheHelper $config,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getMaxAgeSeconds(): int
    {
        return $this->config->getMaxAgeSeconds();
    }
}