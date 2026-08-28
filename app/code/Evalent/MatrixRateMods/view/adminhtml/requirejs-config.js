// We replace magento packaging.js file to only get the items in the current source of which we want to create a shipment.
// Default Magento returns all items in the order
var config = {
    map: {
        '*': {
            "Magento_Shipping/order/packaging": 'Evalent_MatrixRateMods/order/packaging'
        }
    }
};
