<?php
declare(strict_types=1);

namespace IXPF\Syncer\ViewModel\Docs;

use IXPF\Syncer\Services\Docs\DocsRepository;
use IXPF\Syncer\Services\Docs\MarkdownRenderer;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class DocView implements ArgumentInterface
{
    public function __construct(
        private readonly DocsRepository   $docsRepository,
        private readonly MarkdownRenderer $renderer,
        private readonly RequestInterface $request,
        private readonly UrlInterface     $backendUrl
    ) {
    }

    private function getKey(): string
    {
        return (string)$this->request->getParam('doc', '');
    }

    /**
     * @return array{key:string, module:string, title:string, file:string, path:string}|null
     */
    public function getDoc(): ?array
    {
        $key = $this->getKey();
        return $key === '' ? null : $this->docsRepository->getByKey($key);
    }

    /**
     * Rendered, safe HTML for the selected doc (empty when not found).
     */
    public function getHtml(): string
    {
        $key = $this->getKey();
        if ($key === '') {
            return '';
        }
        $content = $this->docsRepository->getContentByKey($key);
        return $content === null ? '' : $this->renderer->render($content);
    }

    public function getBackUrl(): string
    {
        return $this->backendUrl->getUrl('ixpfsyncer/docs/index');
    }
}
