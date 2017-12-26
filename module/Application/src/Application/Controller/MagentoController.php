<?php
/**
 * MagentoController.php
 *
 * Controller for demonstration the Magento SOAP Responses and Requests in API mode combined together with API version 1
 * and API version 2. In addition controller accelerated by Memcache support for easier access to SOAP and CURL responses.
 *
 * PHP version 7
 *
 * @category e-commerce
 * @author Aleksandr Skripov
 * @copyright 2017 Aleksandr Skripov
 * @version 1.0.0
 */

namespace Application\Controller;

ini_set('soap.wsdl_cache_ttl', 0);
ini_set("soap.wsdl_cache_enabled", 0);

use Zend\Json\Json;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Soap\Client;
use Zend\Config\Reader\Xml;

/**
 * Class MagentoController
 * @package Application\Controller
 */
class MagentoController extends AbstractActionController implements MagentoInterface
{
    protected $wsdl_v1;
    protected $wsdl_v2;
    protected $api_url_v2;
    protected $username;
    protected $password;
    
    public function __construct()
    {
        $this->setConfig();
    }

    /**
     * @return array|ViewModel
     */
    public function indexAction() 
    {
        $client = $this->reloadClientIfExpired($this->getCacheObject(self::CLIENT_V2));
        
        $from = $this->getRequest()->getQuery(self::UPDATED_FROM);
        $to = $this->getRequest()->getQuery(self::UPDATED_TO);
        $limit = $this->getRequest()->getQuery(self::LIMIT);          
        $productList = json_decode($this->getProductList($client));         
        // Activation of updated_at filter
        if (isset($from) || isset($to)) {            
            $updated_at = [self::UPDATED_FROM => $from, self::UPDATED_TO => $to];
            $productList = $this->filterUpdatedAt($productList, $updated_at);            
        }        
        // Activation of limits
        if (isset($limit)) {
             $productList = $this->filterLimit($productList, $limit);
        }        
 		return new ViewModel(array(
 			'products' => $productList
		));
    }

    /**
     * @return ViewModel
     */
    public function productAction()
    {
        $productId = $this->getRequest()->getQuery('id');        
        $client = $this->reloadClientIfExpired($this->getCacheObject(self::CLIENT_V2));
        $product = json_decode($this->getProductDetails($client, $productId)); 
		return new ViewModel(array(
            'product' => $product
        ));
    }

    /**
     * @return ViewModel
     */
    public function productListCurlAction()
    {
        $sessionId = $this->getSessionIdByCurl();
        $productList = json_decode($this->getProductListByCurl($sessionId));
        return new ViewModel(array(
            'products' => $productList
        ));   
    }
    
    /**
     * @param int $version
     * @return Client
     */
    public function getClient($version=self::VERSION_V2) 
    {
        $wsdl = ($version == self::VERSION_V2 ? $this->wsdl_v2 : $this->wsdl_v1);
		$client = new Client($wsdl);
        $client_version = (self::VERSION_V2 ? self::VERSION_V2 : self::VERSION_V1);
        $this->setCacheObject($client, $client_version);
    	return $client;
    }

    /**
     * TODO: Rewrite with getters and setters to avoid multi generation.
     * @param $client
     * @return mixed
     */
    public function getSessionId($client) 
    {
        $sessionId = $client->login($this->username, $this->password);
        $this->setCacheObject($sessionId, self::SESSION_ID_V2);
        return $sessionId;
    }

    /**
     * @param $client
     * @return array|Json|string|void
     */
    public function getProductList($client)
    {
        $sessionId = $this->generateSessionIdIfNotExist($this->getCacheObject(self::SESSION_ID_V2), $client);                
        $cachedList = $this->getCacheObject(self::PRODUCT_LIST);
        if(is_null($cachedList)) {
            try {                
                $list = $client->catalogProductList($sessionId);
            } catch (\Exception $e){
               $arguments = array($client);
               return $this->handleErrors($e,'getProductList', $arguments);
            }           
            $json = json_encode($list);
            $this->setCacheObject($json, self::PRODUCT_LIST);            
            $json = $this->bulkListWithDetails();
            return $json;
        }
        return $list = $this->getCacheObject(self::PRODUCT_LIST);						
    }


    /**
     * @param $client
     * @param $productId
     * @return array|string|void
     */
    public function getProductDetails($client, $productId)
    {
        $sessionId = $this->generateSessionIdIfNotExist($this->getCacheObject(self::SESSION_ID_V2), $client);
        $cachedProduct=$this->getCacheObject(self::PRODUCT.$productId);    
        if(is_null($cachedProduct)) {            
            try {                
                $product = $client->catalogProductInfo($sessionId, $productId);
            } catch (\Exception $e) {
                $arguments = array($client, $productId);
                return $this->handleErrors($e, 'getProductDetails', $arguments);
            }               
            $json = json_encode($product);
            $this->setCacheObject($json, self::PRODUCT.$productId);
            return $json;
        }
        return $product = $this->getCacheObject(self::PRODUCT.$productId);   		
    }

    /**
     * @return string
     */
    public function bulkListWithDetails()
    {
        $sessionId = $this->getSessionId($this->getClient(self::VERSION_V1));
        $client = $this->reloadClientIfExpired($this->getCacheObject(self::CLIENT_V2));
        $list = json_decode($this->getCacheObject(self::PRODUCT_LIST));
        $multiCall = array();
        // Creating array for multiCall function from SOAP V1. MultiCall is not available in SOAP v2. It is much faster instead of using one by one calls from SOAP V2.       
        foreach ($list as $product) { 
            array_push($multiCall, array('catalog_product.info', $product->product_id ));
        }        
        $productsInfo = $client->multiCall($sessionId, $multiCall);
        foreach ($productsInfo as $product) { 
            $this->setCacheObject(json_encode($product), self::PRODUCT.$product['product_id']);
        }
        $list = (array)$list;        
        $productsInfo = (array)$productsInfo;
        // Merging arrays 
        array_map(function($a,$b){$a->details = $b;}, $list, $productsInfo); 
        $json = json_encode($list); 
        $this->setCacheObject($json, self::PRODUCT_LIST);  
        return $json;
    }

    /**
     * @param $error
     * @param string $method
     * @param $arguments
     * @return array|string|void|Json
     */
    public function handleErrors($error, $method, $arguments)
    {
        $faultCode = $error->faultcode;
        $message = $error->faultstring;
        $client = $arguments[0];
        $sessionId = $this->getSessionId($client);
        if ($faultCode == self::SESSION_EXPIRED) {                        
            $this->setCacheObject($sessionId, self::SESSION_ID_V2);  
            if(isset($arguments[1])) {
                $optional = $arguments[1];
                return $this->$method($client, $optional);
            }          
            return $this->$method($client);            
        } elseif ($faultCode == self::FAULT_SENDER) {
            $this->setCacheObject($sessionId, self::SESSION_ID_V2);
            return $this->getProductList($client);
        }
        $jsonError = array('errorCode'=>$faultCode, 'message'=>$message);
        return json_encode($jsonError);
    }

    /**
     * @return array
     */
    public function getSessionIdByCurl()
    {
        $xml  =    "<SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\">\n";
        $xml .=    "<SOAP-ENV:Body>\n";
        $xml .=    "<MAGE:login xmlns:MAGE=\"".$this->wsdl_v2."\"\n>";
        $xml .=    "<MAGE:username>". $this->username."</MAGE:username>\n";
        $xml .=    "<MAGE:apiKey>".$this->password."</MAGE:apiKey>\n";
        $xml .=    "</MAGE:login>\n";
        $xml .=    "</SOAP-ENV:Body>\n";
        $xml .=    "</SOAP-ENV:Envelope>\n";    
        $response = $this->curlXmlRequest($xml);        
        $response = $response['SOAP-ENV:Body']['ns1:loginResponse']['loginReturn']['_'];
        return $response;        
    }

    /**
     * @param $sessionId
     * @param null $filters
     * @return array|string
     */
    public function getProductListByCurl($sessionId, $filters=null)
    {
        $xml  = "<SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\">\n";
        $xml .= "<SOAP-ENV:Body>\n";
        $xml .= "<MAGE:catalogProductList xmlns:MAGE=\"".$this->wsdl_v2."\">\n";
        $xml .= "<MAGE:sessionId>".$sessionId."</MAGE:sessionId>\n";
        $xml .= "<MAGE:filters>".$filters."</MAGE:filters>\n";
        $xml .= "<MAGE:storeView></MAGE:storeView>\n";
        $xml .= "</MAGE:catalogProductList>\n";
        $xml .= "</SOAP-ENV:Body>\n";
        $xml .= "</SOAP-ENV:Envelope>\n";        
        $response = $this->curlXmlRequest($xml);
        $response = $response['SOAP-ENV:Body']['ns1:catalogProductListResponse']['storeView']['item'];
        $response = json_encode($response);
        return $response;
    }

    /**
     * @param $xml
     * @return array|bool|mixed
     */
    public function curlXmlRequest($xml)
    {
        $header = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "SOAPAction: \"run\"",
            "Content-length: ".strlen($xml),
        ); 
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url_v2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT,        10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );        
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST,           true );
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $header);
        // Available also for secure connections
        if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === "on") { 
            curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        $reader = new Xml();
        $response = $reader->fromString($response);
        return $response;
    }

    /**
     * @param $productList
     * @param $updated_at
     * @return array
     */
    public function filterUpdatedAt($productList, $updated_at)
    {
        $updated_at = array_filter($updated_at, function($v) {return !is_null($v);});
        $filter = function ($updated_at) {
            return function ($v) use ($updated_at) {
                $from = $updated_at[self::UPDATED_FROM];
                $to = $updated_at[self::UPDATED_TO];   
                $filtered = ((($from ?? $to) === $from) && count($updated_at) == 1) ? (($v->details->updated_at) >= $from) : (($v->details->updated_at) >= $from && ($v->details->updated_at) <= $to);
                return $filtered;
            };
        };
        return $productList = array_filter($productList, $filter($updated_at));
    }

    /**
     * @param $client
     * @return Client
     */
    public function reloadClientIfExpired($client)
    {
        if(is_null($client)) {
            $client = $this->getClient();
        }
        return $client;
    }

    /**
     * @param $productList
     * @param $limit
     * @return array
     */
    public function filterLimit($productList, $limit)
    {
        // PHP Version  >= 5.6.0    Added optional flag parameter and constants ARRAY_FILTER_USE_KEY and ARRAY_FILTER_USE_BOTH. Read here http://php.net/manual/en/function.array-filter.php
        $limit = explode(",", $limit);
        $filter = function($limit, $productListLength) {
            return function ($v) use ($limit, $productListLength) {
                $left = (!empty($limit[0])) ? $limit[0] : 1;
                $right = (!empty($limit[1])) ? $limit[1] : $productListLength;
                return (($v >= $left-1) && ($v <= $right-1));
            };
        };
        return $productList = array_filter($productList, $filter($limit, count($productList)),ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param $sessionId
     * @param $client
     * @return mixed
     */
    public function generateSessionIdIfNotExist($sessionId, $client)
    {
        if(is_null($sessionId)) {
            // TODO: $sessionId as class property
            // TODO: declare setSessionId function
            $sessionId = $this->getSessionId($client);
            $this->setCacheObject($sessionId, self::SESSION_ID_V2);
        }   
        return $sessionId;
    }

    /**
     * @param $object
     * @param $alias
     */
    public function setCacheObject($object, $alias)
    {
        $this->getServiceLocator()->get('memcached')->setItem($alias, $object);
    }

    /**
     * @param $alias
     * @return mixed
     */
    public function getCacheObject($alias)
    {
        $object = $this->getServiceLocator()->get('memcached')->getItem($alias);
        return $object;
    }

    
    protected function setConfig()
    {
        $config = $this->getServiceLocator()->get('Config');
        $wsdl_v2 = $config['magento']['wsdl']['v2'];
        $wsdl_v1 = $config['magento']['wsdl']['v1'];
        $api_url_v2 = $config['magento']['url']['v2'];
        $username = $config['magento']['username'];
        $password = $config['magento']['password'];
        $this->wsdl_v2 = $wsdl_v2;
        $this->wsdl_v1 = $wsdl_v1;
        $this->api_url_v2 = $api_url_v2;
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

}