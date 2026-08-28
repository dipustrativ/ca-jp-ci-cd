# Evalent_DynamicSimilarProducts

This module adds dynamic related products based on the category of the product and sort them according
to two attributes. The two attributes can be picked in admin configuration.
 
As a product can have many categories the category with the highest ID is chosen
(hopefully should this be the category furthest down in the tree).

It when will sort the products according to the attributes chosen in admin in the following way: (First to last)
``` 
1. When the main attribute matches and the second attribute matches with the products attributes
2. When the the main attribute matches
3. When the second attribute matches
4. The rest in no particular order 
```

The attributes has to be either a select, text or int value. If an error occurs in the calculations,
 if the value is an Array for example, the module with allow Magento standard function to continue