# CarpetAvenue_ChangePayment Module

## Overview
The `CarpetAvenue_ChangePayment` module provides functionality to cancel and reorder products in a Magento 2 store. It is designed to handle both guest and logged-in customers.

## Classes

### `CarpetAvenue\ChangePayment\Helper\Order`

This class is responsible for the main functionality of the module. It provides methods to cancel an existing order and create a new one with the same products.

#### Methods

- `cancelAndReorder`: This method is the main entry point for the functionality. It validates the request parameters, checks if the customer is logged in and if they are the owner of the order, cancels the order, and creates a new one with the same products.
- `validateRequestParams`: This method validates the request parameters. It checks if the `order_id` and `email` are present in the request.
- `validateGuestOrder`: This method validates a guest order. It checks if the email in the request matches the email of the order.
- `createNewQuote`: This method creates a new quote with the same products as the cancelled order. If the customer is logged in, it also sets the customer for the quote.
- `cancelOrder`: This method cancels the order and stores the products of the order for later use.
- `setOrderProducts`: This method stores the products of the order.
- `logoutCustomer`: This method logs out the customer and redirects them to the login page with the necessary parameters to reorder after login.

## Order confirmation email link

`Plugin\Order\Email\Container\Template\ChangePaymentVars` adds `change_payment_url`
(and `is_invoice`) to the email template vars when the order was paid by bank
transfer (`evinvoicepayment`) and `sales/change_payment/enabled` is on.

The order confirmation templates are admin-managed (`email_template` table), so the
link that prints the URL is injected by the data patch
`Setup\Patch\Data\AddChangePaymentLinkToOrderEmails` into every template whose
`orig_template_code` is `sales_email_order_template` or
`sales_email_order_guest_template`. The block is wrapped in
`{{depend change_payment_url}}`, so other payment methods never see it. A template
created later in admin needs the block copied in by hand.
