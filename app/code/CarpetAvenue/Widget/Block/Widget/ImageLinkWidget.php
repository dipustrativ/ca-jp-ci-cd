<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;

class ImageLinkWidget extends Template implements BlockInterface
{
    protected $_template = 'CarpetAvenue_Widget::widget/imagelinkwidget.phtml';
    private string $ID = 'ImageLinkWidget-0';

    protected function _construct()
    {
        parent::_construct();
        $this->prepareId();
    }

    public function getLink(): mixed
    {
        return $this->getData('titleurl');
    }

    public function getId(): string
    {
        return $this->ID;
    }

    private function prepareId(): void
    {
        $fullClass = get_class($this);
        $className = substr($fullClass, strrpos($fullClass, '\\') + 1);
        $this->ID  = $className . '-' . rand(1000, 2000);
    }
}
