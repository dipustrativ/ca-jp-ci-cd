<?php

namespace Evalent\AdminActivityLog\Block\Adminhtml\ModelActivity;

class View extends \Magento\Backend\Block\Widget\Container
{
    /**
     * Admin session
     *
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $_session;

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * Backend session
     *
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $_backendSession;

    /**
     * @param \Magento\Backend\Block\Widget\Context $context
     * @param \Magento\Backend\Model\Auth\Session $backendSession
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Backend\Model\Auth\Session $backendSession,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        $this->_backendSession = $backendSession;
        $this->_coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Constructor
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _construct()
    {
        parent::_construct();
        $this->addButton(
            'back',
            [
                'label' => __('Back'),
                'onclick' => 'setLocation("' . $this->getBackUrl() . '")',
                'class' => 'back'
            ],
            -1
        );
    }


    public function getBackUrl()
    {
        return $this->getUrl('evalent_adminactivitylog/modelactivity/index');
    }

}
