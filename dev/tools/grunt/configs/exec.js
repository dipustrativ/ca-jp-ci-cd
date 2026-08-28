/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

'use strict';

var combo = require('./combo'),
    themes = require('../tools/files-router').get('themes'),
    _      = require('underscore');

var themeOptions = {};

_.each(themes, function(theme, name) {
    themeOptions[name] = {
        cmd: combo.collector.bind(combo, name)
    };

    // No clean prefix — pair with clean:<name>Less (scoped to *.less only)
    // so a source-triggered republish never touches published JS/fonts/etc.
    themeOptions[name + 'Deploy'] = {
        cmd: combo.deployCommand.bind(combo, name)
    };
});

var execOptions = {
    all : {
        cmd: function () {
            var cmdPlus = (/^win/.test(process.platform) == true) ? ' & ' : ' && ',
                command;

            command = _.map(themes, function(theme, name) {
                return combo.collector(name);
            }).join(cmdPlus);

            return 'echo ' + command;
        }
    }
};

/**
 * Execution into cmd
 */
module.exports = _.extend(themeOptions, execOptions);
