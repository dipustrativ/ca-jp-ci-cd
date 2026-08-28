<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Renders the configured "we accept" payment-icon strip for the current store
 * view. Reusable anywhere — including CMS via a block directive:
 *
 *   {{block class="CarpetAvenue\Orderify\Block\PaymentIcons" align="left"}}
 *
 * Reads the same orderify/payment_icons/* config the checkout strip uses, so a
 * single Google-Sheet sync drives both the cart button area and the footer.
 */
class PaymentIcons extends Template
{
    private const XML_PATH_ENABLED = 'orderify/payment_icons/enabled';
    private const XML_PATH_HEADING = 'orderify/payment_icons/heading';
    private const XML_PATH_ICONS = 'orderify/payment_icons/icons';

    /** @var Json */
    private $serializer;

    /** @var IconRepository */
    private $iconRepository;

    public function __construct(
        Context $context,
        Json $serializer,
        IconRepository $iconRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->serializer = $serializer;
        $this->iconRepository = $iconRepository;
    }

    /**
     * Default template; overridable via the directive's `template` arg.
     *
     * @var string
     */
    protected $_template = 'CarpetAvenue_Orderify::payment-icons.phtml';

    /**
     * Current store id.
     *
     * @return int
     */
    private function getStoreId(): int
    {
        return (int)$this->_storeManager->getStore()->getId();
    }

    /**
     * Whether the strip is enabled for the current store view.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $this->getStoreId()
        );
    }

    /**
     * Optional heading. Hidden by default here (the host block usually has its
     * own title); pass show_heading="1" to render it.
     *
     * @return string
     */
    public function getHeading(): string
    {
        if (!$this->getData('show_heading')) {
            return '';
        }
        return (string)$this->_scopeConfig->getValue(
            self::XML_PATH_HEADING,
            ScopeInterface::SCOPE_STORE,
            $this->getStoreId()
        );
    }

    /**
     * Alignment modifier: "left", "center" (default) or "right".
     *
     * @return string
     */
    public function getAlign(): string
    {
        $align = strtolower((string)$this->getData('align'));
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    }

    /**
     * Optional extra CSS class(es) added to the wrapper, e.g. css_class="footer"
     * so a host can tweak spacing. Sanitized to safe class-name characters.
     *
     * @return string
     */
    public function getCustomClass(): string
    {
        $class = trim((string)$this->getData('css_class'));
        return preg_replace('/[^a-zA-Z0-9 _-]/', '', $class) ?? '';
    }

    /**
     * Ordered list of icons for the current store view.
     *
     * @return array<int, array{label:string, url:string}>
     */
    public function getIcons(): array
    {
        $raw = $this->_scopeConfig->getValue(
            self::XML_PATH_ICONS,
            ScopeInterface::SCOPE_STORE,
            $this->getStoreId()
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
