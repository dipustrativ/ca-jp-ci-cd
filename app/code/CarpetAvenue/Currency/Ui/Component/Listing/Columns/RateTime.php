<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace CarpetAvenue\Currency\Ui\Component\Listing\Columns;

class RateTime extends \Magento\Ui\Component\Listing\Columns\Date
{
    /**
     * @inheritdoc
     * @since 101.1.1
     */
    public function prepare()
    {
        $config = $this->getData('config');
        $config['dateFormat'] = 'MMM YYYY'; //$this->timezone->getDateTimeFormat(\IntlDateFormatter::MEDIUM);
        $this->setData('config', $config);

        parent::prepare();
    }
}
