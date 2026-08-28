<?php

namespace CarpetAvenue\Core\Block\Header;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class OrganizationSchema extends Template
{

    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    public function __construct(
        Template\Context $context,
        array $data = []
    )
    {
        $this->scopeConfig = $context->getScopeConfig();
        parent::__construct($context, $data);
    }

    public function getOrganizationSchema(): string
    {
        return $this->getConfigValue('carpetavenue_company_schema/schema/code');
    }

    /**
     * Get config value
     *
     * @param string $path
     * @return string
     */
    protected function getConfigValue(string $path): string
    {
        $value = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE
        );

        return $value ? trim((string)$value) : '';
    }


}