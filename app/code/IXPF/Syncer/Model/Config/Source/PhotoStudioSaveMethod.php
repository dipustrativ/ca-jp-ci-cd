<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PhotoStudioSaveMethod implements OptionSourceInterface
{
    /** Use Magento ORM — product->save() fires all observers/events (default) */
    const METHOD_ORM = '0';

    /** Use direct RAW DB queries — faster, bypasses ORM events/plugins */
    const METHOD_RAW_DB = '1';

    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::METHOD_ORM,    'label' => __('ORM (Default — product->save)')],
            ['value' => self::METHOD_RAW_DB,  'label' => __('Raw DB (Direct SQL — faster, no ORM events)')],
        ];
    }
}
