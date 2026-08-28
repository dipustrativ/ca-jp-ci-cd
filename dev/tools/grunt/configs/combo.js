/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

'use strict';

var theme = require('../tools/files-router').get('themes'),
    path = require('./path');

/**
 * Define Combos for repetitive code.
 */
module.exports = {
    // The bare php command, with no preceding clean — callers that already
    // scoped their own clean (e.g. watch:*Src, which only wipes *.less
    // to avoid nuking published JS) use this instead of collector().
    deployCommand: function (themeName) {
        return 'php bin/magento dev:source-theme:deploy ' +
            theme[themeName].files.join(' ') +
            ' --type=less' +
            ' --locale=' + theme[themeName].locale +
            ' --area=' + theme[themeName].area +
            ' --theme=' + theme[themeName].name;
    },

    collector: function (themeName) {
        var cmdPlus = /^win/.test(process.platform) ? ' & ' : ' && ';

        return 'grunt --force clean:' + themeName + cmdPlus + this.deployCommand(themeName);
    },

    autopath: function (themeName, folder) {
        return folder +
            theme[themeName].area + '/' +
            theme[themeName].name + '/' +
            theme[themeName].locale + '/';
    },

    lessFiles: function (themeName) {
        var lessStringArray = [],
            cssStringArray = [],
            lessFiles = {},
            i = 0;

        for (i; i < theme[themeName].files.length; i++) {
            cssStringArray[i] = path.pub +
            theme[themeName].area + '/' +
            theme[themeName].name + '/' +
            theme[themeName].locale + '/' +
            theme[themeName].files[i] + '.css';

            lessStringArray[i] = path.pub +
            theme[themeName].area + '/' +
            theme[themeName].name + '/' +
            theme[themeName].locale + '/' +
            theme[themeName].files[i] + '.less';

            lessFiles[cssStringArray[i]] = lessStringArray[i];
        }

        return lessFiles;
    }
};
