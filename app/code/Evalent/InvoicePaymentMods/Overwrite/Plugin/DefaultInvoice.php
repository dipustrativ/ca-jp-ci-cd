<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Overwrite\Plugin;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Vat;
use Magento\Framework\App\RequestInterface;

class DefaultInvoice extends \Magento\Sales\Model\Order\Pdf\Items\Invoice\DefaultInvoice
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
        RequestInterface $request,
        Vat $vatHelper,
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
   * Draw item line
   *
   * @return void
   */
  public function draw()
  {
      $order = $this->getOrder();
      /** @var \Magento\Sales\Model\Order\Invoice\Item $item */
      $item = $this->getItem();
      $pdf = $this->getPdf();
      $page = $this->getPage();
      $lines = [];

      // We always want the simple product name
      // Null-safe: getProduct() returns null when the catalog product was deleted.
      if ($item->getOrderItem()->getProduct()?->getTypeInstance() instanceof Configurable) {
          $name = $item->getOrderItem()->getChildrenItems()[0]->getName();
      } else {
          $name = $item->getName();
      }

      // draw Product name
      $lines[0] = [
          [
              // phpcs:ignore Magento2.Functions.DiscouragedFunction
              'text' => $this->string->split(html_entity_decode($name), 35, true, true),
              'feed' => 35
          ]
      ];

      // draw SKU
      $lines[0][] = [
          // phpcs:ignore Magento2.Functions.DiscouragedFunction
          'text' => $this->string->split(html_entity_decode($this->getSku($item)), 17),
          'feed' => 290,
          'align' => 'right',
      ];

      // draw QTY
      $lines[0][] = ['text' => $item->getQty() * 1, 'feed' => 430, 'align' => 'right'];

      // draw item Prices
      $i = 0;
      $prices = $this->getItemPricesForDisplay();
      $feedPrice = 390;
      $feedSubtotal = $feedPrice + 170;
      foreach ($prices as $priceData) {
          if (isset($priceData['label'])) {
              // draw Price label
              $lines[$i][] = ['text' => $priceData['label'], 'feed' => $feedPrice, 'align' => 'right'];
              // draw Subtotal label
              $lines[$i][] = ['text' => $priceData['label'], 'feed' => $feedSubtotal, 'align' => 'right'];
              $i++;
          }
          // draw Price
          $lines[$i][] = [
              'text' => $priceData['price'],
              'feed' => $feedPrice,
              'font' => 'bold',
              'align' => 'right',
          ];
          // draw Subtotal
          $lines[$i][] = [
              'text' => $priceData['subtotal'],
              'feed' => $feedSubtotal,
              'font' => 'bold',
              'align' => 'right',
          ];
          $i++;
      }

      // draw Tax
      if ($this->request->getParam("legacy")
          || ($order->getShippingAddress()->getCountryId() != "JP"
              && $order->getShippingAddress()->getCountryId() != "NO")
      ) {
          $lines[0][] = [
              'text'  => $order->formatPriceTxt($item->getTaxAmount()),
              'feed'  => 485,
              'font'  => 'bold',
              'align' => 'right',
          ];
      }

      // custom options
      $options = $this->getItemOptions();
      // We dont want to write out the options on configurable products
      if ($options && !($item->getOrderItem()->getProduct()?->getTypeInstance() instanceof Configurable)) {
          foreach ($options as $option) {
              // draw options label
              $lines[][] = [
                  'text' => $this->string->split($this->filterManager->stripTags($option['label']), 40, true, true),
                  'font' => 'italic',
                  'feed' => 35,
              ];

              // Checking whether option value is not null
              if ($option['value'] !== null) {
                  if (isset($option['print_value'])) {
                      $printValue = $option['print_value'];
                  } else {
                      $printValue = $this->filterManager->stripTags($option['value']);
                  }
                  $values = explode(', ', $printValue);
                  foreach ($values as $value) {
                      $lines[][] = ['text' => $this->string->split($value, 30, true, true), 'feed' => 40];
                  }
              }
          }
      }

      $lineBlock = ['lines' => $lines, 'height' => 20];

      $page = $pdf->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
      $this->setPage($page);
  }
}
