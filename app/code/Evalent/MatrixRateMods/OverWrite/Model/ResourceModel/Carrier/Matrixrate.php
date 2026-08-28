<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\OverWrite\Model\ResourceModel\Carrier;

use Exception;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Matrixrate extends \WebShopApps\MatrixRate\Model\ResourceModel\Carrier\Matrixrate
{

    private ReadFactory $readFactory;

    public function __construct(
        Context $context,
        LoggerInterface $logger,
        ScopeConfigInterface $coreConfig,
        StoreManagerInterface $storeManager,
        \WebShopApps\MatrixRate\Model\Carrier\Matrixrate $carrierMatrixrate,
        \Magento\Directory\Model\ResourceModel\Country\CollectionFactory $countryCollectionFactory,
        CollectionFactory $regionCollectionFactory,
        ReadFactory $readFactory,
        Filesystem $filesystem,
        $resourcePrefix = null
    ) {
        parent::__construct($context, $logger, $coreConfig, $storeManager, $carrierMatrixrate, $countryCollectionFactory, $regionCollectionFactory, $readFactory, $filesystem, $resourcePrefix);
        $this->readFactory = $readFactory;
    }

    public function getRate(RateRequest $request, $zipRangeSet = false)
    {
        $adapter = $this->getConnection();
        $shippingData=[];
        $postcode = trim($request->getDestPostcode() ?? ""); //SHQ18-1978
        if ($zipRangeSet && is_numeric($postcode)) {
            #  Want to search for postcodes within a range. SHQ18-98 Can't use bind. Will convert int to string
            $zipSearchString = ' AND ' . (int)$postcode . ' BETWEEN dest_zip AND dest_zip_to ';
        } else {
            $zipSearchString = " AND :postcode LIKE dest_zip ";
        }

        for ($j=0; $j<8; $j++) {
            $select = $adapter->select()->from(
                $this->getMainTable()
            )->where(
                'website_id = :website_id'
            )->order(
                ['dest_country_id DESC', 'dest_region_id DESC', 'dest_zip DESC', 'condition_from_value DESC']
            );

            $zoneWhere='';
            $bind=[];
            switch ($j) {
                case 0: // country, region, city, postcode
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = :region_id AND STRCMP(LOWER(dest_city),LOWER(:city))= 0 " . $zipSearchString;
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':region_id' => (int)$request->getDestRegionId(),
                        ':city' => $request->getDestCity(),
                        ':postcode' => $postcode,
                    ];
                    break;
                case 1: // country, region, no city, postcode
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = :region_id AND dest_city='*' "
                        . $zipSearchString;
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':region_id' => (int)$request->getDestRegionId(),
                        ':postcode' => $postcode,
                    ];
                    break;
                case 2: // country, state, city, no postcode
                    $zoneWhere = "dest_country_id = :country_id AND dest_region_id = :region_id AND STRCMP(LOWER(dest_city),LOWER(:city))= 0 AND dest_zip ='*'";
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':region_id' => (int)$request->getDestRegionId(),
                        ':city' => $request->getDestCity(),
                    ];
                    break;
                case 3: //country, city, no region, no postcode
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = '0' AND STRCMP(LOWER(dest_city),LOWER(:city))= 0 AND dest_zip ='*'";
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':city' => $request->getDestCity(),
                    ];
                    break;
                case 4: // country, postcode
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = '0' AND dest_city ='*' "
                        . $zipSearchString;
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':postcode' => $postcode,
                    ];
                    break;
                case 5: // country, region
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = :region_id  AND dest_city ='*' AND dest_zip ='*'";
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                        ':region_id' => (int)$request->getDestRegionId(),
                    ];
                    break;
                case 6: // country
                    $zoneWhere =  "dest_country_id = :country_id AND dest_region_id = '0' AND dest_city ='*' AND dest_zip ='*'";
                    $bind = [
                        ':country_id' => $request->getDestCountryId(),
                    ];
                    break;
                case 7: // nothing
                    $zoneWhere =  "dest_country_id = '0' AND dest_region_id = '0' AND dest_city ='*' AND dest_zip ='*'";
                    break;
            }

            $select->where($zoneWhere);

            $bind[':website_id'] = (int)$request->getWebsiteId();
            $bind[':condition_name'] = $request->getConditionMRName();

            //SHQ18-1978
            $condition = $request->getData($request->getConditionMRName());

            if ($condition == null || $condition == "") {
                $condition = 0;
            }
            $bind[':condition_value'] = $condition;

            $select->where('condition_name = :condition_name');
            $select->where('condition_from_value < :condition_value');
            $select->where('condition_to_value >= :condition_value');

            $this->logger->debug('SQL Select: ', $select->getPart('where'));
            $this->logger->debug('Bindings: ', $bind);

            $results = $adapter->fetchAll($select, $bind);
            if (empty($results)) {
                if($bind[':condition_name'] == 'package_weight') {
                    $condition = $request->getData('package_value');
                    $bind[':condition_name'] = 'package_value';
                } else {
                    $condition = $request->getData('package_weight');
                    $bind[':condition_name'] = 'package_weight';
                }
                if ($condition == null || $condition == "") {
                    $condition = 0;
                }
                $bind[':condition_value'] = $condition;
                $results = $adapter->fetchAll($select, $bind);
            }

            if (!empty($results)) {
                $this->logger->debug('SQL Results: ', $results);
                foreach ($results as $data) {
                    $shippingData[]=$data;
                }
                break;
            }
        }
        return $shippingData;
    }

    public function uploadAndImport(DataObject $object)
    {
        //M2-24
        $importFieldData = $object->getFieldsetDataValue('import');
        if (empty($importFieldData['tmp_name'])) {
            return $this;
        }

        $website = $this->storeManager->getWebsite($object->getScopeId());
        $csvFile = $importFieldData['tmp_name'];

        $this->importWebsiteId = (int)$website->getId();
        $this->importUniqueHash = [];
        $this->importErrors = [];
        $this->importedRows = 0;

        //M2-20
        $tmpDirectory = ini_get('upload_tmp_dir') ? $this->readFactory->create(ini_get('upload_tmp_dir'))
            : $this->filesystem->getDirectoryRead(DirectoryList::SYS_TMP);
        $path = $tmpDirectory->getRelativePath($csvFile);
        $stream = $tmpDirectory->openFile($path);

        // check and skip headers
        $headers = $stream->readCsv();
        if ($headers === false || count($headers) < 5) {
            $stream->close();
            throw new LocalizedException(__('Please correct Matrix Rates File Format.'));
        }

        if ($object->getData('groups/matrixrate/fields/condition_name/inherit') == '1') {
            $conditionName = (string)$this->coreConfig->getValue('carriers/matrixrate/condition_name', 'default');
        } else {
            $conditionName = $object->getData('groups/matrixrate/fields/condition_name/value');
        }
        $this->importConditionName = $conditionName;

        $adapter = $this->getConnection();
        $adapter->beginTransaction();

        try {
            $rowNumber = 1;
            $importData = [];

            $this->_loadDirectoryCountries();
            $this->_loadDirectoryRegions();

            // MNB-566 delete old data by website. Changed to delete all data for website.
            // Thanks to https://github.com/JeroenVanLeusden for contribution
            $adapter->delete($this->getMainTable(), ['website_id = ?' => $this->importWebsiteId, 'condition_name = ?' => $conditionName]);

            while (false !== ($csvLine = $stream->readCsv())) {
                $rowNumber++;

                if (empty($csvLine)) {
                    continue;
                }

                $row = $this->_getImportRow($csvLine, $rowNumber);
                if ($row !== false) {
                    $importData[] = $row;
                }

                if (count($importData) == 5000) {
                    $this->_saveImportData($importData);
                    $importData = [];
                }
            }
            $this->_saveImportData($importData);
            $stream->close();
        } catch (LocalizedException $e) {
            $adapter->rollback();
            $stream->close();
            throw new LocalizedException(__($e->getMessage()));
        } catch (Exception $e) {
            $adapter->rollback();
            $stream->close();
            $this->logger->critical($e);
            throw new LocalizedException(
                __('Something went wrong while importing matrix rates.')
            );
        }

        $adapter->commit();

        if ($this->importErrors) {
            $error = __(
                'We couldn\'t import this file because of these errors: %1',
                implode(" \n", $this->importErrors)
            );
            throw new LocalizedException($error);
        }

        return $this;
    }
}
