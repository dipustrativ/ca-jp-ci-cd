<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Docs;

use Magento\Framework\Filesystem\Driver\File;

/**
 * Discovers and reads Markdown docs shipped by every IXPF vendor module
 * under app/code/IXPF/<Module>/docs/*.md.
 *
 * A doc is addressed by an opaque "key" (<Module>__<FileName>). The View
 * controller only ever serves a key that exists in the scanned whitelist,
 * so there is no path-traversal surface.
 */
class DocsRepository
{
    private const DOCS_GLOB = '/app/code/IXPF/*/docs/*.md';

    public function __construct(
        private readonly File $fileDriver
    ) {
    }

    /**
     * @return array<int, array{key:string, module:string, title:string, file:string, path:string}>
     */
    public function getList(): array
    {
        $docs = [];
        foreach (glob(BP . self::DOCS_GLOB) ?: [] as $path) {
            // .../app/code/IXPF/<Module>/docs/<File>.md
            if (!preg_match('#/app/code/IXPF/([^/]+)/docs/([^/]+)\.md$#', $path, $m)) {
                continue;
            }
            $module = $m[1];
            $file   = $m[2];
            $docs[] = [
                'key'    => $module . '__' . $file,
                'module' => 'IXPF_' . $module,
                'title'  => $this->humanize($file),
                'file'   => $file . '.md',
                'path'   => $path,
            ];
        }

        usort($docs, static function ($a, $b) {
            return [$a['module'], $a['title']] <=> [$b['module'], $b['title']];
        });

        return $docs;
    }

    /**
     * @return array{key:string, module:string, title:string, file:string, path:string}|null
     */
    public function getByKey(string $key): ?array
    {
        foreach ($this->getList() as $doc) {
            if ($doc['key'] === $key) {
                return $doc;
            }
        }
        return null;
    }

    public function getContentByKey(string $key): ?string
    {
        $doc = $this->getByKey($key);
        if (!$doc || !$this->fileDriver->isExists($doc['path'])) {
            return null;
        }
        return $this->fileDriver->fileGetContents($doc['path']);
    }

    private function humanize(string $file): string
    {
        // "QuoteCreate" -> "Quote Create", "OrderUpdateCustomer" -> "Order Update Customer"
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $file);
        return trim(str_replace(['_', '-'], ' ', $spaced));
    }
}
