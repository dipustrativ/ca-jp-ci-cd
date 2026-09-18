# Module CarpetAvenue ReservationsFix

    ``carpetavenue/module-reservationsfix``


## Explanations
When using multiple sales channels as well as stocks for different websites the calculations 
with reservations breaks. 

When having a source allocated to multiple stocks and a reservation is made in one stock (a website)
the saleable quantity in the other stock is not deducting the reserved item for the other sales channel.

## Function
This module battles this issue by moving the stock around when an order is made.

When an order is made in selected stock the module checks the available stock with reservations and 
moved stock around if needed.

## Possible issues
When moving stock around in Magento the product is still located physically in the same spot
and this can cause confusion for admin workers.

When a product is returned, to which stock is it returned to?
In our special case we choose to move it back to a specific stock based on order status.



