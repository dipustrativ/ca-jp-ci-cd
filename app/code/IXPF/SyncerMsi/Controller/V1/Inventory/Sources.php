<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Controller\V1\Inventory;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\SyncerMsi\Services\Inventory\Sources as SourcesService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Sources extends AbstractController implements HttpGetActionInterface
{
    public function __construct(
        Context         $context,
        TokenValidator  $tokenValidator,
        JsonFactory     $resultFactory,
        protected SourcesService $sourcesService
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $sources = $this->sourcesService->getList();

            return $this->jsonResponse([
                'success' => true,
                'data'    => $sources
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
