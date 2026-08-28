<?php

namespace IXPF\Syncer\Controller;

use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Controller\Result\JsonFactory;

use Exception;

abstract class AbstractController extends Action
{
    protected $tokenValidator;

    protected $restRequest;

    protected $resultJsonFactory;

    public function __construct(
        Context        $context,
        TokenValidator $tokenValidator,
        JsonFactory    $resultFactory
    )
    {
        parent::__construct($context);
        $this->tokenValidator = $tokenValidator;
        $this->resultJsonFactory = $resultFactory;
    }

    /**
     * @throws Exception
     */
    protected function getBearerToken(): string
    {
        $authHeader = $this->_request->getHeader('X-Auth-Token');
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            throw new \Exception('Unauthorized');
        }
        return trim(str_ireplace('Bearer', '', $authHeader));
    }

    /**
     * @throws Exception
     */
    protected function tokenValidator(): bool
    {
        return $this->tokenValidator->checkTokenValidation($this->getBearerToken());
    }

    protected function jsonResponse(array $data, $statusCode = 200): Json
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        return $result->setData($data);
    }

}
