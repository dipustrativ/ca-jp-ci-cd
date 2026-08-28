<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Icon;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Returns the list of existing payment icons as JSON for the admin chooser.
 */
class Listing extends Action
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::payment_icons';

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var IconRepository */
    private $iconRepository;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        IconRepository $iconRepository
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->iconRepository = $iconRepository;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            return $result->setData([
                'success' => true,
                'items' => $this->iconRepository->getList(),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
                'items' => [],
            ]);
        }
    }
}
