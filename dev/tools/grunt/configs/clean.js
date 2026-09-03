/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

'use strict';

var themes = require('../tools/files-router').get('themes'),
    _      = require('underscore');

var themeOptions = {};

_.each(themes, function(theme, name) {
    // Scoped to the published .less sources — the only thing 'exec:<name>'
    // (clean + dev:source-theme:deploy) puts back. Stock wiped the whole
    // published theme dir, so one LESS save also deleted every .js
    // (requirejs-config.js with it), image and font, killing all storefront JS
    // until an uncached page render rebuilt them — which is why 'watch:<name>Src'
    // used to need full_page cache off. var/view_preprocessed mirrors the
    // pub/static path and holds the preprocessed copies of these files; it has
    // to go with them or the republish reuses stale output. Magento 2.4 no
    // longer uses path.tmpLess/tmpSource (2.1-era paths, they match nothing).
    //
    // deployedVersion stays in: this store does serve CSS under a
    // /static/version<ts>/ prefix even in developer mode, and that prefix only
    // moves when the file is gone. Leave it and the browser keeps the cached
    // stylesheet after every save — the recompile lands and nothing changes on
    // screen. Magento rewrites it on the next request, and it takes no JS with
    // it, which was the actual problem with the stock wipe.
    themeOptions[name] = {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= combo.autopath(\""+name+"\", path.pub ) %>**/*.less",
                    "var/view_preprocessed/<%= combo.autopath(\""+name+"\", path.pub ) %>**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    };
});

var cleanOptions = {
    "var": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.tmp %>/log/**/*",
                    "<%= path.tmp %>/maps/**/*",
                    "<%= path.tmp %>/page_cache/**/*",
                    "<%= path.tmp %>/tmp/**/*",
                    "<%= path.tmp %>/view/**/*",
                    "<%= path.tmp %>/view_preprocessed/**/*"
                ]
            }
        ]
    },
    "pub": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.pub %>frontend/**/*",
                    "<%= path.pub %>adminhtml/**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "styles": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/view_preprocessed/**/*",
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.pub %>frontend/**/*.less",
                    "<%= path.pub %>frontend/**/*.css",
                    "<%= path.pub %>adminhtml/**/*.less",
                    "<%= path.pub %>adminhtml/**/*.css",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "markup": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.tmp %>/cache/**/*",
                    "<%= path.tmp %>/view_preprocessed/html/**/*",
                    "<%= path.tmp %>/page_cache/**/*"
                ]
            }
        ]
    },
    "js": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.pub %>**/*.js",
                    "<%= path.pub %>**/*.html",
                    "<%= path.pub %>_requirejs/**/*",
                    "<%= path.deployedVersion %>"
                ]
            }
        ]
    },
    "generation": {
        "force": true,
        "files": [
            {
                "force": true,
                "dot": true,
                "src": [
                    "<%= path.generation %>code/**/*",
                    "<%= path.generation %>metadata/**/*"
                ]
            }
        ]
    }
};

module.exports = _.extend(cleanOptions, themeOptions);

