<?php

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Config\Model\Config\Backend\File;
class GoogleBucketJsonFile extends File
{
    protected $_allowedExtensions = ['json'];

    public function beforeSave()
    {
        $value = $this->getValue();
        $tmpName = $this->_requestData->getTmpName($this->getPath());
        $filePath = '';

        //-- Existing file re-saved without a new upload: the form re-posts the
        //-- saved name as value[value] with no tmp_name. parent::beforeSave()
        //-- would validate it via setValueAfterValidation(), which checks
        //-- MEDIA_DIR while we store in VAR_DIR -> "Invalid file name". Handle
        //-- it here and never let the parent run that check.
        if (!$tmpName && is_array($value) && empty($value['tmp_name'])) {
            if (!empty($value['delete'])) {
                $this->setValue('');
                return $this;
            }
            if (!empty($value['value'])) {
                $fileName = basename($value['value']);
                if (!file_exists($this->_getUploadDir() . '/' . $fileName)) {
                    throw new LocalizedException(__('Existing JSON file not found.'));
                }
                $this->setValue($fileName);
                return $this;
            }
        }

        if ($tmpName) {
            //-- New uploaded file
            $filePath = $tmpName;
        } elseif (is_array($value) && !empty($value['tmp_name'])) {
            //-- New uploaded file
            $filePath = $value['tmp_name'];
        } elseif (is_string($value) && !empty($value)) {
            //-- Existing file already saved
            $filePath = $this->_getUploadDir() . '/' . $value;
            if (!file_exists($filePath)) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Existing JSON file not found.'));
            }
        } else {
            //-- No file uploaded, skip validation
            return parent::beforeSave();
        }

        //-- Read and decode JSON
        $jsonData = file_get_contents($filePath);
        $data = json_decode($jsonData, true);
        if ($data === null) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid JSON file.'));
        }

        //-- Required keys
        $requiredKeys = [
            'project_id', 'type', 'private_key_id', 'private_key',
            'client_email', 'client_id', 'auth_uri', 'token_uri',
            'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain'
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Missing key "%1" in JSON file.', $key));
            }
        }

        parent::beforeSave();
    }

    /**
     * Restrict the uploader to JSON files.
     *
     * Must be declared here: Magento\Config\Model\Config\Backend\File never reads
     * $_allowedExtensions, it calls _getAllowedExtensions() (empty in the parent).
     * CarpetAvenue_Utilities' Web Images feature plugs beforeSetAllowedExtensions()
     * and merges svg/webp into whatever it gets, so an empty list turns into an
     * image-only allow list and every .json upload dies with "Disallowed file type."
     *
     * @return string[]
     */
    protected function _getAllowedExtensions()
    {
        return $this->_allowedExtensions;
    }

    protected function _getUploadDir()
    {
        $mediaDirectory = $this->_filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        return $mediaDirectory->getAbsolutePath('persistent/google/bucket/config');
    }
}