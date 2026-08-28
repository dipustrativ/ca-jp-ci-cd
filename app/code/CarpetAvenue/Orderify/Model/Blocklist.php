<?php

namespace CarpetAvenue\Orderify\Model;

use CarpetAvenue\Orderify\Api\BlacklistInterface;
use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;

class Blocklist implements BlacklistInterface
{
    public function __construct(
        protected ResourceConnection $resourceConnection
    )
    {
    }

    /**
     * @param int $orderId
     * @param string $blacklistType
     * @param string|null $blacklistReason
     * @return bool
     */
    public function addToBlacklist(int $orderId, string $blacklistType, ?string $blacklistReason = null): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('carpetavenue_order_black_list');
        $blacklistValue = $this->getBlackListValueFromOrder($orderId, $blacklistType);

        //-- check if already exists
        $existingEntry = $this->getBlackList($blacklistType, $blacklistValue);
        if (!$existingEntry) {
            if ($blacklistValue === '') {
                throw new InvalidArgumentException('Blacklist value cannot be empty');
            }
            $data = [
                'blacklist_type' => $blacklistType,
                'blacklist_value' => $blacklistValue,
                'note' => $blacklistReason
            ];
            $connection->insert($tableName, $data);
        }

        return true;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function removeBlackList(int $id): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('carpetavenue_order_black_list');

        $where = ['entity_id = ?' => $id];
        $connection->delete($tableName, $where);

        return true;

    }

    /**
     * @param string $type
     * @param string $value
     * @return array
     */
    public function getBlackList(string $type, string $value): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('carpetavenue_order_black_list');

        $select = $connection->select()
            ->from($tableName)
            ->where('blacklist_type = ?', $type)
            ->where('blacklist_value = ?', $value)
            ->limit(1);
        $result = $connection->fetchRow($select);
        if ($result) {
            return $result;
        }
        return [];
    }

    /**
     * @param int $orderId
     * @param string $blacklistType
     * @return string
     */
    public function getBlackListValueFromOrder(int $orderId, string $blacklistType): string
    {
        $connection = $this->resourceConnection->getConnection();
        $addressTable = $connection->getTableName('sales_order_address');
        $orderTable = $connection->getTableName('sales_order');
        $blacklistValue = "";
        switch ($blacklistType) {
            case 'email':
                $select = $connection->select()
                    ->from($orderTable, ['customer_email'])
                    ->where('entity_id = ?', $orderId);
                $blacklistValue = $connection->fetchOne($select);
                break;
            case 'address':
                $select = $connection->select()
                    ->from(['soa' => $addressTable], ['street'])
                    ->join(
                        ['so' => $orderTable],
                        'soa.parent_id = so.entity_id',
                        []
                    )
                    ->where('so.entity_id = ?', $orderId);
                $blacklistValue = $connection->fetchOne($select);
                break;
            case  'ip':
                $select = $connection->select()
                    ->from($orderTable, ['x_forwarded_for'])
                    ->where('entity_id = ?', $orderId);
                $ip = $connection->fetchOne($select);
                if ($ip) {
                    $ipParts = explode(',', $ip);
                    $blacklistValue = trim($ipParts[0]);
                }
                break;
            default:
                throw new InvalidArgumentException('Invalid blacklist type');
        }
        return $blacklistValue;
    }

    /**
     * @param int $orderId
     * @return array
     */
    public function getBlackListFromOrder(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('carpetavenue_order_black_list');

        $addressValue = $this->getBlackListValueFromOrder($orderId, 'address');
        $emailValue = $this->getBlackListValueFromOrder($orderId, 'email');
        $ipValue = $this->getBlackListValueFromOrder($orderId, 'ip');

        $result = [];
        if ($exitsAddress = $this->getBlackList('address', $addressValue)) {
            $result[] = $exitsAddress;
        }
        if ($exitsEmail = $this->getBlackList('email', $emailValue)) {
            $result[] = $exitsEmail;
        }
        if ($exitsIp = $this->getBlackList('ip', $ipValue)) {
            $result[] = $exitsIp;
        }
        return $result;
    }
}