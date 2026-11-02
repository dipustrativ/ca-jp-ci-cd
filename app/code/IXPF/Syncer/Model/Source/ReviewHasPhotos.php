<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Yes/No options for the has_photos grid column.
 *
 * Must implement Data\OptionSourceInterface, not the Option\ArrayInterface the other sources in
 * this directory use. Those feed system.xml dropdowns, which accept either. A grid column is
 * different: Column::prepare() hands the source to AbstractOptionsField, which calls
 * toOptionArray() only when it sees an OptionSourceInterface and otherwise treats the source as
 * an array. Magento\Config\Model\Config\Source\Yesno is an Option\ArrayInterface, so it takes
 * that second path and the dropdown and cells come out empty with no error anywhere.
 */
class ReviewHasPhotos implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => __('Yes')],
            ['value' => 0, 'label' => __('No')],
        ];
    }
}
