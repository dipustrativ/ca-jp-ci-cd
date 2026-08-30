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

    // '<name>Src' watches the real theme sources under app/design instead of the
    // published copy in pub/static. Magento's asset publisher skips a file that
    // already exists at the destination, so once 'exec:<name>' has published a
    // file, later edits under app/design are invisible to 'watch:<name>' — it
    // would sit idle forever. Running exec before less republishes on each save.
    // Path is derived from the theme entry, so renaming the theme in
    // local-themes.js is enough; nothing here hardcodes a vendor.
    themeOptions[name + 'Src'] = {
        'files': [
            'app/design/' + theme.area + '/' + theme.name + '/**/*.less'
        ],
        'tasks': ['exec:' + name, 'less:' + name]
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
