<?php
namespace Application\Controller;

/**
 * Interface MagentoInterface
 * @package Application\Controller
 */
interface MagentoInterface
{
    //Error constants
    const SESSION_EXPIRED = 5;
    const FAULT_SENDER = "Sender";
    // Memcache keys
    const PRODUCT_LIST = 'productList';
    const SESSION_ID_V2 = 'sessionIdAPIVersion2';
    const SESSION_ID_V1 = 'sessionIdAPIVersion1';
    const CLIENT_V2 = 'clientAPIVersion2';
    const CLIENT_V1 = 'clientAPIVersion1';
    const PRODUCT = 'product-';
    // Numeric constants
    const VERSION_V1 = 1;
    const VERSION_V2 = 2;
    //Query parameters
    const UPDATED_FROM = 'updated_from';
    const UPDATED_TO =  'updated_to';
    const LIMIT = 'limit';


    /**
     * @param $object
     * @param $alias
     * @return mixed
     */
    public function setCacheObject($object, $alias);

    /**
     * @param $alias
     * @return mixed
     */
    public function getCacheObject($alias);
}