# Evalent AdminACtivityLog
Logs most activities that users do in admin such as product, category and config changes.

The activity is split in 5 categories.

## Model Activity
This includes all entities that extends \Magento\Framework\Model\AbstractModel.
I.e Products, Categories, Order, Items (In orders, creditmemo, invoice and quote).

## Config Activity
This logs all the changes made in configuration

## Login Activity  
Logs successful logins and logouts but also unsuccessful to spot potentials hacking attempts 

## CMS Activity
Any changes to pages, blocks or widgets are logged here

## Cache Activity
Logs when used clear the cache in the store
