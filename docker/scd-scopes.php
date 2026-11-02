<?php
/**
 * Build-time scopes + themes for setup:static-content:deploy — no DB needed.
 *
 * Magento's SCD reads websites/stores/themes from app/etc/config.php when
 * env.php has neither a 'db' nor an 'install' key (InitialConfigSource). The
 * Dockerfile merges this file into config.php ONLY inside the SCD RUN and
 * restores the original afterwards, so the runtime config.php never carries
 * scopes — if it did, app:config:import / setup:upgrade would sync stores from
 * the file into the prod DB.
 *
 * Regenerate after adding a website, store view or theme: run
 *   bin/magento app:config:dump scopes themes
 * in a scratch copy (it rewrites app/etc/config.php) and paste the 'scopes' and
 * 'themes' arrays here. docker/README.md §8.1.
 */
return [
    'scopes' => [
        'websites' => [
            'admin' => [
                'website_id' => '0',
                'code' => 'admin',
                'name' => 'Admin',
                'sort_order' => '0',
                'default_group_id' => '0',
                'is_default' => '0',
            ],
            'ca_jp' => [
                'website_id' => '1',
                'code' => 'ca_jp',
                'name' => 'Carpet Avenue JP',
                'sort_order' => '0',
                'default_group_id' => '1',
                'is_default' => '1',
            ],
        ],
        'groups' => [
            [
                'group_id' => '0',
                'website_id' => '0',
                'name' => 'Default',
                'root_category_id' => '0',
                'default_store_id' => '0',
                'code' => 'default',
            ],
            [
                'group_id' => '1',
                'website_id' => '1',
                'name' => 'Carpet Avenue Store JP',
                'root_category_id' => '2',
                'default_store_id' => '1',
                'code' => 'carpet_avenue_store_jp',
            ],
        ],
        'stores' => [
            'admin' => [
                'store_id' => '0',
                'code' => 'admin',
                'website_id' => '0',
                'group_id' => '0',
                'name' => 'Admin',
                'sort_order' => '0',
                'is_active' => '1',
            ],
            'jp' => [
                'store_id' => '1',
                'code' => 'jp',
                'website_id' => '1',
                'group_id' => '1',
                'name' => '日本',
                'sort_order' => '0',
                'is_active' => '1',
            ],
        ],
    ],
    'themes' => [
        'frontend/Magento/blank' => [
            'parent_id' => null,
            'theme_path' => 'Magento/blank',
            'theme_title' => 'Magento Blank',
            'is_featured' => '0',
            'area' => 'frontend',
            'type' => '0',
            'code' => 'Magento/blank',
        ],
        'adminhtml/Magento/backend' => [
            'parent_id' => null,
            'theme_path' => 'Magento/backend',
            'theme_title' => 'Magento 2 backend',
            'is_featured' => '0',
            'area' => 'adminhtml',
            'type' => '0',
            'code' => 'Magento/backend',
        ],
        'frontend/Magento/luma' => [
            'parent_id' => 'Magento/blank',
            'theme_path' => 'Magento/luma',
            'theme_title' => 'Magento Luma',
            'is_featured' => '0',
            'area' => 'frontend',
            'type' => '0',
            'code' => 'Magento/luma',
        ],
        'frontend/carpient/rugs' => [
            'parent_id' => 'Magento/blank',
            'theme_path' => 'carpient/rugs',
            'theme_title' => 'Carpient Rugs',
            'is_featured' => '0',
            'area' => 'frontend',
            'type' => '0',
            'code' => 'carpient/rugs',
        ],
        'frontend/carpetavenue/rugs' => [
            'parent_id' => 'Magento/blank',
            'theme_path' => 'carpetavenue/rugs',
            'theme_title' => 'CarpetAvenue Rugs',
            'is_featured' => '0',
            'area' => 'frontend',
            'type' => '0',
            'code' => 'carpetavenue/rugs',
        ],
    ],
];
