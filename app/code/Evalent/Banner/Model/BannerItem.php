<?php

declare(strict_types=1);

namespace Evalent\Banner\Model;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Api\Data\BannerItemInterface;
use Magento\Framework\Exception\LocalizedException;

class BannerItem implements BannerItemInterface
{

    /** @var string */
    private $bannerId;

    /** @var string */
    private $column;

    /** @var string */
    private $animationEffect;

    /** @var null|BannerInterface  */
    private $banner = null;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    public function __construct(
        BannerRepositoryInterface $bannerRepository
    ) {
        $this->bannerRepository = $bannerRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getBanner(): ?BannerInterface
    {
        if ($this->banner !== null) {
            return $this->banner;
        }

        try {
            return $this->banner = $this->bannerRepository->get($this->bannerId);
        } catch (LocalizedException $e) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getColumn(): ?string
    {
        return $this->column;
    }

    /**
     * {@inheritdoc}
     */
    public function getAnimationEffect(): ?string
    {
        return $this->animationEffect;
    }

    /**
     * {@inheritdoc}
     */
    public function setEntityId($entityId)
    {
        $this->bannerId = $entityId;
        $this->banner = null; // clear local "cache" in case bannerId is reset
    }

    /**
     * {@inheritdoc}
     */
    public function setColumn($column)
    {
        $this->column = $column;
    }

    /**
     * {@inheritdoc}
     */
    public function setAnimationEffect($animationEffect)
    {
        $this->animationEffect = $animationEffect;
    }
}