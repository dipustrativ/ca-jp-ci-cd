<?php


namespace Evalent\Banner\Block\Adminhtml\Banner\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{


    /**
     * @return array
     */
    public function getButtonData()
    {
        $user = $this->authSession->getUser();
        if (!$this->policy->isAllowed($user->getRole()->getId(),
            'Evalent_Banner::Banner_delete')
        ) {
            return [];
        }
        $data = [];
        if ($this->getModelId()) {
            $data = [
                'label'      => __('Delete Banner'),
                'class'      => 'delete',
                'on_click'   => 'deleteConfirm(\'' . __(
                        'Are you sure you want to do this?'
                    ) . '\', \'' . $this->getDeleteUrl() . '\')',
                'sort_order' => 20,
            ];
        }
        return $data;
    }

    /**
     * Get URL for delete button
     *
     * @return string
     */
    public function getDeleteUrl()
    {
        return $this->getUrl('*/*/delete',
            ['entity_id' => $this->getModelId()]);
    }
}
