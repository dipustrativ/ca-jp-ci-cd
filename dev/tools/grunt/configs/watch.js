/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

'use strict';

var combo  = require('./combo'),
    themes = require('../tools/files-router').get('themes'),
    _      = require('underscore');

var themeOptions = {};

_.each(themes, function (theme, name) {
    themeOptions[name] = {
        'files': [
            '<%= combo.autopath(\''+name+'\', path.pub) %>/**/*.less'
        ],
        'tasks': 'less:' + name
    };

    // Magento's asset publisher (dev:source-theme:deploy) skips republishing
    // a file that already exists under pub/static, so watching only the
    // published copy (above) misses real source edits. Watch the actual
    // source and force a republish + recompile — but clean only *.less
    // (clean:<name>Less), not the whole theme dir (clean:<name>, used by
    // exec:<name>), or every save would also delete published JS/fonts/etc.
    themeOptions[name + 'Src'] = {
        'files': [
            'app/design/' + theme.area + '/' + theme.name + '/**/*.less',
            'app/code/**/view/' + theme.area + '/web/css/source/**/*.less'
        ],
        'tasks': ['clean:' + name + 'Less', 'exec:' + name + 'Deploy', 'less:' + name]
    };
});

var watchOptions = {
    'setup': {
        'files': '<%= path.less.setup %>/**/*.less',
        'tasks': 'less:setup'
    },
    'updater': {
        'options': {
            livereload: true
        },
        'files': '<%= path.less.updater %>/**/*.less',
        'tasks': 'less:updater'
    },
    'reload': {
        'files': '<%= path.pub %>/**/*.css',
        'options': {
            livereload: true
        }
    }
};

module.exports = _.extend(themeOptions, watchOptions);
