<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

/**
 * A fetched review photo. Photos still waiting on a download are never returned, so both
 * derivative paths always resolve to files on disk.
 */
class ReviewPhoto
{
    public function __construct(
        public readonly string $thumbPath,
        public readonly string $gridPath,
        public readonly ?int $width,
        public readonly ?int $height
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'thumb_path' => $this->thumbPath,
            'grid_path'  => $this->gridPath,
            'width'      => $this->width,
            'height'     => $this->height,
        ];
    }
}
