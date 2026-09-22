<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Overwrite\Plugin;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * Packing slip PDF renderer.
 *
 * Core renders every string with lib/internal/GnuFreeFont/FreeSerif*.ttf, which carries no
 * CJK glyphs, so Japanese addresses, labels and product names come out as .notdef boxes.
 * The same cid0jp.ttf the invoice renderer uses covers both Latin and Japanese, so the font
 * getters are swapped wholesale.
 */
class Shipment extends \Magento\Sales\Model\Order\Pdf\Shipment
{
    /**
     * @var Reader
     */
    private $moduleReader;

    /**
     * @var File
     */
    private $fileDriver;

    /**
     * @var string|null
     */
    private $japaneseFontPath;

    /**
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\Stdlib\StringUtils $string
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Sales\Model\Order\Pdf\Config $pdfConfig
     * @param \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory
     * @param \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Sales\Model\Order\Address\Renderer $addressRenderer
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param Reader $moduleReader
     * @param File $fileDriver
     * @param array $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Sales\Model\Order\Pdf\Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Store\Model\App\Emulation $appEmulation,
        Reader $moduleReader,
        File $fileDriver,
        array $data = []
    ) {
        $this->moduleReader = $moduleReader;
        $this->fileDriver = $fileDriver;
        parent::__construct(
            $paymentData,
            $string,
            $scopeConfig,
            $filesystem,
            $pdfConfig,
            $pdfTotalFactory,
            $pdfItemsFactory,
            $localeDate,
            $inlineTranslation,
            $addressRenderer,
            $storeManager,
            $appEmulation,
            $data
        );
    }

    /**
     * Set font as regular
     *
     * @param  \Zend_Pdf_Page|\Zend_Pdf_Style $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontRegular($object, $size = 7)
    {
        return $this->applyFont($object, $size, 'FreeSerif.ttf');
    }

    /**
     * Set font as bold
     *
     * @param  \Zend_Pdf_Page|\Zend_Pdf_Style $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontBold($object, $size = 7)
    {
        return $this->applyFont($object, $size, 'FreeSerifBold.ttf');
    }

    /**
     * Set font as italic
     *
     * @param  \Zend_Pdf_Page|\Zend_Pdf_Style $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontItalic($object, $size = 7)
    {
        return $this->applyFont($object, $size, 'FreeSerifItalic.ttf');
    }

    /**
     * Assign the Japanese font, falling back to the core GNU FreeFont file if it is missing
     *
     * @param  \Zend_Pdf_Page|\Zend_Pdf_Style $object
     * @param  int $size
     * @param  string $fallbackFile
     * @return \Zend_Pdf_Resource_Font
     */
    private function applyFont($object, $size, $fallbackFile)
    {
        $path = $this->getJapaneseFontPath()
            ?: $this->_rootDirectory->getAbsolutePath('lib/internal/GnuFreeFont/' . $fallbackFile);

        $font = \Zend_Pdf_Font::fontWithPath($path);
        $object->setFont($font, $size);

        return $font;
    }

    /**
     * Resolve cid0jp.ttf inside this module's view dir
     *
     * @return string|null
     */
    private function getJapaneseFontPath()
    {
        if ($this->japaneseFontPath === null) {
            $path = $this->moduleReader->getModuleDir(
                Dir::MODULE_VIEW_DIR,
                'Evalent_InvoicePaymentMods'
            ) . '/cid0jp.ttf';

            $this->japaneseFontPath = $this->fileDriver->isExists($path) ? $path : '';
        }

        return $this->japaneseFontPath ?: null;
    }
}
