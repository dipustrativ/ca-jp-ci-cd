<?php

declare(strict_types=1);

namespace IXPF\Syncer\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Clean Review Cache" button in the review configuration.
 *
 * A button, not a stored value: there is nothing to save, so the field renders its own control and
 * the scope/inheritance checkbox is suppressed.
 */
class CleanReviewCache extends Field
{
    /**
     * The backend URL builder explicitly, not $this->getUrl(): the target is an adminhtml route
     * and needs the admin base URL plus its secret key. Inheriting whichever UrlInterface the
     * area happens to resolve to would silently produce a storefront URL that 404s.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly BackendUrl $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData([
            'id'      => 'ixpf_reviews_clean_cache',
            'label'   => __('Clean Review Cache'),
            'onclick' => sprintf(
                "setLocation('%s')",
                $this->backendUrl->getUrl('ixpfsyncer/review/cleanCache')
            ),
        ]);

        return $button->toHtml();
    }
}
