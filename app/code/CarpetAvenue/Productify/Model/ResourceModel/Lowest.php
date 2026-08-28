<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class Lowest
{
    private const TBL = 'carpetavenue_productify_omni_lowest';

    public function __construct(private ResourceConnection $res) {}

    public function upsert(
        int $productId,
        int $storeId,
        ?float $price,
        ?string $since,
        ?string $formattedText,
        string $windowStart,
        string $windowEnd
    ): void {
        $conn = $this->res->getConnection();

        $data = [
            'product_id'       => $productId,
            'store_id'         => $storeId,
            'lowest_30d_price' => $price,
            'lowest_30d_since' => $since,
            'formatted_text'   => $formattedText,
            'window_start'     => $windowStart,
            'window_end'       => $windowEnd,
            'updated_at'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $conn->insertOnDuplicate(
            self::TBL,
            $data,
            ['lowest_30d_price','lowest_30d_since','formatted_text','window_start','window_end','updated_at']
        );
    }

    public function fetch(int $productId, int $storeId): ?array
    {
        $conn = $this->res->getConnection();
        $sel  = $conn->select()
            ->from(self::TBL)
            ->where('product_id = ?', $productId)
            ->where('store_id = ?', $storeId)
            ->limit(1);
        $row = $conn->fetchRow($sel);
        return $row ?: null;
    }

    /** Candidates where minimum likely aged out */
    public function fetchAgedOutCandidates(int $limit = 1000): array
    {
        $conn = $this->res->getConnection();
        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s');

        $sel = $conn->select()
            ->from(self::TBL, ['product_id','store_id'])
            ->where('lowest_30d_since IS NOT NULL')
            ->where('lowest_30d_since < ?', $threshold)
            ->limit($limit);

        return $conn->fetchAll($sel) ?: [];
    }
}
