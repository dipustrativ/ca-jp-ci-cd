<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "Wash & Repair" CMS page served at wash-repair,
 * linked from the footer. Downloads the illustration from the CDN into local
 * media on apply and references it through a {{media url=...}} directive so the
 * page stays portable across environments. Falls back to the CDN URL if the
 * download fails.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class CreateWashRepairCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'wash-repair';
    private const TITLE = 'Wash & Repair';
    private const PAGE_LAYOUT = '1column';

    private const IMAGE_URL = 'https://cdn.carpetavenue.net/media/about-carpetavenue/CA_Rep.png';
    private const MEDIA_PATH = 'about-carpetavenue/CA_Rep.png';

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Filesystem $filesystem,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        $imageSrc = $this->fetchImage()
            ? '{{media url="' . self::MEDIA_PATH . '"}}'
            : self::IMAGE_URL;

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(PageInterface::IDENTIFIER, self::IDENTIFIER)
            ->create();
        $existing = $this->pageRepository->getList($criteria)->getItems();

        /** @var PageInterface $page */
        $page = $existing ? array_shift($existing) : $this->pageFactory->create();

        $page->setIdentifier(self::IDENTIFIER)
            ->setTitle(self::TITLE)
            ->setContent(str_replace('%IMG_SRC%', $imageSrc, $this->getContent()))
            ->setIsActive(1);

        if (!$existing) {
            $page->setPageLayout(self::PAGE_LAYOUT);
        }

        $this->pageRepository->save($page);

        return $this;
    }

    /**
     * Download the illustration into local media. Returns true when the file is
     * present locally afterwards (already existed or downloaded successfully).
     */
    private function fetchImage(): bool
    {
        try {
            $mediaWriter = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

            if ($mediaWriter->isExist(self::MEDIA_PATH)) {
                return true;
            }

            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            $this->curl->get(self::IMAGE_URL);

            if ($this->curl->getStatus() !== 200) {
                $this->logger->warning(
                    'CreateWashRepairCmsPage: image download failed, HTTP ' . $this->curl->getStatus(),
                    ['url' => self::IMAGE_URL]
                );
                return false;
            }

            $body = $this->curl->getBody();
            if ($body === '') {
                return false;
            }

            $mediaWriter->writeFile(self::MEDIA_PATH, $body);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CreateWashRepairCmsPage: could not store image locally: ' . $e->getMessage(),
                ['url' => self::IMAGE_URL]
            );
            return false;
        }
    }

    private function getContent(): string
    {
        return <<<'HTML'
<div class="row">
<div class="col-xs-12 col-md-6">
<h2><strong>クリーニングと修理</strong></h2>
<div class="page" title="Page 1">
<div class="layoutArea">
<div class="column">
<p>手結びや手織の絨毯は世代を超えて何年も長持ちするように作られています。 正しいお手入れと注意を払えば優雅に年を重ね、時間と共により美しく価値の あるものになっていきます。</p>
</div>
</div>
</div>
<div class="page" title="Page 1">
<div class="layoutArea">
<div class="column">
<p>日本のクリーニングと修理専門業者が対応させて頂きます。</p>
</div>
</div>
</div>
<div class="page" title="Page 1">
<div class="layoutArea">
<div class="column">
<p>例えお客様のシン プルな手織絨毯のクリーニングであっても、高価な純シルクやアンティーク絨 毯の修復でも、大切なのは経験のある専門家に任せることです。</p>
</div>
</div>
</div>
<p>&nbsp;</p>
<h4><strong>クリーニング</strong></h4>
<div class="page" title="Page 1">
<div class="layoutArea">
<div class="column">
<p>一般的に手結びや手織の絨毯は 3~5 年毎に洗うことをお勧めします。</p>
<p>クリーニ ングされた絨毯は見た目が良くなるだけでなく、より長持ちするようになりま す。</p>
<p>手結びや手織の羊毛やシルクの絨毯クリーニング、ほこりやある程度のシ ミ取りは専門家の手による手洗いサービスが最も効果的で美しく仕上がります。</p>
<p>&nbsp;</p>
</div>
</div>
</div>
<p><strong>修理と修復</strong></p>
<div class="page" title="Page 2">
<div class="layoutArea">
<div class="column">
<p>お客様の手織の絨毯に穴が開いた際の織り直しやフリンジの結び直しといった 補修もお手伝いさせて頂きます。</p>
<p>&nbsp;</p>
</div>
</div>
</div>
<h4><strong>お問い合わせ下さい。</strong></h4>
<div>
<div class="page" title="Page 2">
<div class="layoutArea">
<div class="column">
<p>詳細については無料の相談とお見積りを <a href="mailto:service@carpetavenue.com">info@carpetavenue.jp</a> までご連絡をお願いいたします。&nbsp;&nbsp;</p>
</div>
</div>
</div>
</div>
<div class="page" title="Page 2">
<div class="layoutArea">
<div class="column">
<p>&nbsp;</p>
</div>
</div>
</div>
<h4><strong style="font-size: 14px;">Carpet Avenue クリーニングと修理 -お客様の絨毯の ニーズにお応え致します!</strong></h4>
</div>
<div class="col-xs-12 col-md-6"><img style="height: auto; max-height: 100%;" src="%IMG_SRC%" alt="" width="810" height="800"></div>
</div>
HTML;
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
