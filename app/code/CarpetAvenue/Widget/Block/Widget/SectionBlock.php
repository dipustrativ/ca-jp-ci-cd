<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */

declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

/**
 * CMS Static Section Block widget — wraps a CMS block in a full-width page
 * section with optional background/border and mobile visibility.
 * Ported from Evalent_SectionBlock (base project).
 */
class SectionBlock extends \Magento\Cms\Block\Widget\Block
{
    /**
     * Get value of widgets' border parameter
     *
     * @return bool
     */
    public function useBorder(): bool
    {
        return $this->getData('border') === 'true';
    }

    /**
     * Get value of widgets' background color parameter
     *
     * @return mixed|string
     */
    public function useBackgroundColor()
    {
        return $this->getData('background_color');
    }

    /**
     * Get value of widgets' mobile_hide parameter
     *
     * @return mixed|string
     */
    public function hideOnMobile()
    {
        return $this->getData('mobile_hide');
    }
}
