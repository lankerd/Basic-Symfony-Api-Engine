<?php

namespace App\Controller;

use DomainException;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use Exception;
use JMS\Serializer\SerializerInterface;
use LogicException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Inflector\Inflector;

/**
 * @author Julian Lankerd <julian@corephp.com>
 */
class BaseController extends AbstractFOSRestController
{
    public const ENTITY_NAMESPACE = 'App\\Entity\\';

    /**
     * @var \Symfony\Component\Cache\Adapter\AdapterInterface
     */
    protected $cache;

    protected $serializer;

    protected $companyKey;

    public function __construct(RequestStack $requestStack, AdapterInterface $cache, SerializerInterface $serializer)
    {
        /**
         * Grab the secret key, and make sure it is correct.
         **/
        $secretKey = $requestStack->getCurrentRequest()->headers->get('secretKey');
        if('8#Jx8pGw2gzhiAh&I^Gb68Z*C31@3k' !== $secretKey) {
            throw $this->createAccessDeniedException();
        }

        /**
         * Grab the company ApiKey. This will
         * allow us to do searches based company information
         **/
        $this->companyKey = $requestStack->getCurrentRequest()->headers->get('companyKey');

            /**
         * Set a Serializer property so that we can correctly serialize data for insertion to Redis.
         **/
        $this->serializer = $serializer;

        /**
         * Set a Cache property so that we can begin to interact with Redis.
         **/
        $this->cache = $cache;
    }

    /**
     * todo: Move this off to a service
     *
     * @param Request $request
     * @param $entityPath
     * @param $formPath
     *
     * @return bool
     */
    protected function postRequest(Request $request, $entityPath, $formPath): bool
    {
        /*Grab the Doctrine Entity Manager so that we can process our Entity to the database.*/
        $entityManager = $this->getDoctrine()->getManager();

        /*Unpack and decode data from $request in order to obtain form information.*/
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        /*Instantiate a new User object for us to insert the $request form data into.*/
        $entity = new $entityPath();

        /*Create form with corresponding Entity paired to it*/
        $form = $this->createForm($formPath, $entity);

        /*Submit $data that was unpacked from the $response into the $form.*/
        $form->submit($data);

        /*Check if the current $form has been submitted, and is valid.*/
        if ($form->isSubmitted() && $form->isValid())
        {
            /**
             * Encapsulate attempt to store data
             * into database with try-catch. This
             * will ensure in the case of an error
             * we will catch the exception, and throw
             * it back as a suitable response.
             */
            try {
                /**
                 * Persist() will make an instance of the entity
                 * available for doctrine to submit to the Database.
                 */
                $entityManager->persist($entity);

                /**
                 * Using Flush() causes write operations against the
                 * database to be executed. Which means if you
                 * used Persist($object) before flushing,
                 * You'll end up inserting a new record
                 * into the Database.
                 */
                $entityManager->flush();
            } catch (Exception $e) {
                /**
                 * Throw a new exception to inform sender of the error.
                 *
                 * If an exception is thrown (an error is found), it will stop the process,
                 * and show the error that occurred in the "try" brackets.
                 * Instead of showing the exact error that occured in the exception,
                 * we're gonna over-generalize the error, because you never know when
                 * something nefarious may be afoot.
                 */
                throw new RuntimeException('There was an issue inserting the record!', $e->getCode());
            }

            /*Return a 200 status, successfully completing the transaction*/
            return true;
        }

        /*Return a 400 status, failing to complete the transaction*/
        return false;
    }

    /**
     * todo: Move this off to a service.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return string
     * @throws \ReflectionException
     */
    protected function getAllValues(Request $request): ?string
    {
        try {
            $entityName = str_replace('Controller', '', (new ReflectionClass($this))->getShortName());
        } catch (ReflectionException $e) {
            throw $e;
        }

        if (!class_exists($path = self::ENTITY_NAMESPACE.$entityName)) {
            throw new LogicException($entityName.' does not exist!');
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        try {
            return new Response(
                $this->serializer->serialize($this->getDoctrine()->getRepository($path)->findAll(), 'json'),
                Response::HTTP_OK,
                ['Content-type' => 'application/json']
            );
        } catch (ReflectionException $e) {
            throw new RuntimeException($e);
        }
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function coupler(Request $request): void
    {
        /*Grab the Doctrine Entity Manager so that we can process our Entity to the database.*/
        $entityManager = $this->getDoctrine()->getManager();

        /*Unpack and decode data from $request in order to obtain form information.*/
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        /*There should be information that can be used to find the specified "primaryEntity" in the database.*/
        $primaryEntity = $data['primaryEntity'];

        /*There should be information that can be used to find the multiple specified "secondaryEntities" in the database.*/
        $secondaryEntities = $data['secondaryEntities'];

        /**
         * Placing this into a try-catch is definitely a wise call
         * considering we are stuffing kinda questionable stuff into the
         * findBy.
         *
         * Query for the $primaryEntity, so that we can begin to set relationships
         * to the $primaryEntity. This is the target we will be setting database
         * relationships towards.
         */
        try {
            $primaryEntity = $this->getDoctrine()->getRepository('App:'.key($primaryEntity))->findBy(
                $primaryEntity[key($primaryEntity)]
            );
        } catch (Exception $e) {
            throw new $e;
        }

        /*Check if there are many objects that have been returned to the return*/
        $primaryEntity = $this->hasOneValue($primaryEntity, 'primaryEntity');

        try {
            $objectProperties = $this->getObjectProperties($primaryEntity);
        } catch (ReflectionException $e) {
            throw new $e;
        }

        foreach ($secondaryEntities as $entityName => $entityData) {
            $entityName = $this->curateSingularizedName(ucfirst($entityName));

            /**
             * Check if any of the properties passed are an array.
             * If the property is an exception will be thrown
             * because the functionality does not currently exist.
             *
             * Why would someone wish to pass an array in a property?
             * Collections or associations. Someone may wish to search
             * for information to pair up based on a certain relationship.
             *
             * Sorry for any temporary inconveniences! It'll be usable soon!
             *
             * todo: Julian needs to build association functionality capable in the "coupler"
             */
            
            foreach ($entityData as $index => $entityDatum) {
                if (is_array($entityDatum)){
                    throw new DomainException(
                        'It appears an array was passed as a property! This functionality is not yet available, but will be coming soon!'
                    );
                }
            }
            
            /**
             * Placing this into a try-catch is definitely a wise call
             * considering we are stuffing kinda questionable stuff into the
             * findBy.
             *
             * Query for the $primaryEntity, so that we can begin to set relationships
             * to the $primaryEntity. This is the target we will be setting database
             * relationships towards.
             */
            try {
                $returnedValue = $this->getDoctrine()->getRepository('App:'.$entityName)->findBy($entityData);
            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }

            /*Check if there are many objects that have been returned to the return*/
            $entity = $this->hasOneValue($returnedValue, $entityName);

            $bindingMethod = null;
            foreach ($objectProperties[ucfirst($entityName)] as $objectMethod) {
                if (false !== stripos($objectMethod, 'add')) {
                    $bindingMethod = $objectMethod;
                }

                if (false !== stripos($objectMethod, 'set')) {
                    $bindingMethod = $objectMethod;
                }
            }

            $primaryEntity->$bindingMethod($entity);
        }
        $entityManager->persist($primaryEntity);
        $entityManager->flush();
    }

    /**
     * This will grab all properties of the provided entity
     * and return an array of the properties with their
     * associated methods that can be accessed to sort through.
     *
     * @param object $object
     *
     * @return array
     * @throws \ReflectionException
     */
    private function getObjectProperties(object $object) : array
    {
        /**
         * Initalize objectProperties array in
         * order to have a place to store the
         * property names
         */
        $objectProperties = [];

        /**
         * Create a reflection of the object
         * that has been provided. This will
         * allow us to access all information
         * pertinent to the object.
         */
        try {
            $objectReflection = new ReflectionClass($object);
        } catch (ReflectionException $e) {
            throw $e;
        }


        $objectReflectionMethods = $objectReflection->getMethods();
        /**
         * Loop through all of the objects
         * properties, and store the name
         * of each property into the
         * $objectProperties array.
         */
        foreach ($objectReflection->getProperties() as $property) {
            /*Grab the property name*/
            $propertyName = $property->getName();
            $singularizedPropertyName = $this->curateSingularizedName(ucfirst($propertyName));

                $methodNames = [];
                foreach ($objectReflectionMethods as $method) {
                    $methodName = $method->getName();
                    $singularizedMethodName = $this->curateSingularizedName(ucfirst($methodName));
//                    dump(preg_match("~$propertyName~",strtolower($methodName)), strtolower($methodName), $propertyName);
//                    if (mb_strripos($methodName, $propertyName)){


//                    if (preg_match('~\z'.ucfirst(rtrim($propertyName, 's')).'~',$methodName)){
                    if ($this->endsWith( $singularizedMethodName, $singularizedPropertyName)){
                        $methodNames[] = $methodName;
                    }
                }

            /**
             * For those who don't know, when
             * inserting data into an array
             * in PHP without ever defining an array's
             * keys is called auto-incremented keys.
             * That is exactly what's happening below,
             * we are telling PHP to auto create a key
             * as we fill the array with the value that
             * has been provided!
             */
            $objectProperties[$singularizedPropertyName] = $methodNames;
        }

        /**
         * Finally we will return the
         * array of properties that have
         * been associated to the object.
         */
        return $objectProperties;
    }

    private function curateSingularizedName(string $name): string
    {

        $singularizedName = Inflector::singularize($name);
        if (is_array($singularizedName)){
            $singularizedName = end($singularizedName);
        }
        return $singularizedName;
    }

    /**
     * @param $haystack
     * @param $needle
     *
     * @return bool
     */
    private function endsWith($haystack, $needle): bool
    {
        $length = strlen($needle);
        if ($length === 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }

    /**
     * This will quickly become legacy,
     * and unused, but for the moment
     * it'll be used to ensure one value
     * was assigned to a variable.
     *
     * @param array  $data
     * @param string $subjectsName
     *
     * @return object
     */
    private function hasOneValue(array $data, string $subjectsName = 'Entity') : object
    {
        /*Check if there are many objects that have been returned to the return*/
        if (count($data) !== 1) {
            throw new RuntimeException(
                'There was an issue retrieving the primary Entity. Expected to find 1 record. Found: '. count($data).'. Further specify information in: '.$subjectsName
            );
        }
        return $data[0];
    }


//    /**
//     * todo: Move this off to a service
//     */
//    private function getCompany(): Company
//    {
//        /*Checks to see if key is empty*/
//        ($this->companyKey) or $this->createAccessDeniedException();
//
//        /*Check and ensure the Company actually exists*/
//        $company = $this->getDoctrine()->getRepository('App:Company')->findOneBy(['apiKey' => $this->companyKey]);
//
//        if($company === null){
//            throw $this->createAccessDeniedException();
//        }
//        return $company;
//    }

}
