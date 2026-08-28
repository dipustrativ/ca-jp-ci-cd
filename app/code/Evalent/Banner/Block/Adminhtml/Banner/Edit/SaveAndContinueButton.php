<?php


namespace Evalent\Banner\Block\Adminhtml\Banner\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveAndContinueButton extends GenericButton implements ButtonProviderInterface
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
            'label' => __('Save and Continue Edit'),
            'class' => 'save',
            'data_attribute' => [
                'mage-init' => [
                    'button' => ['event' => 'saveAndContinueEdit'],
                ],
            ],
            'sort_order' => 80,
        ];
    }
}
