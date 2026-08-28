<?php

namespace CarpetAvenue\Orderify\Api;

interface BlacklistInterface
{

    public function addToBlacklist(int $orderId, string $blacklistType, ?string $blacklistReason = null): bool;

    public function getBlackList(string $type, string $value): array;

    public function removeBlackList(int $id): bool;

    public function getBlackListFromOrder(int $orderId): array;

}