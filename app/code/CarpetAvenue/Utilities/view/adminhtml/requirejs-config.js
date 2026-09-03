/**
 * Copyright © MagestyApps. All rights reserved.
 * See LICENSE.txt for license details.
 */

var config = {
    map: {
        '*': {
            // Product "Images and Videos" gallery uploader: swap core for the
            // project copy that adds 'webp' to the hard-coded allowedExt.
            'Magento_Backend/js/media-uploader': 'CarpetAvenue_Utilities/js/media-uploader'
        }
    },
    config: {
        mixins: {
            'Magento_Ui/js/form/element/image-uploader': {
                'CarpetAvenue_Utilities/js/form/element/image-uploader-mixin': true
            }
        }
    }
};
