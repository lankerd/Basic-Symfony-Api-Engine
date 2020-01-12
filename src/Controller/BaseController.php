<?php

namespace App\Controller;

use DomainException;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use Exception;
use JMS\Serializer\SerializerInterface;
use Lankerd\GroundworkBundle\Handler\DataHandler;
use LogicException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Inflector\Inflector;

/**
 * @author Julian Lankerd <julian@corephp.com>
 */
class BaseController extends AbstractFOSRestController
{
    protected $dataHandler;

    public function __construct(DataHandler $dataHandler) {
        /**
         * Initialize GroundworkBundle object handler into the controller.
         */
        $this->dataHandler = $dataHandler;
    }


    /**
     * @Rest\Post("/manage/data")
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return JsonResponse
     */
    public function manageData(Request $request): JsonResponse
    {
        return $this->json($this->dataHandler->requestHandler($request));
    }
}
