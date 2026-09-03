<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Overwrite\Mageplaza\Osc\Controller;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Redirect;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Mageplaza\Osc\Helper\Data;

/**
 * Intercepts the /onestepcheckout/banktransfer and /onestepcheckout/other entry URLs
 * emitted by the cart buttons, records the choice in the checkout session, then
 * forwards to the clean one-step-checkout route.
 */
class Router extends \Mageplaza\Osc\Controller\Router
{
    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var ResponseInterface
     */
    protected $response;

    public function __construct(
        Session $checkoutSession,
        ResponseInterface $response,
        ActionFactory $actionFactory,
        Data $helperData
    ) {
        parent::__construct($actionFactory, $helperData);
        $this->checkoutSession = $checkoutSession;
        $this->response = $response;
    }

    /**
     * @param RequestInterface|Http $request
     *
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request)
    {
        $identifier = trim($request->getPathInfo(), '/');
        if (strpos($identifier, 'onestepcheckout') !== false
            || strpos($identifier, $this->helper->getOscRoute()) !== false
        ) {
            $newRoute = strpos($identifier, 'onestepcheckout') !== false
                ? '/onestepcheckout'
                : '/' . $this->helper->getOscRoute();
            if (strpos($identifier, 'banktransfer') !== false) {
                $this->checkoutSession->setIsBankTransfer(1);
                $this->response->setRedirect($newRoute);
                return $this->actionFactory->create(Redirect::class);
            } elseif (strpos($identifier, 'other') !== false) {
                $this->checkoutSession->setIsBankTransfer(0);
                $this->response->setRedirect($newRoute);
                return $this->actionFactory->create(Redirect::class);
            }
        }
        return parent::match($request);
    }
}
