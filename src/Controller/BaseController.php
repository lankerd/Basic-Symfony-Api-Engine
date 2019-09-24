<?php

namespace App\Controller;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @author Julian Lankerd <julian@corephp.com>
 */
class BaseController extends AbstractFOSRestController
{
    /**
     * @var \Symfony\Component\Cache\Adapter\AdapterInterface
     */
    protected $cache;

    protected $serializer;

    public function __construct(RequestStack $requestStack, AdapterInterface $cache, SerializerInterface $serializer)
    {
        /**
         * Grab the secret key, and make sure it is correct.
         **/
        $secretKey = $requestStack->getCurrentRequest()->headers->get('secretKey');
        ($secretKey === 'y@9l&eZRDe&cLq$rI*U^0bnE3#!t%y') or exit('Authorization Failed');

        /**
         * Set a Serializer property so that we can correctly serialize data for insertion to Redis.
         **/
        $this->serializer = $serializer;

        /**
         * Set a Cache property so that we can begin to interact with Redis.
         **/
        $this->cache = $cache;
    }
}
