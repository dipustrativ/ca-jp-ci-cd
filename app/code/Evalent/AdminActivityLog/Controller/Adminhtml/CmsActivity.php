<?php


namespace Evalent\AdminActivityLog\Controller\Adminhtml;

abstract class CmsActivity extends \Magento\Backend\App\Action
{

    const ADMIN_RESOURCE = 'Evalent_AdminActivityLog::top_level';
    protected $_coreRegistry;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Registry $coreRegistry
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Registry $coreRegistry
    ) {
        $this->_coreRegistry = $coreRegistry;
        parent::__construct($context);
    }

    /**
     * Init page
     *
     * @param \Magento\Backend\Model\View\Result\Page $resultPage
     */
    public function initPage($resultPage)
    {
        $resultPage->setActiveMenu(self::ADMIN_RESOURCE)
            ->addBreadcrumb(__('Evalent'), __('Evalent'))
            ->addBreadcrumb(__('Cms Activity'), __('Cms Activity'));
        return $resultPage;
    }
}
