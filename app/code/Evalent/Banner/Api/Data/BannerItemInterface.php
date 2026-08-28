<?php

declare(strict_types=1);

namespace Evalent\Banner\Api\Data;

/**
 * Interface BannerItemInterface
 *
 * @package Evalent\Banner\Api\Data
 * @api
 */
interface BannerItemInterface
{

    /**
     * Get banner
     * @return \Evalent\Banner\Api\Data\BannerInterface|null
     */
    public function getBanner(): ?BannerInterface;

    /**
     * Set entity_id
     *
     * @param $entityId
     *
     */
    public function setEntityId($entityId);


    /**
     * Get Value of column field
     * @return string|null
     */
    public function getColumn(): ?string;

    /**
     * Set column
     *
     * @param $column
     *
     */
    public function setColumn($column);


    /**
     * Get If any effect, empty is default (ie. zoom)
     * @return string|null
     */
    public function getAnimationEffect(): ?string;

    /**
     * Set animation_effect
     *
     * @param $animationEffect
     *
     */
    public function setAnimationEffect($animationEffect);
}