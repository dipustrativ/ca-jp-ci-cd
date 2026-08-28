<?php

declare(strict_types=1);

namespace Evalent\Banner\Model;

use Evalent\Banner\Model\Banner\ImageUploader;
use Magento\Framework\ObjectManagerInterface;

class UploaderPool
{
    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var array
     */
    protected $uploaders;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param array $uploaders
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        array $uploaders = []
    ) {
        $this->objectManager = $objectManager;
        $this->uploaders     = $uploaders;
    }

    /**
     * @param $type
     *
     * @return ImageUploader
     * @throws \Exception
     */
    public function getUploader($type)
    {
        if (!isset($this->uploaders[$type])) {
            throw new \Exception("Uploader not found for type: ".$type);
        }
        if (!is_object($this->uploaders[$type])) {
            $this->uploaders[$type] = $this->objectManager->create($this->uploaders[$type]);
        }
        $uploader = $this->uploaders[$type];
        if (!($uploader instanceof ImageUploader)) {
            throw new \Exception("Uploader for type {$type} not instance of " . ImageUploader::class);
        }
        return $uploader;
    }
}
