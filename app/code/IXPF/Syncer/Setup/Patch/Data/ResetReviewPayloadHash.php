<?php

declare(strict_types=1);

namespace IXPF\Syncer\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Forces one rewrite of every stored review, so text cut short at an emoji can be repaired.
 *
 * Before TextEncoding, a 4-byte character truncated the stored text (MySQL 8) or became "?"
 * (MariaDB). The payload hash is taken from the JSON as received, so an IXPF re-push of the same
 * review matches it and is skipped - the damaged text would stay forever. An empty hash matches
 * nothing: the next push of each pool rewrites its reviews once, encoded, and stores the real hash
 * again. Photos and thumbnails keep their files because their bucket paths do not change.
 */
class ResetReviewPayloadHash implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->update($this->moduleDataSetup->getTable('ixpf_review'), ['payload_hash' => '']);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
