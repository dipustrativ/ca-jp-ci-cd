<?php
declare(strict_types=1);

namespace IXPF\Syncer\ViewModel\Docs;

use IXPF\Syncer\Services\Docs\DocsRepository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class DocsList implements ArgumentInterface
{
    public function __construct(
        private readonly DocsRepository $docsRepository,
        private readonly UrlInterface   $backendUrl
    ) {
    }

    /**
     * @return array<int, array{key:string, module:string, title:string, file:string, path:string}>
     */
    public function getDocs(): array
    {
        return $this->docsRepository->getList();
    }

    public function getViewUrl(string $key): string
    {
        return $this->backendUrl->getUrl('ixpfsyncer/docs/view', ['doc' => $key]);
    }
}
