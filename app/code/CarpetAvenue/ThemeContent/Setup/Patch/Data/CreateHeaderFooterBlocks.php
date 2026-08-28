<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Recreates the header + footer CMS static blocks that the carpetavenue/rugs
 * theme layout renders. Block HTML ported from the reference project.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateHeaderFooterBlocks implements DataPatchInterface
{
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function apply(): self
    {
        foreach ($this->getBlocks() as $identifier => $data) {
            $this->saveBlock($identifier, $data['title'], $data['content']);
        }

        return $this;
    }

    private function saveBlock(string $identifier, string $title, string $content): void
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(BlockInterface::IDENTIFIER, $identifier)
            ->create();
        $existing = $this->blockRepository->getList($criteria)->getItems();

        /** @var BlockInterface $block */
        $block = $existing ? array_shift($existing) : $this->blockFactory->create();

        $block->setIdentifier($identifier)
            ->setTitle($title)
            ->setContent($content)
            ->setIsActive(1);

        if (method_exists($block, 'setStoreId')) {
            $block->setStoreId([0]);
        }

        $this->blockRepository->save($block);
    }

    /**
     * @return array<string, array{title: string, content: string}>
     */
    private function getBlocks(): array
    {
        return [
            'top_header_static-desktop' => [
                'title' => 'Header Top Static Desktop',
                'content' => <<<'HTML'
<div class="top-static-content desktop">
<ul>
<li><span class="navicon-check">&nbsp;</span>More than 32000 carpets</li>
<li><span class="navicon-check">&nbsp;</span>Free Shipping &amp; Returns within the EU</li>
<li><span class="navicon-check">&nbsp;</span>30-Day Money Back Guarantee</li>
<li><span class="navicon-check">&nbsp;</span>Satisfaction Guaranteed</li>
</ul>
</div>
HTML,
            ],
            'footer-bottom-logo' => [
                'title' => 'Footer Bottom Logo',
                'content' => <<<'HTML'
<p><img src="{{view url="images/logo_white.svg"}}" alt="Carpet Avenue"></p>
HTML,
            ],
            'footer-carpet-links' => [
                'title' => 'Footer: Carpet links',
                'content' => <<<'HTML'
<h3>Carpet Avenue</h3>
<ul>
<li><a href="/carpets/about">About Us</a></li>
<li><a href="/imprint">Imprint</a></li>
</ul>
HTML,
            ],
            'footer-customer-service' => [
                'title' => 'Footer: Customer service',
                'content' => <<<'HTML'
<h3>Need Help?</h3>
<p>Our Customer Service team will be happy to assist you!</p>
<p>&nbsp;</p>
<p>Our opening hours (CET): <br>Monday - Friday: 9 am - 5 pm<br>Saturday: 10 am - 4 pm<br>Sunday &amp; Public holidays: Closed</p>
<p>Telephone: +49 40 228 988 59</p>
<p>E-mail: <a href="mailto:info@carpetavenue.com">info@carpetavenue.com</a></p>
<p>&nbsp;</p>
HTML,
            ],
            'footer-social-links' => [
                'title' => 'Footer Social Links',
                'content' => <<<'HTML'
<p class="footer-social"><a href="https://www.facebook.com/FindYourCarpet" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/fb_inv_new.png"}}" alt="Facebook"></a><a href="https://www.instagram.com/carpetavenue" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/insta_inv_new.png"}}" alt="Instagram"></a><a href="https://www.youtube.com/@carpetavenue" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/youtube_inv_new.png"}}" alt="YouTube"></a></p>
HTML,
            ],
            'footer-service-links' => [
                'title' => 'Footer: Service links',
                'content' => <<<'HTML'
<h3>Service</h3>
<ul>
<li><a href="/terms-conditions">Terms &amp; Conditions</a></li>
<li><a href="/faq">FAQ</a></li>
<li><a href="/privacy-cookie-policy">Privacy &amp; Cookie Policy</a></li>
<li><a href="/tips-for-buying-your-carpet">Tips for buying your carpet</a></li>
<li><a href="/carpet-care">Carpet care</a></li>
<li><a href="/wash-repair">Wash &amp; Repair</a></li>
<li><a href="/contact">Contact</a></li>
<li><a href="/showroom">Showroom</a></li>
</ul>
HTML,
            ],
            'footer-trustpilot-badge' => [
                'title' => 'Footer Customer Loyalty',
                'content' => <<<'HTML'
<h3>Customer Loyalty</h3>
<p><a href="https://uk.trustpilot.com/review/carpetavenue.com" target="_blank" rel="noopener"><img src="{{view url="images/icons/Trustpilot_brandmark.png"}}" alt="Trustpilot badge" width="150" height="37"></a></p>
HTML,
            ],
            'footer-empty-links' => [
                'title' => 'Footer Empty Links',
                'content' => '',
            ],
            'footer-payment-logos' => [
                'title' => 'Footer: Payment logos',
                'content' => <<<'HTML'
<h3>Payment Methods</h3>
<p><img src="{{media url="badges/SE.png"}}" alt="Payment methods" width="290" height="95"></p>
<div class="footer-extra-pay"><img src="{{media url="wysiwyg/Apple_Pay-White-Logo_new.png"}}" alt="Apple Pay" width="64" height="20"> <img style="padding: 12px 20px;" src="{{media url="wysiwyg/swish_logo_white_new.png"}}" alt="Swish" width="64" height="20"></div>
HTML,
            ],
            'footer-delivery-partners' => [
                'title' => 'Footer: Delivery partners',
                'content' => <<<'HTML'
<h3>We ship with</h3>
<div class="row footer-ship-row">
<div class="col-xs-2 col-md-3"><img src="{{media url="badges/175px-UPS_Logo_Shield_2017.svg.png"}}" alt="UPS" width="66" height="107"></div>
<div class="col-xs-5 col-md-6" style="padding-top: 5px;"><img src="{{media url="badges/DHL_logo_rgb.png"}}" alt="DHL" width="174" height="39"></div>
</div>
HTML,
            ],
        ];
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
