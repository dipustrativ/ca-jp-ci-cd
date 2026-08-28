<?php
/**
 * Generic Documentation Viewer — renders any .md file from a module's docs/ folder.
 *
 * URL pattern:  carpetavenue/doc/view?module=CarpetAvenue_Core&doc=slack-notification
 *
 * @package   CarpetAvenue_Core
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Controller\Adminhtml\Doc;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Core::miscellaneous_documentation';

    public function __construct(
        Context                      $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\View\Result\Page
    {
        $page = $this->pageFactory->create();
        $doc  = $this->getRequest()->getParam('doc', '');
        $title = ucwords(str_replace(['-', '_'], ' ', (string)$doc));
        $page->getConfig()->getTitle()->prepend(__('%1 — Documentation', $title));
        return $page;
    }
}

