<?php

return array(
    'caches' => array(
        'memcached' => array( //can be called directly via SM in the name of 'memcached'
            'adapter' => array(
                'name'     =>'memcached',
                'options'  => array(
                    'ttl' => 7200,
                    'servers'   => array(
                        array(
                            '127.0.0.1',11211
                        )
                    ),
                    'namespace'  => 'MAGENTO',
                    'liboptions' => array (
                        'COMPRESSION' => true,
                        'binary_protocol' => true,
                        'no_block' => true,
                        'connect_timeout' => 100
                    )
                )
            ),
            'plugins' => array(
                'exception_handler' => array(
                    'throw_exceptions' => false
                ),
            ),
        ),
    ),
);