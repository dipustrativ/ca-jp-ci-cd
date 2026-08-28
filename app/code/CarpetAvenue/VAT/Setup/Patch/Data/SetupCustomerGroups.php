<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Renames the three default Magento customer groups to the CarpetAvenue B2B layout
 * WITHOUT changing their ids, so existing hardcoded references stay valid:
 *   1 -> Consumer, 2 -> Business, 3 -> Tax-Free  (id 0 "NOT LOGGED IN" untouched).
 * Tax-Free must stay id 3 (Productify\Block\Pricing\Render\Amount::TAX_FREE_GROUP_ID).
 * Idempotent: only renames a group whose code currently differs.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Setup\Patch\Data;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SetupCustomerGroups implements DataPatchInterface
{
    public const GROUP_CONSUMER = 1;
    public const GROUP_BUSINESS = 2;
    public const GROUP_TAX_FREE = 3;

    /** @var array<int,string> id => target group name */
    private const TARGET_NAMES = [
        self::GROUP_CONSUMER => 'Consumer',
        self::GROUP_BUSINESS => 'Business',
        self::GROUP_TAX_FREE => 'Tax-Free',
    ];

    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        foreach (self::TARGET_NAMES as $groupId => $name) {
            try {
                $group = $this->groupRepository->getById($groupId);
            } catch (NoSuchEntityException $e) {
                // Non-standard install without this default group – nothing to rename.
                continue;
            }

            if ($group->getCode() !== $name) {
                $group->setCode($name);
                $this->groupRepository->save($group);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
