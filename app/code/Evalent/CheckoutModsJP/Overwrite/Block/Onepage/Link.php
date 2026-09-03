<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Overwrite\Block\Onepage;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use Magento\Checkout\Helper\Data;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders the cart "proceed to checkout" buttons.
 *
 * JP-adapted from Evalent_CheckoutMods: Klarna and Adyen branches removed
 * (those modules are not installed on this store), bank-transfer availability
 * driven by admin config instead of a hardcoded store id.
 */
class Link extends \Magento\Checkout\Block\Onepage\Link
{
    private const XML_PATH_BANKTRANSFER = 'checkoutmodsjp/payment_buttons/banktransfer_enabled';
    private const XML_PATH_ENABLED = 'orderify/payment_icons/enabled';
    private const XML_PATH_HEADING = 'orderify/payment_icons/heading';
    private const XML_PATH_ICONS = 'orderify/payment_icons/icons';

    /**
     * @var Manager
     */
    private $moduleManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var IconRepository
     */
    private $iconRepository;

    public function __construct(
        StoreManagerInterface $storeManager,
        Manager $moduleManager,
        ScopeConfigInterface $scopeConfig,
        Json $serializer,
        IconRepository $iconRepository,
        Context $context,
        Session $checkoutSession,
        Data $checkoutHelper,
        array $data = []
    ) {
        parent::__construct($context, $checkoutSession, $checkoutHelper, $data);
        $this->moduleManager = $moduleManager;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->serializer = $serializer;
        $this->iconRepository = $iconRepository;
    }

    /**
     * Whether the Mageplaza one-step checkout is installed and active.
     *
     * @return bool
     */
    public function isMagePlazaOnestepEnabled()
    {
        if ($this->moduleManager->isEnabled('Mageplaza_Osc')) {
            if ($this->scopeConfig->isSetFlag(
                'osc/general/enabled',
                ScopeInterface::SCOPE_STORE,
                $this->storeManager->getStore()->getId()
            )) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStore()
    {
        return $this->storeManager->getStore();
    }

    /**
     * Whether the bank-transfer button should be shown for the current store view.
     *
     * @return bool
     */
    public function bankTransferEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BANKTRANSFER,
            ScopeInterface::SCOPE_STORE,
            $this->getStore()->getId()
        );
    }

    /**
     * Whether the "we accept" icon strip is enabled for the current store view.
     *
     * @return bool
     */
    public function isIconStripEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $this->getStore()->getId()
        );
    }

    /**
     * Strip heading text (may be empty).
     *
     * @return string
     */
    public function getIconHeading(): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_HEADING,
            ScopeInterface::SCOPE_STORE,
            $this->getStore()->getId()
        );
    }

    /**
     * Ordered list of payment icons for the current store view.
     *
     * @return array<int, array{label:string, url:string}>
     */
    public function getPaymentIcons(): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_ICONS,
            ScopeInterface::SCOPE_STORE,
            $this->getStore()->getId()
        );
        if (empty($raw)) {
            return [];
        }

        try {
            $rows = $this->serializer->unserialize($raw);
        } catch (\Exception $e) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $icons = [];
        foreach ($rows as $row) {
            $path = isset($row['icon']) ? trim((string)$row['icon']) : '';
            if ($path === '' || !$this->iconRepository->fileExists($path)) {
                continue;
            }
            $icons[] = [
                'label' => isset($row['label']) ? (string)$row['label'] : '',
                'url' => $this->iconRepository->getUrl($path),
            ];
        }
        return $icons;
    }
}
