'use strict';

/**
 * Local theme list for grunt (overrides themes.js via files-router ".loc" lookup).
 * Only the active storefront theme — keeps `grunt less`/`grunt watch` fast.
 */
module.exports = {
    rugs: {
        area: 'frontend',
        name: 'carpient/rugs',
        locale: 'en_US',
        files: [
            'css/styles-m',
            'css/styles-l'
        ],
        dsl: 'less'
    }
};
