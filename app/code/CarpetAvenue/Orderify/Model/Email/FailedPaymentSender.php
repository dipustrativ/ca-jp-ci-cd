<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\Email;

use CarpetAvenue\Orderify\Helper\Config;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class FailedPaymentSender
{
    public function __construct(
        private readonly Config                     $config,
        private readonly TransportBuilder           $transportBuilder,
        private readonly StateInterface             $inlineTranslation,
        private readonly StoreManagerInterface      $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface   $orderRepository,
        private readonly AdminSession               $adminSession,
        private readonly LoggerInterface            $logger
    ) {}

    public function send(Order $order): bool
    {
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isFailedPaymentEmailEnabled($storeId)) {
            return false;
        }

        $customerEmail = $order->getCustomerEmail();
        if (!$customerEmail) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $store = $this->storeManager->getStore($storeId);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getFailedPaymentEmailTemplate($storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order'              => $order,
                    'order_id'           => $order->getId(),
                    'store'              => $store,
                    'store_url'          => $store->getBaseUrl(),
                    'product_links_html' => $this->buildProductLinksHtml($order),
                ])
                ->setFromByScope($this->config->getFailedPaymentEmailSender($storeId), $storeId)
                ->addTo($customerEmail, $order->getCustomerName())
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();

            $order->addCommentToStatusHistory(
                $this->buildOrderComment($customerEmail),
                false,
                false
            );
            $this->orderRepository->save($order);

            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error(sprintf(
                '[Orderify] Failed payment reminder error for order #%s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));

            return false;
        }
    }

    private function buildOrderComment(string $customerEmail): string
    {
        $adminUser = $this->adminSession->getUser();
        $byLine    = '';

        if ($adminUser) {
            $byLine = sprintf(
                '| by %s %s (%s)<br>',
                $adminUser->getFirstName(),
                $adminUser->getLastName(),
                $adminUser->getEmail()
            );
        }

        return $byLine . sprintf('Failed payment reminder email sent to %s.', $customerEmail);
    }

    /**
     * Build one anchor per visible order item pointing to the product page.
     * Returned as raw HTML — use {{var product_links_html|raw}} in template.
     */
    private function buildProductLinksHtml(Order $order): string
    {
        $html = '';

        foreach ($order->getAllVisibleItems() as $item) {
            try {
                $product = $this->productRepository->getById(
                    (int) $item->getProductId(),
                    false,
                    $order->getStoreId()
                );
                $url  = $product->getUrlModel()->getUrl($product);
                $name = htmlspecialchars((string) $item->getName(), ENT_QUOTES);

                $html .= '<p style="margin:6px 0;">'
                    . '<a href="' . $url . '" '
                    . 'style="color:#1a1a1a;font-weight:bold;text-decoration:underline;">'
                    . $name
                    . '</a>'
                    . '</p>';
            } catch (NoSuchEntityException) {
                // product deleted — skip link
            }
        }

        return $html;
    }
}
