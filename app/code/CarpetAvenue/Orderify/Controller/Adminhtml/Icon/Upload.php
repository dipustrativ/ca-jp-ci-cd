<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Icon;

use CarpetAvenue\Orderify\Model\PaymentIcon\IconRepository;
use CarpetAvenue\Orderify\Model\PaymentIcon\SvgSanitizer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Handles SVG/PNG uploads from the admin payment-icon chooser.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::payment_icons';

    private const FILE_FIELD = 'icon';

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var UploaderFactory */
    private $uploaderFactory;

    /** @var IconRepository */
    private $iconRepository;

    /** @var SvgSanitizer */
    private $svgSanitizer;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        UploaderFactory $uploaderFactory,
        IconRepository $iconRepository,
        SvgSanitizer $svgSanitizer
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->uploaderFactory = $uploaderFactory;
        $this->iconRepository = $iconRepository;
        $this->svgSanitizer = $svgSanitizer;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $uploader = $this->uploaderFactory->create(['fileId' => self::FILE_FIELD]);
            $uploader->setAllowedExtensions(IconRepository::ALLOWED_EXTENSIONS);
            // Same-named upload replaces the existing icon instead of saving
            // a "name_1.ext" copy — filename = method code must stay stable.
            $uploader->setAllowRenameFiles(false);
            $uploader->setFilesDispersion(false);

            $files = $this->getRequest()->getFiles(self::FILE_FIELD);
            $originalName = \Magento\Framework\File\Uploader::getCorrectFileName(
                (string)($files['name'] ?? '')
            );
            $replaced = $originalName !== ''
                && $this->iconRepository->fileExists(IconRepository::MEDIA_SUBDIR . '/' . $originalName);

            $absDir = $this->iconRepository->getAbsoluteDir();
            $saved = $uploader->save($absDir);

            if (empty($saved['file'])) {
                throw new \RuntimeException('Upload failed.');
            }

            $name = $saved['file'];
            $this->svgSanitizer->sanitizeFile(rtrim($absDir, '/') . '/' . $name);

            $relative = IconRepository::MEDIA_SUBDIR . '/' . $name;
            return $result->setData([
                'success' => true,
                'name' => $name,
                'path' => $relative,
                'url' => $this->iconRepository->getUrl($relative),
                'replaced' => $replaced,
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
