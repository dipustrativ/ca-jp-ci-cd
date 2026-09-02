/**
 * Evalent_MagicGallery
 * @author Hampus Ershagen <hampus.ershagen@evalent.com>
 */
define([
    'jquery',
    'slick',
    'fancybox',
    'uiClass',
    'Magento_ProductVideo/js/load-player',
    'Magento_Ui/js/modal/modal'
], function ($, slick, fancybox, Class) {
    'use strict';

    /**
     * @param href
     * @returns {Element}
     */
    function parseHref(href) {
        var a = document.createElement('a');

        a.href = href;

        return a;
    }

    /**
     * @param href
     * @param forceVideo
     * @returns {*}
     */
    function parseVideoURL(href, forceVideo) {
        var id,
            type,
            ampersandPosition,
            vimeoRegex,
            _provider;

        /**
         * Get youtube ID
         * @param {String} srcid
         * @returns {{}}
         */
        function _getYoutubeId(srcid) {
            if (srcid) {
                ampersandPosition = srcid.indexOf('&');

                if (ampersandPosition === -1) {
                    return srcid;
                }

                srcid = srcid.substring(0, ampersandPosition);
            }

            return srcid;
        }

        if (typeof href !== 'string') {
            return href;
        }

        href = parseHref(href);

        if (href.host.match(/youtube\.com/) && href.search) {
            id = href.search.split('v=')[1];

            if (id) {
                id = _getYoutubeId(id);
                type = 'youtube';
            }
        } else if (href.host.match(/youtube\.com|youtu\.be/)) {
            id = href.pathname.replace(/^\/(embed\/|v\/)?/, '').replace(/\/.*/, '');
            type = 'youtube';
            _provider = 'youtube';
        } else if (href.host.match(/vimeo\.com/)) {
            type = 'vimeo';
            vimeoRegex = new RegExp(['https?:\\/\\/(?:www\\.|player\\.)?vimeo.com\\/(?:channels\\/(?:\\w+\\/)',
                '?|groups\\/([^\\/]*)\\/videos\\/|album\\/(\\d+)\\/video\\/|video\\/|)(\\d+)(?:$|\\/|\\?)'
            ].join(''));
            id = href.href.match(vimeoRegex)[3];
        }

        if ((!id || !type) && forceVideo) {
            id = href.href;
            type = 'custom';
        }

        //let urlSanitize = href.search.replace(/^\?/, '');
        var urlSanitize = href.href;

        if(href.href.indexOf("watch?v=") >= 0) {
            urlSanitize = href.href.replace(/(?:https:\/\/)?(?:www\.)?(?:youtube\.com)\/(?:watch\?v=)?(.+)/g, '//www.youtube.com/embed/$1');
        }

        return id ? {
            id: id, type: type, s: urlSanitize, provider: _provider
        } : false;
    }

    /**
     * Swatch script is calling this :\
     */
    $.widget('mage.AddFotoramaVideoEvents', {
        _create: function () {
            // Dummy widget
        }
    });

    /**
     * Removed hardcoded protocol from URL
     */
    $.widget('mage.videoVimeo', $.mage.videoVimeo, {
        /**
         * Initialize the Vimeo widget
         * @private
         */
        _create: function () {
            var timestamp,
                additionalParams = '',
                src;

            this._initialize();
            timestamp = new Date().getTime();
            this._autoplay = true;

            if (this._autoplay) {
                additionalParams += '&autoplay=1';
            }

            if (this._loop) {
                additionalParams += '&loop=1';
            }
            src = '//player.vimeo.com/video/' +
                this._code + '?api=1&player_id=vimeo' +
                this._code +
                timestamp +
                additionalParams;
            this.element.append(
                $('<iframe/>')
                    .attr('frameborder', 0)
                    .attr('id', 'vimeo' + this._code + timestamp)
                    .attr('width', this._width)
                    .attr('height', this._height)
                    .attr('src', src)
                    .attr('webkitallowfullscreen', '')
                    .attr('mozallowfullscreen', '')
                    .attr('allowfullscreen', '')
            );
            this._player = window.$f(this.element.children(':first')[0]);

            // Froogaloop throws error without a registered ready event
            this._player.addEvent('ready', function (id) {
                $('#' + id).closest('.fotorama__stage__frame').addClass('fotorama__product-video--loaded');
            });
        }
    });

    /**
     *
     */
    return Class.extend({
        /**
         * Classes used for linking main and thumbnail sliders
         */
        GALLERY_CLASS: 'mz-main-slider',
        THUMBS_CLASS: 'mz-gallery-thumbs',
        VIDEOCONTAINER: 'mz-product-video',
        VIDEOLOADED: 'video-loaded',
        isMobile: function () {
            return $(window).width() <= 800;
        },

        defaults: {
            data: [],
            sliderSettings: {
                options: {}
            },
            imagePopup: {
                options: {}
            },
            thumbsSettings: {
                options: {}
            },
            videoSettings: {
            }
        },

        // Doesn't initialize on (tap) click if enabled
        isTouchEnabled: (function () {
            return false;
            // return 'ontouchstart' in document.documentElement;
        })(),

        /**
         *
         * @param config Configuration object
         * @param element Slider wrapper element
         */
        initialize: function (config, element) {
            var self = this;

            this.config = $.extend(true, this.defaults, config);

            if(!jQuery.isEmptyObject(this.config.thumbsSettings) && $(window).width() > 800) {
                //this.config.sliderSettings.options.asNavFor = '.' + this.THUMBS_CLASS;
                //this.config.thumbsSettings.options.asNavFor = '.' + this.GALLERY_CLASS;
            }

            this.prepareVideoData();

            // Custom YouTube video
            if(config.hasOwnProperty("videoCustom")) {
                let customYoutubeVideo = config.videoCustom.videoUrl;
                let youtube_video_id = customYoutubeVideo.match(/youtube\.com.*(\?v=|\/embed\/)(.{11})/).pop();
                let youtubeVideoThumb = "https://img.youtube.com/vi/"+youtube_video_id+"/0.jpg";
                let youtubeVideoUrl = "//www.youtube.com/embed/"+youtube_video_id;

                if(youtube_video_id.length > 0) {

                    // Prepare and push custom YouTube video data
                    let customVideo = {
                        id: youtube_video_id,
                        type: "video",
                        videoUrl: youtubeVideoUrl,
                        provider: "youtube",
                        contentSource: "youtube",
                        img: youtubeVideoThumb,
                        thumb: youtubeVideoThumb
                    };

                    this.config.data.push(customVideo);
                }
            }

            this.sliderElement = $(element);

            this.initSlider();

            this.initApi();

            /* Bug: https://github.com/magento/magento2/issues/7399 */
            $(document).on('click', '.mz-video-modal', function(e) {
                e.stopImmediatePropagation();
                $('.modals-overlay').trigger('click');
            });

            var windowWidth = $(window).width();

            $(window).on('resize', function () {
                // Reinit the slider if window changes between desktop/mobile
                if($(window).width() != windowWidth && ($(window).width() <= 800 || $(window).width() > 800)) {
                    self.reInitSlider();
                    windowWidth = $(window).width();
                }
            });

        },

        /**
         *
         */
        initSlider: function() {
            var self = this;
            var imagePopOptions = this.config.imagePopup.options;

            this.sliderElement.removeClass('_block-content-loading');

            this.sliderElement.addClass(this.GALLERY_CLASS);

            // Build the slide markup into the DOM BEFORE initializing slick.
            // Slicking an empty element and then slickAdd-ing breaks on mobile:
            // when a responsive breakpoint (<=769px) is active at init, the slick
            // instance silently rejects every slickAdd, so the main image never
            // renders on phones. Initializing slick with the slides already present
            // avoids that broken state.
            this.appendSlides();

            this.sliderElement.slick(this.config.sliderSettings.options);

            if (this.config.imagePopup.imagePopupStatus) {
                if (this.isTouchEnabled) {
                    self.imageModalPopup('taphold', imagePopOptions);
                } else {
                    self.imageModalPopup('click', imagePopOptions);
                }
            }

            this.initThumbs();
        },

        /**
         * Inits video container
         */
        videoContainer: function () {
            var self = this;
            self.createVideoContainer();

            this.sliderElement.on('afterChange', function (event, slick, currentSlide) {
                self.unloadVideoPlayer();
                self.createVideoContainer();
            });
        },

        /**
         * Image modal popup
         *
         * @returns {boolean}
         */
        imageModalPopup: function (event, config) {
            var self = this;
            let slickClass = this.config.thumbsSettings.options.slidesToShow < 10 ?
                ".slick-slide-wrap.slick-slide.slick-current:not(.slick-video-slide)" :
                ".slick-slide";

            $(document).on(event, slickClass, function(e) {
                let slickVisibleLinks = self.config.thumbsSettings.options.slidesToShow < 10 ?
                    ".slick-slide-wrap:not(.slick-cloned):not(.slick-video-slide)" :
                    ".slick-slide:not(.slick-cloned)";
                var visibleLinks = $(slickVisibleLinks);
                $.fancybox.open( visibleLinks, config, visibleLinks.index( this ) );

                return false;
            });
        },

        /**
         *
         */
        /**
         * Builds one slide's markup.
         */
        buildSlide: function (imageData, index) {
            var _className = (this.isVideo(imageData)) ? 'slick-video-slide' : '',
                _hrefLink = (this.isVideo(imageData)) ? imageData['videoUrl'] : imageData['full'],
                _fancyBox = (this.config.imagePopup.imagePopupStatus) ? 'href="' + _hrefLink + '"' : '',
                _dataType = imageData.provider == "youtube" ? 'data-type="iframe"' : 'data-type="' + imageData['type'] + '"';

            return '<' + (imageData.provider == "youtube" ? 'div data-fancybox data-type="iframe"' : 'div') + ' class="slick-slide-wrap '+ _className + (imageData.provider == "youtube" ? ' iframe' : '') + '" data-data-index="' + index + '" ' + _fancyBox + ' ><img width="'+this.config.image_width+'" height="'+this.config.image_height+'" ' + _dataType + ' src="' + imageData['img'] + '"/></' + (imageData.provider == "youtube" ? 'a' : 'div') + '>';
        },

        /**
         * Appends the slide markup to the main slider element (call BEFORE slick init).
         * Mobile shows every slide; desktop keeps the first two (the rest live in the
         * thumbnail rail).
         */
        appendSlides: function () {
            var self = this;
            var html = '';
            $.each(this.config.data, function (index, imageData) {
                if (!self.isMobile() && index >= 2) {
                    return;
                }
                html += self.buildSlide(imageData, index);
            });
            this.sliderElement.append(html);
        },

        /**
         *
         */
        initThumbs: function () {
            var self = this;

            this.thumbsElement = $('<div/>', {'class': this.THUMBS_CLASS});
            this.sliderElement.parent().find('.mz-gallery-thumbs-wrap').append(this.thumbsElement);

            if(!jQuery.isEmptyObject(this.config.thumbsSettings)) {
                this.thumbsElement.slick(this.config.thumbsSettings.options);
            }

            // Make thumbnails
            this.addThumbs();

            // Thumbnails expand button
            $('.mz-gallery-thumbs--expand__btn').on("click", function () {
               $('.mz-gallery-thumbs').toggleClass("expand");
               $(this).hide(500);
            });
        },

        /**
         *
         */
        addThumbs: function () {
            var self = this;
            $.each(this.config.data, function (index, imageData) {
                var _className = (self.isVideo(imageData)) ? 'slick-video-slide' : '',
                    _hrefLink = (self.isVideo(imageData)) ? imageData['videoUrl'] : imageData['full'],
                    _fancyBox = (self.config.imagePopup.imagePopupStatus) ? 'href="' + _hrefLink + '"' : '',
                    _dataType = imageData.provider == "youtube" ? 'data-type="iframe"' : 'data-type="' + imageData['type'] + '"',
                     _thumb = '<a class="thumb ' + _className + '"' + _fancyBox + ' ' + _dataType + '><img width="320" height="280" src="' + imageData['thumb'] + '"/></a>';

                if(index > 1) {
                    self.thumbsElement.slick('slickAdd', _thumb);
                }
            });
        },

        /**
         *
         */
        reInitSlider: function () {
            // Rebuild the main slider from scratch: unslick, clear, re-append the
            // slides, then slick again. Same reason as initSlider — going back to an
            // empty slick and slickAdd-ing breaks under the mobile responsive
            // breakpoint. Re-slicking with slides already in the DOM is reliable.
            this.sliderElement.slick('unslick');
            this.sliderElement.empty();
            this.appendSlides();
            this.sliderElement.slick(this.config.sliderSettings.options);

            // Thumbs are unaffected by the breakpoint bug (they render fine); just
            // clear and repopulate them.
            var slickThumbs = this.thumbsElement.slick('getSlick'),
                thumbs = slickThumbs.$slides;
            while (thumbs.length !== 0) {
                this.thumbsElement.slick('slickRemove', --thumbs.length);
            }
            this.addThumbs();
        },

        /**
         *
         */
        playSlider: function () {
            if (this.config.sliderSettings.options.autoplay) {
                this.sliderElement.slick('slickPlay');
            }
        },

        /**
         *
         */
        pauseSlider: function () {
            if (this.config.sliderSettings.options.autoplay) {
                this.sliderElement.slick('slickPause');
            }
        },

        /**
         *
         * @param item
         * @returns {*|boolean}
         */
        isVideo: function (item) {
            return (item.type && (item.type === 'external-video' || item.type === 'video'));
        },

        /**
         *
         */
        prepareVideoData: function () {
            var data = this.config.data;

            for (var i = 0; i < data.length; i++) {
                if (this.isVideo(data[i])) {
                    var dataUrl = data[i].videoUrl;
                    dataUrl = parseVideoURL(dataUrl);

                    data[i].id = dataUrl.id;
                    data[i].provider = dataUrl.type;
                    data[i].contentSource = dataUrl.type;

                    if (dataUrl.type === 'youtube') {
                        // Override Youtube videoUrl with embed url
                        this.config.data[i].videoUrl = dataUrl.s;
                    }

                    if (dataUrl.type === 'vimeo') {
                        this.loadVimeoJSFramework();
                    }
                }
            }
        },

        /**
         *
         */
        createVideoContainer: function () {
            var currentSlide = this.sliderElement.find('.slick-current'),
                currentImage = this.sliderElement.find('.slick-current img'),
                data = this.config.data[currentSlide.data('data-index')];

            if (currentImage.data('media-type') === 'image' || currentImage.data('media-type') === 'iframe' || currentSlide.find('.' + this.VIDEOCONTAINER).length > 0) {
                return;
            }

            currentSlide.append(
                '<div class="' +
                this.VIDEOCONTAINER +
                '" data-related="' +
                this.config.videoSettings.showRelated +
                '" data-loop="' +
                this.config.videoSettings.videoAutoRestart +
                '" data-type="' +
                data.provider +
                '" data-code="' +
                data.id +
                '" data-width="100%" data-height="100%"></div>'
            );

            if (!this.config.imagePopup.imagePopupStatus) {
                this.setVideoEvents(currentSlide);
            }
        },

        /**
         *
         */
        setVideoEvents: function (currentSlide) {
            currentSlide
                .off('click tap', $.proxy(this.videoClickHandler, this))
                .on('click tap', $.proxy(this.videoClickHandler, this));
        },

        /**
         *
         */
        videoClickHandler: function (event) {
            var self = this;

            if ($(event.target).find('iframe').length === 0) {
                var videoContainer = $(event.target).find('.' + this.VIDEOCONTAINER);
                videoContainer.productVideoLoader();

                if (this.config.sliderSettings.videoPopup) { // show in modal
                    videoContainer.modal({
                        clickableOverlay: true,
                        innerScroll: false,
                        modalClass: 'mz-video-modal',
                        buttons: [],
                        closed: function () {
                            videoContainer.remove();
                            self.unloadVideoPlayer();
                        }
                    }).trigger('openModal');
                } else {
                    $(event.target).addClass(this.VIDEOLOADED);
                }

                self.pauseSlider();
            }
        },

        /**
         *
         */
        unloadVideoPlayer: function () {
            var self = this;
            this.sliderElement.find('.' + this.VIDEOCONTAINER).each(function () {
                var $item = $(this).parent(),
                    cloneVideoDiv,
                    iframeElement = $(this).find('iframe');

                if (iframeElement.length === 0) {
                    return;
                }

                $item.removeClass(self.VIDEOLOADED);
                iframeElement.remove();
                cloneVideoDiv = $(this).clone();
                $(this).remove();
                $item.append(cloneVideoDiv);
            });

            self.playSlider();
        },

        /**
         *
         */
        loadVimeoJSFramework: function () {
            var element = document.createElement('script'),
                scriptTag = document.getElementsByTagName('script')[0];

            element.async = true;
            element.src = 'https://secure-a.vimeocdn.com/js/froogaloop2.min.js';
            scriptTag.parentNode.insertBefore(element, scriptTag);
        },

        /**
         * Creates gallery's API.
         *
         * Used by swatches/product_video
         */
        initApi: function () {
            var gallery = this,
                sliderElement = this.sliderElement,
                config = this.config,
                api = {

                    /**
                     * Displays the last image on preview.
                     */
                    last: function () {
                        var slick = gallery.sliderElement.slick('getSlick');
                        sliderElement.slick('slickGoTo', slick.$slides.length - 1);
                    },

                    /**
                     * Displays the first image on preview.
                     */
                    first: function () {
                        sliderElement.slick('slickGoTo', 0);
                    },

                    /**
                     * Displays previous element on preview.
                     */
                    prev: function () {
                        sliderElement.slick('slickPrev');
                    },

                    /**
                     * Displays next element on preview.
                     */
                    next: function () {
                        sliderElement.slick('slickNext');
                    },

                    /**
                     * Displays image with appropriate count number on preview.
                     */
                    seek: function (index) {
                        sliderElement.slick('slickGoTo', index);
                    },

                    /**
                     * Updates gallery with new set of options.
                     */
                    updateOptions: function (configuration, isInternal) {
                        // TODO Might be used only by defaukt gallery.js. Could be removed if that's the case.
                    },

                    /**
                     * Updates gallery with specific set of items.
                     */
                    updateData: function (data) {
                        if ($.isArray(data)) {
                            config.data = data;
                            gallery.reInitSlider();
                        }
                    },

                    /**
                     * Returns current images list
                     */
                    returnCurrentImages: function () {
                        var images = [];

                        $.each(config.data, function (key, item) {
                            images.push(item);
                        });

                        return images;
                    },

                    /**
                     * Updates gallery data partially by index
                     */
                    updateDataByIndex: function(index, item) {
                        config.data.splice(index, 1, item);
                        gallery.reInitSlider();
                    }
                };

            sliderElement.data('gallery', api);
            this.config.api = sliderElement.data('gallery');
            sliderElement.trigger('gallery:loaded');
        }
    });
});
