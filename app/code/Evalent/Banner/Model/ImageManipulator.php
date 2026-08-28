<?php

namespace Evalent\Banner\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class ImageManipulator
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;
    /**
     * @var \Magento\Framework\Image\AdapterFactory
     */
    private $imageFactory;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var \Magento\Framework\Api\Uploader
     */
    private $uploader;
    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    private $mediaDirectory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Image\AdapterFactory $imageFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Api\Uploader $uploader,
        LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->imageFactory = $imageFactory;
        $this->storeManager = $storeManager;
        $this->uploader = $uploader;
        $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->logger = $logger;
    }

    // pass imagename, width and height
    public function resize($image, $fileName, $baseTmpPath, $width = null, $height = null, $quality = 40)
    {
        $resizedURL = "";

        try {

            $imageResized = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath($baseTmpPath) . $image;
            $imageResize = $this->imageFactory->create();
            $imageResize->open($this->mediaDirectory->getAbsolutePath($baseTmpPath) . $image);
            $imageResize->constrainOnly(true);
            $imageResize->keepTransparency(true);
            $imageResize->keepFrame(false);
            $imageResize->keepAspectRatio(true);
            $imageResize->resize($width,$height);
            $imageResize->quality($quality);
            //destination folder
            $destination = $imageResized;
            //save image
            $imageResize->save($destination);

            $resizedURL = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA). $baseTmpPath . $image;

            return $resizedURL;

        } catch (Exception $e) {
            $this->logger->info('imageManipulator: '.$e);
            throw new LocalizedException(
                __('Something went wrong: '.$e)
            );
        }

        return '';

    }
}
