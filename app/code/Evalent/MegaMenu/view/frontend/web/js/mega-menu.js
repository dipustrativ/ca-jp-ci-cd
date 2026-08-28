define([
        'jquery',
        'mage/menu'
    ],
    function($) {

        $.widget('evalent.megamenu', $.mage.menu, {

            options: {
                mediaBreakpoint: '(max-width: 767px)',
                openOnClick: false,
                showDelay: 100,
                hideDelay: 300,
                delay: 0
            },
            /**
             * @private
             */
            _prevMenu: null,
            _subItemWasClicked: false,
            _newMenuWasClicked: false,
            _hoverTimer: null,
            _create: function () {
                this._super();

                var self = this;
                this.delay = this.options.delay;

                $(this.element).off('mousedown mouseenter mouseleave mousemove');
                $(this).off('mousedown mouseenter mouseleave mousemove');

                $(this).off('.ui-menu-item', 'mouseenter');
                $(this).off('.ui-menu-item', 'mousemove');
                $(this).off('.ui-menu-item', 'mouseleave');

                $(window).on('resize', function () {
                    self.element.find('.submenu-reverse').removeClass('submenu-reverse');
                });

                this._on(this.document, {
                    click: function (event) {
                        if (this._closeOnDocumentClick( event )) {
                            $('[data-role="menu-background-overlay"]').hide();
                            this._prevMenu = null;
                            this._newMenuWasClicked = false;
                            this._subItemWasClicked = false;
                            this.collapseAll( event, true );
                        }
                    }
                });

                $('.megamenu--block').closest('li.level0').addClass('parent');
                $('.megamenu--block').closest('li.level0').find('.category-all').hide();
            },
            collapseAll: function( event, all ) {

                if(!this._newMenuWasClicked) {
                    $('[data-role="menu-background-overlay"]').hide();
                    $('#megamenu-ups').css({'display' : ''});
                }

                this._super(event, all);
            },
            _closeOnDocumentClick: function (event) {

                if ($(event.target).closest(".ui-menu-item").length ||
                    $(event.target).closest(".ui-menu-item-wrapper").length ||
                    $(event.target).closest(".ui-menu").length ||
                    $(event.target).closest(".submenu").length) {

                    return false;
                }

                return true;
            },
            _close: function (startMenu) {
                this._super(startMenu);
            },
            /**
             * @private
             */
            _toggleMobileMode: function () {

                $(this.element).off('mouseenter mouseleave mousemove');

                // Reset any open drill-down panels when (re)entering mobile mode
                this.element.find('.ui-menu').removeClass('is-active');

                this._on({

                    /**
                     * Drill into a category that has children, otherwise follow the link.
                     * @param {jQuery.Event} event
                     */
                    'click .ui-menu-item': function (event) {
                        var target = $(event.target).closest('.ui-menu-item');

                        // The back header has its own handler; stop here so the click
                        // does not bubble up and re-open a parent panel.
                        if (target.hasClass('menu-back')) {
                            event.stopPropagation();
                            return;
                        }

                        var childMenu = target.children('.ui-menu');

                        // Parent row with children. The arrow (.ui-menu-icon)
                        // drills into the child panel; the text/link navigates
                        // to the category page.
                        if (childMenu.length && !target.hasClass('all-category') &&
                            !target.hasClass('megamenu--block')) {

                            var hitArrow = $(event.target).closest('.ui-menu-icon').length > 0;
                            var parentLink = target.children('a').attr('href');

                            // No real link to follow -> always drill, even on text.
                            if (hitArrow || !parentLink || parentLink === '#') {
                                event.preventDefault();
                                event.stopPropagation();
                                childMenu.addClass('is-active');
                                childMenu.get(0).scrollTop = 0;
                                $(document).trigger('ev-megamenu:open');
                                return;
                            }

                            // Text/link tapped -> go to the category page.
                            event.preventDefault();
                            event.stopPropagation();
                            window.location.href = parentLink;
                            return;
                        }

                        // Leaf row (or "All ..." link) -> navigate
                        event.stopPropagation();

                        if ($(event.target).hasClass('contact-popup-btn') ||
                            $(event.target).closest('.contact-popup-btn').length) {
                            return;
                        }

                        var link = target.children('a').attr('href');
                        if (link && link !== '#') {
                            window.location.href = link;
                        }
                    },

                    /**
                     * Slide the current panel back out.
                     * @param {jQuery.Event} event
                     */
                    'click .menu-back': function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        $(event.currentTarget).closest('.ui-menu').removeClass('is-active');
                    }
                });

                // Build a back header + bottom "All <category>" link for every panel
                this.element.find('.ui-menu').each($.proxy(function (index, menu) {
                    var $menu = $(menu);

                    // Promo/highlight blocks render flat (plain list, no drill);
                    // skip the back header + "All ..." link for them.
                    if ($menu.closest('.megamenu--block').length) {
                        return;
                    }

                    var $anchor = $menu.closest('.ui-menu-item').children('a'),
                        name = $.trim($anchor.find('.megamenu__child-title').first().text()),
                        url = $anchor.attr('href');

                    if (!name) {
                        name = $.trim($anchor.clone().children().remove().end().text());
                    }

                    if ($menu.children('.menu-back').length === 0) {
                        $menu.prepend(
                            $('<li>').addClass('ui-menu-item menu-back')
                                .append($('<span>').addClass('menu-back__label').text(name))
                        );
                    }

                    if (url && url !== '#' && $menu.children('.all-category').length === 0) {
                        $menu.append(
                            $('<li>').addClass('ui-menu-item all-category all-category--bottom')
                                .append(
                                    $('<a>').attr('href', url)
                                        .text($.mage.__('All %1').replace('%1', name))
                                )
                        );
                    }
                }, this));

                // Reset panels whenever the mobile menu drawer is toggled
                $(document).off('click.megamenuReset', '.nav-toggle')
                    .on('click.megamenuReset', '.nav-toggle', $.proxy(function () {
                        this.element.find('.ui-menu').removeClass('is-active');
                    }, this));
            },
            /**
             * @private
             */
            _toggleDesktopMode: function () {
                var categoryParent, html, self = this;

                $(this.element).off('click mousedown mouseenter mouseleave mousemove');
                $(this).off('mousedown mouseenter mouseleave mousemove');

                $(this).off('.ui-menu-item', 'mouseenter');
                $(this).off('.ui-menu-item', 'mousemove');
                $(this).off('.ui-menu-item', 'mouseleave');

                $('ul#ui-id-1').off();

                this._on({

                    /**
                     * Prevent focus from sticking to links inside menu after clicking
                     * them (focus should always stay on UL during navigation).
                     */
                    'mousedown .ui-menu-item:not(.megamenu--block) > a': function (event) {
                        event.preventDefault();
                    },

                    /**
                     * @param {jQuery.Event} event
                     */
                    'click .ui-menu-item:has(a)': function(event) {

                        var target = $(event.target).closest('.ui-menu-item');

                        if (!target.has('.ui-menu').length) {
                            if (target.find('> a').length && !$(event.target).hasClass('contact-popup-btn')) {
                                window.location.href = target.find('> a').attr('href');
                            }
                        }
                    },

                    /**
                     * @param {jQuery.Event} event
                     */
                    'mouseenter .ui-menu-item.parent': function(event) {
                        var target = $(event.currentTarget),
                            levelTop = $(event.target).closest('.level-top'),
                            submenu = this.options.menus,
                            ulElement,
                            ulElementWidth,
                            width,
                            targetPageX,
                            rightBound,
                            menuBackground = $('[data-role="menu-background"]'),
                            menuBackgroundOverlay = $('[data-role="menu-background-overlay"]');

                        if (self._hoverTimer) {
                            clearTimeout(self._hoverTimer);
                        }

                        self._hoverTimer = setTimeout(function() {
                            if (self._prevMenu && $(self._prevMenu).attr("id") !== $(levelTop).attr("id")) {
                                self.collapseAll(event, true);
                            }

                            if (target.has(submenu)) {
                                ulElement = target.find(submenu);
                                ulElementWidth = ulElement.outerWidth(true);
                                width = target.outerWidth() * 2;
                                targetPageX = target.offset().left;
                                rightBound = $(window).width();

                                if (ulElementWidth + width + targetPageX > rightBound) {
                                    ulElement.addClass('submenu-reverse');
                                }
                                if (targetPageX - ulElementWidth < 0) {
                                    ulElement.removeClass('submenu-reverse');
                                }

                                target.find(submenu).css({'display' : 'grid', 'left' : '0px'});

                                let increaseHeight = 50;
                                let megamenuUsp = $('#megamenu-usp');
                                if ($(megamenuUsp).length) {
                                    $(megamenuUsp).css({'display' : 'flex'});
                                    increaseHeight = 110;
                                }

                                let mmHeight = target.find(submenu).height();
                                menuBackgroundOverlay.show();

                                menuBackground.css("height", (mmHeight + increaseHeight) + 'px');

                                self._prevMenu = levelTop;
                            }

                            target.siblings().children('.ui-state-active').removeClass('ui-state-active');
                            self.focus(event, target);
                        }, self.options.showDelay);
                    },

                    /**
                     * @param {jQuery.Event} event
                     */
                    'mouseleave .ui-menu-item.parent': function (event) {
                        var relatedTarget = $(event.relatedTarget);

                        if (relatedTarget.closest('.ui-menu').length) {
                            return;
                        }

                        // Clear hover timer
                        if (self._hoverTimer) {
                            clearTimeout(self._hoverTimer);
                        }

                        setTimeout(function() {
                            $('[data-role="menu-background-overlay"]').hide();
                            self.collapseAll(event, true);
                            self._prevMenu = null;
                        }, self.options.hideDelay);
                    },

                    /**
                     * @param {jQuery.Event} event
                     */
                    'mouseleave': function (event) {
                        var relatedTarget = $(event.relatedTarget);

                        if (relatedTarget.closest(self.element).length ||
                            relatedTarget.closest('[data-role="menu-background"]').length) {
                            return;
                        }

                        setTimeout(function() {
                            $('[data-role="menu-background-overlay"]').hide();
                            self.collapseAll(event, true);
                            self._prevMenu = null;
                        }, self.options.hideDelay);
                    },

                    /**
                     * @param {jQuery.Event} event
                     */
                    'mouseenter .ui-menu-item:not(.parent)': function(event) {
                        var target = $(event.currentTarget);

                        if (self._hoverTimer) {
                            clearTimeout(self._hoverTimer);
                        }

                        // Don't close if hovering over interactive elements (buttons, popups, drawers)
                        if (target.find('button, .popup, .contact-popup-btn, [data-popup]').length ||
                            target.hasClass('contact-popup-btn') || target.hasClass('level1')) {
                            return;
                        }

                        if (self._prevMenu) {
                            setTimeout(function() {
                                $('[data-role="menu-background-overlay"]').hide();
                                self.collapseAll(event, true);
                                self._prevMenu = null;
                            }, 100);
                        }
                    },
                });

                categoryParent = this.element.find('.all-category');
                html = $('html');

                categoryParent.remove();
                this.element.find('.menu-back').remove();
                this.element.find('.ui-menu').removeClass('is-active');

                if (html.hasClass('nav-open')) {
                    html.removeClass('nav-open');
                    setTimeout(function () {
                        html.removeClass('nav-before-open');
                    }, this.options.hideDelay);
                }
            }
        });

        return $.evalent.megamenu;
    }
);
