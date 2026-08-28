<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\Adminhtml\Docs;

use IXPF\Syncer\Services\Docs\DocsRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::docs';

    public function __construct(
        Context $context,
        protected readonly PageFactory $pageFactory,
        protected readonly DocsRepository $docsRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('doc', '');
        $doc = $key !== '' ? $this->docsRepository->getByKey($key) : null;

        if ($doc === null) {
            $this->messageManager->addErrorMessage(__('Document not found.'));
            $redirect = $this->resultRedirectFactory->create();
            return $redirect->setPath('ixpfsyncer/docs/index');
        }

        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('IXPF_Syncer::docs');
        $page->getConfig()->getTitle()->prepend(__('API Docs: %1', $doc['title']));
        return $page;
    }
}
