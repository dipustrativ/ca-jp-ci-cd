<?php
/**
 * Admin grid checks: ui_component definitions, both data sources, the group label column.
 *
 * Covers what a page load would cover minus the HTML: creating the listing through
 * UiComponentFactory parses and validates the ui_component XML against its XSD and instantiates
 * every column class, so a typo in either fails here.
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use IXPF\Syncer\Ui\Component\Listing\Column\ReviewGroupLabel;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\View\Element\UiComponentFactory;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$service  = $om->get(UpsertReviewGroup::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn     = $resource->getConnection();

$pass = 0; $fail = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $n\n"; return; }
    $fail++; echo "  FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n";
}

/**
 * UiComponentInterface::prepare() does not cascade - the layout generator walks the tree and
 * prepares each node. Preparing only the root leaves every column unprepared, which is how a
 * column ends up with no wrapped component and no resolved options.
 */
function prepareTree(\Magento\Framework\View\Element\UiComponentInterface $component): void
{
    $component->prepare();
    foreach ($component->getChildComponents() as $child) {
        prepareTree($child);
    }
}

$tGroup = $resource->getTableName('ixpf_review_group');

echo "\n--- setup: two pools with known contents ---\n";
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);

$fixture = json_decode(
    (string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/Test/fixture/review_group_8518C.json'),
    true
);
$fixture['review_group'] = 'ZZGRIDA';
$r = $service->upsert($fixture);
check('pool A stored', ($r['status'] ?? '') === 'stored', json_encode($r));

// A second pool with no en_US label at all, which is the label fallback case: IXPF pushes
// markets that are not live in Magento yet.
$second = $fixture;
$second['review_group'] = 'ZZGRIDB';
$second['group_label']  = ['ja_JP' => 'ZZグリッド専用ラベル'];
$second['generated_at'] = '2026-09-05T00:00:00Z';
$second['count']        = 1;
$second['average']      = 4.0;
$second['distribution'] = [0, 0, 0, 1, 0];
$second['reviews']      = [$fixture['reviews'][1]];
$second['reviews'][0]['id']     = 'zzgridb00000000000000001';
$second['reviews'][0]['rating'] = 4;
$r2 = $service->upsert($second);
check('pool B stored', ($r2['status'] ?? '') === 'stored', json_encode($r2));

echo "\n--- ui_component definitions build (XSD + column classes) ---\n";
$factory = $om->get(UiComponentFactory::class);
foreach (['ixpfsyncer_review_listing', 'ixpfsyncer_reviewgroup_listing'] as $listing) {
    $component = null;
    $error = '';
    try {
        $component = $factory->create($listing);
        prepareTree($component);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    check("$listing builds", $component !== null && $error === '', $error);

    if ($component === null) {
        continue;
    }

    $provider = $component->getContext()->getDataProvider();
    check("$listing has a data provider", $provider !== null);

    // Column names must match real table columns or the grid renders empty cells.
    $columns = [];
    foreach ($component->getChildComponents() as $child) {
        if ($child->getName() === str_replace('_listing', '_columns', $listing)) {
            foreach ($child->getChildComponents() as $col) {
                $columns[] = $col->getName();
            }
        }
    }
    check("$listing exposes columns", $columns !== [], implode(',', $columns));

    $table = $listing === 'ixpfsyncer_review_listing' ? 'ixpf_review' : 'ixpf_review_group';
    $real  = array_keys($conn->describeTable($resource->getTableName($table)));
    $unknown = array_diff($columns, $real);
    check("$listing columns all exist on $table", $unknown === [], implode(',', $unknown));
}

echo "\n--- has_photos renders as a Yes/No select ---\n";
// has_photos is stored 0/1. Without options wired the filter dropdown is empty and the cell
// shows a bare integer, which is the kind of thing that only shows up on a real page load.
$reviewListing = $factory->create('ixpfsyncer_review_listing');
prepareTree($reviewListing);
$photoColumn = null;
foreach ($reviewListing->getChildComponents() as $child) {
    foreach ($child->getChildComponents() as $col) {
        if ($col->getName() === 'has_photos') {
            $photoColumn = $col;
        }
    }
}
check('has_photos column exists', $photoColumn !== null);
$options = $photoColumn === null ? [] : ($photoColumn->getData('config')['options'] ?? []);
$labels  = array_column(is_array($options) ? $options : [], 'label');
$labels  = array_map(static fn ($l) => (string)$l, $labels);
check('has_photos carries Yes/No options', in_array('Yes', $labels, true) && in_array('No', $labels, true),
    implode(',', $labels));

echo "\n--- reviews data source ---\n";
$reviewCollection = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReview\Grid\Collection');
$reviewCollection->addFieldToFilter('review_group', 'ZZGRIDA');
check('reviews collection returns pool A rows', $reviewCollection->getSize() === 3, (string)$reviewCollection->getSize());

$filtered = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReview\Grid\Collection');
$filtered->addFieldToFilter('review_group', 'ZZGRIDA')->addFieldToFilter('has_photos', 1);
check('has_photos filter narrows to the one review with photos', $filtered->getSize() === 1, (string)$filtered->getSize());

$sorted = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReview\Grid\Collection');
$sorted->addFieldToFilter('review_group', 'ZZGRIDA')->setOrder('created_at', 'DESC');
$dates = array_map(static fn ($i) => $i->getData('created_at'), array_values($sorted->getItems()));
$descending = $dates;
rsort($descending);
check('created_at DESC ordering applies', $dates === $descending, implode(' | ', $dates));

$paged = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReview\Grid\Collection');
$paged->addFieldToFilter('review_group', 'ZZGRIDA')->setPageSize(2)->setCurPage(1);
check('paging returns one page worth', count($paged->getItems()) === 2, (string)count($paged->getItems()));
check('paging keeps the unpaged total', $paged->getSize() === 3, (string)$paged->getSize());

echo "\n--- pools data source (varchar primary key) ---\n";
$groupCollection = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReviewGroup\Grid\Collection');
$groupCollection->addFieldToFilter('review_group', ['like' => 'ZZGRID%']);
check('pools collection returns both pools', $groupCollection->getSize() === 2, (string)$groupCollection->getSize());

$first = $groupCollection->getFirstItem();
// A varchar primary key: the grid needs getId() to resolve, or row identity breaks and the
// bookmark/selection machinery silently keys everything on null.
check('pool row id resolves to the review_group value',
    in_array($first->getId(), ['ZZGRIDA', 'ZZGRIDB'], true), var_export($first->getId(), true));
check('pool row carries aggregates', (int)$first->getData('review_count') > 0, (string)$first->getData('review_count'));
check('pool row carries the distribution',
    $first->getData('dist_5') !== null && $first->getData('dist_1') !== null);
// The label filter runs against the raw locale-keyed JSON, which is the point: a search hits
// the label in whatever language IXPF sent, including markets with no en_US label.
$byLabel = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReviewGroup\Grid\Collection');
$byLabel->addFieldToFilter('review_group', ['like' => 'ZZGRID%'])
        ->addFieldToFilter('group_label', ['like' => '%Bokhara%']);
check('label filter matches the en_US label', $byLabel->getSize() === 1, (string)$byLabel->getSize());

$byJpLabel = $om->create('IXPF\Syncer\Model\ResourceModel\IxpfReviewGroup\Grid\Collection');
$byJpLabel->addFieldToFilter('review_group', ['like' => 'ZZGRID%'])
          ->addFieldToFilter('group_label', ['like' => '%専用ラベル%']);
check('label filter matches a non-en_US label', $byJpLabel->getSize() === 1, (string)$byJpLabel->getSize());

check('pool row carries both timestamps',
    $first->getData('generated_at') !== null && $first->getData('updated_at') !== null);

echo "\n--- group label column ---\n";
$labelColumn = $om->create(ReviewGroupLabel::class, [
    'data' => ['name' => 'group_label'],
]);
$rendered = $labelColumn->prepareDataSource(['data' => ['items' => [
    ['group_label' => json_encode(['de_DE' => 'Teppich', 'en_US' => 'Pakistan Bokhara'])],
    ['group_label' => json_encode(['ja_JP' => 'パキスタン ボハラ'])],
    ['group_label' => null],
    ['group_label' => 'not json at all'],
    ['group_label' => json_encode([])],
    [],
]]]);
$items = $rendered['data']['items'];
check('prefers en_US', $items[0]['group_label'] === 'Pakistan Bokhara', (string)$items[0]['group_label']);
check('falls back to the only locale present', $items[1]['group_label'] === 'パキスタン ボハラ', (string)$items[1]['group_label']);
check('null label renders empty', $items[2]['group_label'] === '');
check('malformed json renders empty, does not throw', $items[3]['group_label'] === '');
check('empty label object renders empty', $items[4]['group_label'] === '');
check('missing key renders empty', $items[5]['group_label'] === '');
check('passthrough when there are no items',
    $labelColumn->prepareDataSource(['data' => []]) === ['data' => []]);

// Stored pools go through the same column, so a real group_label must survive it.
$real = $labelColumn->prepareDataSource(['data' => ['items' => [
    ['group_label' => (string)$conn->fetchOne("SELECT group_label FROM $tGroup WHERE review_group='ZZGRIDA'")],
    ['group_label' => (string)$conn->fetchOne("SELECT group_label FROM $tGroup WHERE review_group='ZZGRIDB'")],
]]]);
check('stored pool A label resolves', $real['data']['items'][0]['group_label'] === 'Pakistan Bokhara',
    (string)$real['data']['items'][0]['group_label']);
check('stored pool B label falls back off en_US', $real['data']['items'][1]['group_label'] === 'ZZグリッド専用ラベル',
    (string)$real['data']['items'][1]['group_label']);

// Reused for bought_variant_texts: must read its own field, not group_label.
$boughtColumn = $om->create(ReviewGroupLabel::class, [
    'data' => ['name' => 'bought_variant_texts'],
]);
$bought = $boughtColumn->prepareDataSource(['data' => ['items' => [[
    'bought_variant_texts' => json_encode(['sv_SE' => 'Köpt: Beige, 200 × 250 cm', 'en_US' => 'Bought: Beige, 200 × 250 cm']),
    'group_label'          => json_encode(['en_US' => 'wrong field']),
]]]]);
check('bought column reads its own field', $bought['data']['items'][0]['bought_variant_texts'] === 'Bought: Beige, 200 × 250 cm',
    (string)$bought['data']['items'][0]['bought_variant_texts']);

echo "\n--- acl resources are declared ---\n";
$aclConfig = (string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/etc/acl.xml');
$menuConfig = (string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/etc/adminhtml/menu.xml');
foreach (['IXPF_Syncer::product_reviews', 'IXPF_Syncer::review_pools'] as $res) {
    check("$res in acl.xml", str_contains($aclConfig, 'id="' . $res . '"'));
    check("$res in menu.xml", str_contains($menuConfig, 'resource="' . $res . '"'));
}

$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);
echo "\ncleaned up test rows\n";

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
