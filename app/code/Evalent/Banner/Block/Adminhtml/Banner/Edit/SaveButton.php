<?php


namespace Evalent\Banner\Block\Adminhtml\Banner\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton extends GenericButton implements ButtonProviderInterface
{

    /**
     * @return array
     */
    public function getButtonData()
    {
        $user = $this->authSession->getUser();
        $object = $this->getModel();
        if (!$object) {
            if (!$this->policy->isAllowed($user->getRole()->getId(),
                'Evalent_Banner::Banner_create')
            ) {
                return [];
            }
        } else {
            if (!$this->policy->isAllowed($user->getRole()->getId(),
                'Evalent_Banner::Banner_update')
            ) {
                return [];
            }
        }
        return [
            'label' => __('Save Banner'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}
