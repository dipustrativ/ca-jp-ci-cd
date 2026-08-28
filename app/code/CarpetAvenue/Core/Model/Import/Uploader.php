<?php
declare(strict_types=1);

namespace CarpetAvenue\Core\Model\Import;

/**
 * Adds webp support to product import image uploads.
 *
 * CarpetAvenue_Utilities' Web Images feature only covers admin UI uploads
 * (Framework\File\Uploader, gallery processor, wysiwyg); the CSV import path
 * uses this separate uploader with its own hardcoded mime map.
 */
class Uploader extends \Magento\CatalogImportExport\Model\Import\Uploader
{
    /**
     * @var array
     */
    protected $_allowedMimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
}
