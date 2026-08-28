<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Controller\Adminhtml\Campaign;

use CarpetAvenue\Productify\Helper\CampaignHelper;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use IntlDateFormatter;


class ActiveList extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context                  $context,
        protected CampaignHelper $campaignHelper
    )
    {
        parent::__construct($context);
    }


    public function execute()
    {

        $activeCampaigns = $this->campaignHelper->getActiveCampaigns();
        $html = '<table class="data-grid">
        <thead>
            <tr>
                <th class="data-grid-th">' . __('Campaign') . '</th>
                <th class="data-grid-th">' . __('Type') . '</th>
                <th class="data-grid-th">' . __('Store') . '</th>
                <th class="data-grid-th">' . __('Start Time') . '</th>
                <th class="data-grid-th">' . __('End Time') . '</th>
                <th class="data-grid-th">' . __('Status') . '</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($activeCampaigns as $campaign) {
            $html .= '<tr>
            <td>' . ($campaign['title'] ?? '') . '</td>
            <td>' . ($campaign['type'] ?? '') . '</td>
            <td>' . ($campaign['stores'] ?? '') . '</td>
            <td>' . ($campaign['start_date'] ?? '') . '</td>
            <td>' . ($campaign['end_date'] ?? '') . '</td>
            <td>' . ($campaign['status'] ?? '') . '</td>
        </tr>';
        }

        $html .= '</tbody></table>';

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        return $result->setContents($html);
    }

}