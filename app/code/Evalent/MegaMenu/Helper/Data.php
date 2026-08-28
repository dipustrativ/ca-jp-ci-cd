<?php
declare(strict_types=1);

namespace Evalent\MegaMenu\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Data
 * @package Evalent\MegaMenu\Helper
 * @api since 1.0.0
 */
class Data extends AbstractHelper
{

    public function __construct(
        Context $context
    ) {
        parent::__construct($context);
    }

    const IS_ENABLED_XML_PATH = 'catalog/navigation/top_menu_root_category_enabled';
    const ROOT_CATEGORY_ID_XML_PATH = 'catalog/navigation/top_menu_root_category_id';

    /**
     * @return bool
     */
    public function isEnabled() :bool
    {
        return $this->scopeConfig->isSetFlag(self::IS_ENABLED_XML_PATH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getRootCategoryId() :int
    {
        return (int)$this->scopeConfig->getValue(self::ROOT_CATEGORY_ID_XML_PATH, ScopeInterface::SCOPE_STORE);
    }
}
