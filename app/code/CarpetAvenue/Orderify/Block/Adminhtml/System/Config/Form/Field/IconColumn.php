<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\System\Config\Form\Field;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\View\Element\Template;

/**
 * Renders the "icon" cell of the Icons FieldArray: a (read-only) path input,
 * a preview thumbnail and a "Choose / Upload" button. The button + preview are
 * wired up by CarpetAvenue_Orderify/js/payment-icon-chooser via delegation.
 */
class IconColumn extends Template
{
    /** @var string */
    protected $_template = 'CarpetAvenue_Orderify::system/config/payment-icon-column.phtml';

    /** @var IconRepository */
    private $iconRepository;

    public function __construct(
        Context $context,
        IconRepository $iconRepository,
        array $data = []
    ) {
        $this->iconRepository = $iconRepository;
        parent::__construct($context, $data);
    }

    /**
     * Set by AbstractFieldArray::renderCellTemplate via magic setter.
     *
     * @return string
     */
    public function getInputName(): string
    {
        return (string)$this->getData('input_name');
    }

    /**
     * @return string
     */
    public function getInputId(): string
    {
        return (string)$this->getData('input_id');
    }

    /**
     * @return string
     */
    public function getColumnName(): string
    {
        return (string)$this->getData('column_name');
    }

    /**
     * @return string
     */
    public function getMediaBaseUrl(): string
    {
        return $this->iconRepository->getMediaBaseUrl();
    }
}
