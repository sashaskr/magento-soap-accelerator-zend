# Magento SOAP Accelerator based on Zend Framework
## Introduction
This is the Magento SOAP accelerator proof of concept. The main idea is to move all of the SOAP responded objects to Memcache storage. 
In this example, I demonstrated an ability to use both API versions. Demo application based on Zend Framework 2. 
There are two methods implemented:
1. By SOAP extension
2. By CURL extension
## Requirements 

* Zend 2 Skeleton application
* PHP version 7
* Memcached
* php7.0-memcached (NOT php7.0-memcache)
* CURL
* php7.0-soap
* php7.0-xml
* don't forget to enable mod_rewrite for apache
At the module.config.php replace Magento settings to yours. These settings at the bottom of the file. 

## Available functionality
### Filters
* __Limit filter__. There is one GET parameter: limit. The format is ?limit=A,B
  * If given only A and right is empty it is mean [A; +infinity)
  * If given only B and left is empty it is mean [0; B]
  * If given both it is mean [A; B]
  * If given only one number it is mean [0;number], vector from 0.
* __Updated_at filter__. There are two GET parameters: updated_from and updated_to. The format is 2001-02-01 23:00:00
  * If given only updated_from it is mean [updated_from; +infinity)
  * If given only updated_to it is mean [0; updated_to]
  * If given both it is mean [updated_from; updated_to]

## TODO
* Move all of the Magento settings to .ENV
* Add console router action to make a Memcache refresh by scheduled tasks
* Make a Magento plugin, based on this idea
* Add Docker/Vagrant support
