<?php

namespace Evalent\ProductCertificate\Helper;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Mpdf\Mpdf;

class Certificate extends AbstractHelper
{

    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    protected $directoryList;

    /** @var \Evalent\ProductCertificate\Api\ConfigInterface */
    protected $config;

    /** @var \Magento\Store\Model\App\Emulation */
    protected $emulation;

    /** @var \Magento\Eav\Model\Config */
    protected $eavConfig;

    /**
     * @var LayoutInterface
     */
    private $_layout;

    /** @var ProductRepository $_productRepository */
    private $_productRepository;

    /** @var \Magento\Framework\Module\Dir\Reader */
    protected $moduleReader;

    public function __construct(
        Context $context,
        LayoutInterface $layout,
        ProductRepository $productRepository,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Evalent\ProductCertificate\Api\ConfigInterface $config,
        \Magento\Store\Model\App\Emulation $emulation,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\Module\Dir\Reader $moduleReader
    ) {
        $this->_layout = $layout;
        $this->_productRepository = $productRepository;
        parent::__construct($context);
        $this->directoryList = $directoryList;
        $this->config = $config;
        $this->emulation = $emulation;
        $this->eavConfig = $eavConfig;
        $this->moduleReader = $moduleReader;
    }

    /**
     * Creates a PDF containing certificates of authenticity generated for the
     * specified order
     *
     * @param OrderInterface $order
     *
     * @return mixed
     */
    public function create(OrderInterface $order, $skipRules = false)
    {

        /** @var \Evalent\ProductCertificate\Block\Certificate $block */
        $block = $this->_layout->createBlock('Evalent\ProductCertificate\Block\Certificate');
        $this->eavConfig->clear();
        $products = $this->_getProducts($order, $skipRules);
        $block->setProducts($products);
        $block->setStoreId($order->getStoreId());
        $this->emulation->startEnvironmentEmulation($order->getStoreId(), \Magento\Framework\App\Area::AREA_FRONTEND, true);
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];
        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A5-P',
            'fontDir' => array_merge($fontDirs, [
                $this->moduleReader->getModuleDir(
                    \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                    'Evalent_ProductCertificate'
                ),
            ]),
            'fontdata' => $fontData + [
                    'cid0jp' => [
                        'R' => 'cid0jp.ttf',
                    ],
                ],
            'default_font' => 'cid0jp',
            'tempDir' => $this->directoryList->getPath('tmp')
        ]);
        /** @var \Evalent\ProductCertificate\Block\Details $details */
        $details = $this->_layout->createBlock('Evalent\ProductCertificate\Block\Details');
        $details->setStoreId($order->getStoreId());

        $footerText = "<div class=\"footer\">" . $details->getSignatureHtml() . "<div class=\"inner\">" . $this->config->getFooterText($order->getStoreId()) . " <span class=\"date\">". date('Y-m-d')."</span></div></div>";
        $mpdf->setHTMLFooter($footerText);
        $mpdf->WriteHTML($block->toHtml());

        $this->emulation->stopEnvironmentEmulation();
        return ['pdf' => $mpdf, 'productCount' => count($products)];
    }

    /**
     * Returns the ordered products loaded in the order store & in read mode
     *
     * @param OrderInterface $order
     *
     * @return Product[]
     */
    private function _getProducts(OrderInterface $order, $skipRules = false)
    {
        $products = [];
        foreach ($order->getItems() as $item) {
            try {
                // Load the product for the store
                /** @var Product $product */
                $product = $this->_productRepository->get(
                    $item->getSku(),
                    false,
                    $order->getStoreId()
                );

                if ($skipRules ||($product && $item->getBasePriceInclTax() >= $this->config->getRowPriceLimit($order->getStoreId()) && $this->config->isValidProductionType($product->getProductionType(),$order->getStoreId()))) {
                    $products[] = $product;
                }
            } catch (NoSuchEntityException $e) {
            }
        }

        return $products;
    }
}
