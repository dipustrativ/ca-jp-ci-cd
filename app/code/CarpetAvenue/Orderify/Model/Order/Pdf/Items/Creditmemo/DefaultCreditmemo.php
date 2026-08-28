<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\Order\Pdf\Items\Creditmemo;

use Magento\Customer\Model\Vat;
use Magento\Framework\App\RequestInterface;

class DefaultCreditmemo extends \Magento\Sales\Model\Order\Pdf\Items\Creditmemo\DefaultCreditmemo
{

    /**
     * @var \Magento\Customer\Model\Vat
     */
    protected $vatHelper;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    public function __construct(
        Vat $vatHelper,
        RequestInterface $request,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Tax\Helper\Data $taxData,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Filter\FilterManager $filterManager,
        \Magento\Framework\Stdlib\StringUtils $string,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $taxData, $filesystem,
            $filterManager, $string, $resource, $resourceCollection, $data);
        $this->vatHelper = $vatHelper;
        $this->request = $request;
    }

    /**
     * Draw process
     *
     * @return void
     */
    public function draw()
    {
        $order = $this->getOrder();
        $item = $this->getItem();
        $pdf = $this->getPdf();
        $page = $this->getPage();
        $lines = [];

        // draw Product name
        $lines[0] = [
            [
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                'text' => $this->string->split(html_entity_decode($item->getName()), 35, true, true),
                'feed' => 35
            ]
        ];

        // draw SKU
        $lines[0][] = [
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            'text' => $this->string->split(html_entity_decode($this->getSku($item)), 17),
            'feed' => 255,
            'align' => 'right',
        ];

        // draw Total (ex)
        $lines[0][] = [
            'text' => $order->formatPriceTxt($item->getRowTotalInclTax()),
            'feed' => 330,
            'font' => 'bold',
            'align' => 'right',
        ];

        // draw Discount
        $lines[0][] = [
            'text' => $order->formatPriceTxt(-$item->getDiscountAmount()),
            'feed' => 380,
            'font' => 'bold',
            'align' => 'right',
        ];

        // draw QTY
        $lines[0][] = ['text' => $item->getQty() * 1, 'feed' => 445, 'font' => 'bold', 'align' => 'right'];

        //        // draw Tax
        // draw Tax
        if (($order->getCustomerTaxvat() == "" && $order->getTaxAmount() > 0
                && $this->vatHelper->isCountryInEU($order->getShippingAddress()->getCountryId())
                && $order->getShippingAddress()->getCountryId() != "DE"
                && !$this->request->getParam('legacy')
            ) || $order->getShippingAddress()->getCountryId() == "CH"
              || $order->getShippingAddress()->getCountryId() == "JP"
        ) {

        } else {
            $lines[0][] = [
                'text'  => $order->formatPriceTxt($item->getTaxAmount()),
                'feed'  => 495,
                'font'  => 'bold',
                'align' => 'right',
            ];
        }

        // draw Total (inc)
        $subtotal = $item->getRowTotal() +
            $item->getTaxAmount() +
            $item->getDiscountTaxCompensationAmount() -
            $item->getDiscountAmount();
        $lines[0][] = [
            'text' => $order->formatPriceTxt($subtotal),
            'feed' => 565,
            'font' => 'bold',
            'align' => 'right',
        ];

        // draw options
        $options = $this->getItemOptions();
        if ($options) {
            foreach ($options as $option) {
                // draw options label
                $lines[][] = [
                    'text' => $this->string->split($this->filterManager->stripTags($option['label']), 40, true, true),
                    'font' => 'italic',
                    'feed' => 35,
                ];

                // draw options value
                $printValue = isset(
                    $option['print_value']
                ) ? $option['print_value'] : $this->filterManager->stripTags(
                    $option['value']
                );
                $lines[][] = ['text' => $this->string->split($printValue, 30, true, true), 'feed' => 40];
            }
        }

        $lineBlock = ['lines' => $lines, 'height' => 20];

        $page = $pdf->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
        $this->setPage($page);
    }
}
