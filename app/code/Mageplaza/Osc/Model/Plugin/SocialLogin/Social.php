<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Model\Plugin\SocialLogin;

use Mageplaza\Osc\Helper\Data;

/**
 * Class Social
 * @package Mageplaza\Osc\Model\Plugin\SocialLogin
 */
class Social
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * Social constructor.
     *
     * @param Data $helper
     */
    public function __construct(
        Data $helper
    ) {
        $this->helper = $helper;
    }

    /**
     * @param \Mageplaza\SocialLogin\Block\Popup\Social $subject
     * @param $result
     *
     * @return mixed
     */
    public function afterGetAvailableSocials(\Mageplaza\SocialLogin\Block\Popup\Social $subject, $result)
    {
        if (!$this->helper->isOscPage() || !$this->helper->isEnabled()) {
            return $result;
        }

        $oscRouter = "back_url/".$this->helper->getOscRoute();
        foreach ($result as $social => &$data) {
            if (isset($data['login_url'])) {
                $data['login_url'] .= $oscRouter;
            }
        }

        return $result;
    }
}
