# Evalent MegaMenu

The user can choose a different root category for the menu by going to Config -> Catalog -> Catalog -> Category Top Navigation -> Change Top Menu Root Category

The Images are available in the category nodes when creating the menu.
They can be received but following methods:

``` 
    $node->getData('thumbnail_image_url') or $node->getThumnailImageUrl();
    $note->getData('image_url') or $node->getImageUrl();
    $note->getData('mobile_image_url') or $node->getMobileImageUrl();  
```