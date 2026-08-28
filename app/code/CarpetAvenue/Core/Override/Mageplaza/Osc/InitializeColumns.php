<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Override\Mageplaza\Osc;

use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Mageplaza\Osc\Setup\Patch\Data\InitializeColumns as MageplazaInitializeColumns;

/**
 * Mageplaza OSC's InitializeColumns data patch adds columns to the flat
 * order/quote tables via addAttribute — i.e. it issues DDL. Magento wraps
 * data patches in a DB transaction and MySQL 8 rejects DDL inside a
 * transaction ("DDL statements are not allowed in transactions"), which
 * aborts setup:upgrade.
 *
 * Implementing NonTransactionableInterface tells Magento's PatchApplier to run
 * this patch outside the transaction (see PatchApplier::applyDataPatch), which
 * is the correct handling for a DDL-performing data patch. Wired in through a
 * di.xml preference so the vendor module stays untouched.
 */
class InitializeColumns extends MageplazaInitializeColumns implements NonTransactionableInterface
{
    /**
     * Keep the original patch as an alias so the patch history dedups against
     * it and the patch is never applied twice, regardless of which class name
     * PatchApplier records.
     *
     * @return string[]
     */
    public function getAliases()
    {
        return array_merge(parent::getAliases(), [MageplazaInitializeColumns::class]);
    }
}
