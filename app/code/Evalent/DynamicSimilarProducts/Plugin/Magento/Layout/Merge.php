<?php
namespace Evalent\DynamicSimilarProducts\Plugin\Magento\Layout;

use Magento\Framework\App\RequestInterface;

class Merge{
    protected \Magento\Framework\View\LayoutInterface $layout;

    public function __construct(
        \Magento\Framework\View\LayoutInterface $layout,
        protected RequestInterface $request
    ){
        $this->layout = $layout;
    }
    public function beforeLoad(\Magento\Framework\View\Model\Layout\Merge $subject, $handles = []){
        if ($this->request->getParam('similarTo')){
            if($block = $this->layout->getBlock('trustpilot.category.horizontal.mini')){
                $this->layout->unsetElement('trustpilot.category.horizontal.mini');
            }
            if($block = $this->layout->getBlock('page.main.title')){
                $this->layout->unsetElement('page.main.title');
            }
        }
        return [$handles];
    }
}
