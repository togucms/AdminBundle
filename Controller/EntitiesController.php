<?php

/*
 * Copyright (c) 2012-2014 Alessandro Siragusa <alessandro@togu.io>
 *
 * This file is part of the Togu CMS.
 *
 * Togu is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Togu is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with Togu.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Togu\AdminBundle\Controller;

use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\View\View;
use \JMS\Serializer\SerializationContext;
use \Doctrine\DBAL\DBALException;
use \JMS\Serializer\DeserializationContext;
use FOS\RestBundle\Controller\Annotations\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class EntitiesController
 */
class EntitiesController extends FOSRestController {
    /**
     * Get detail of a record
     * @param              $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @QueryParam(name="group", description="The JMS Serializer group", default="")
     * @QueryParam(name="depth", description="The depth to use for serialization", default="1")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getEntityAction(ParamFetcherInterface $paramFetcher, $id) {
    	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
        $manager = $this->get("doctrine_phpcr.odm.default_document_manager");
        $entity = $manager->getRepository($modelLoader->getFullClassName($entityName))->find($id);
        $view = View::create(array(
        	"success" => $entity !== null,
        	"total" => $entity !== null ? 1 : 0,
        	"records" => array($entity)
        ), 200)->setSerializationContext($this->getSerializerContext(array("get")));;
        return $this->handleView($view);
    }

    /**
     * Get list of records
     * @param ParamFetcherInterface $paramFetcher
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @QueryParam(name="page", requirements="\d+", default="1", description="Page of the list.")
     * @QueryParam(name="start", requirements="\d+", default="0", description="Offset of the list")
     * @QueryParam(name="limit", requirements="\d+", default="25", description="Number of record per fetch.")
     * @QueryParam(name="sort", description="Sort result by field in URL encoded JSON format", default="[]")
     * @QueryParam(name="filter", description="Search filter in URL encoded JSON format", default="[]")
     * @QueryParam(name="gridFilter", description="Search filter in URL encoded JSON format", default="[]")
     * @QueryParam(name="group", description="The JMS Serializer group", default="")
     * @QueryParam(name="depth", description="The depth to use for serialization", default="1")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getEntitiesAction(ParamFetcherInterface $paramFetcher) {
    	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
    	$manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $rawSorters = json_decode($paramFetcher->get("sort"), true);
        $sorters = array();
        foreach ($rawSorters as $s) {
            $sorters[$s['property']] = $s['direction'];
        }
        $rawFilters = json_decode($paramFetcher->get("filter"), true);
        $filters = array();
        foreach ($rawFilters as $f) {
            $filters[$f['property']] = $f['value'];
        }
        $rawGridFilters = json_decode($paramFetcher->get("gridFilter"), true);
        foreach ($rawGridFilters as $f) {
        	$comparator = isset($f['comparison']) ? $f['comparison'] : 'eq';
        	$filters[$f['field']] = array(
        		$comparator => $f['value']
        	);
        }
        $start = 0;
        if ($paramFetcher->get("start") === "0") {
            if ($paramFetcher->get("page") > 1) {
                $start = ($paramFetcher->get("page")-1) * $paramFetcher->get("limit");
            }
        } else {
            $start = $paramFetcher->get("start");
        }
        $repository = $manager->getRepository($modelLoader->getFullClassName($entityName));
        $list = $repository->findBy(
            $filters,
            $sorters,
            $paramFetcher->get("limit"),
            $start
        );
        $count = $repository->findBy(
        	$filters
        );

        $list = array_values($list->toArray());
        $context = $this->getSerializerContext(array('list'));
        $view = View::create(array(
        	"success" => true,
        	"records" =>  $list,
        	"total" => count($count)
        ), 200)->setSerializationContext($context);
        return $this->handleView($view);
    }

    /**
     * Create a new record
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function postEntitiesAction(ParamFetcherInterface $paramFetcher) {
       	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
    	$serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
        	$modelLoader->getFullClassName($entityName),
            'json',
            DeserializationContext::create()->setGroups(array("Default", "post"))
        );
        $validator = $this->get('validator');
        $validations = $validator->validate($entity, array('Default', 'post'));
        if ($validations->count() === 0) {
            $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
            $rootData = $manager->find(null, '/data');
            $entity->setParentDocument($rootData);
            $manager->persist($entity);
            try {
                $manager->flush();
            } catch (DBALException $e) {
                return $this->handleView(
                    View::create(array('errors'=>array($e->getMessage())), 400)
                );
            }
            return $this->handleView(
                View::create(array(
		        	"success" => $entity !== null,
		        	"total" => $entity !== null ? 1 : 0,
		        	"records" => array($entity)
		        ), 201, array('Location'=>$this->generateUrl(
                    "_togu_admin_bundle_api_get_entities",
                    array('id'=>$entity->getId()),
                    true
                )))->setSerializationContext($this->getSerializerContext())
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Update an existing record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putEntitiesAction(ParamFetcherInterface $paramFetcher, $id) {
       	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
    	$manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository($modelLoader->getFullClassName($entityName))->find($id);
        if ($entity === null) {
            return $this->handleView(View::create('', 404));
        }
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
            $modelLoader->getFullClassName($entityName),
            'json',
            DeserializationContext::create()->setGroups(array("Default", "put"))
        );
        $validator = $this->get('validator', array('Default', 'put'));
        $validations = $validator->validate($entity);
        if ($validations->count() === 0) {
            try {
                $manager->merge($entity);
                $manager->flush();
            } catch (DBALException $e) {
                return $this->handleView(
                    View::create(array('errors'=>array($e->getMessage())), 400)
                );
            }
            return $this->handleView(
                View::create(array(
		        	"success" => $entity !== null,
		        	"total" => $entity !== null ? 1 : 0,
		        	"records" => array($entity)
		        ), 200)->setSerializationContext($this->getSerializerContext(array("get")))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Patch an existing record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function patchEntitiesAction(ParamFetcherInterface $paramFetcher, $id) {
       	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
    	$manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository($modelLoader->getFullClassName($entityName))->find($id);
        if ($entity === null) {
            return $this->handleView(View::create('', 404));
        }
        $content = json_decode($this->getRequest()->getContent(), true);
        $content['id'] = $id;
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $dContext = DeserializationContext::create()->setGroups(array("Default", "patch"));
        $dContext->attributes->set('related_action', 'merge');
        $entity = $serializer->deserialize(
            json_encode($content),
            $modelLoader->getFullClassName($entityName),
            'json',
            $dContext
        );
        $validator = $this->get('validator');
        $validations = $validator->validate($entity, array('Default', 'patch'));
        if ($validations->count() === 0) {
            try {
                $manager->flush();
            } catch (DBALException $e) {
                return $this->handleView(
                    View::create(array('errors'=>array($e->getMessage())), 400)
                );
            }
            return $this->handleView(
                View::create(array(
		        	"success" => $entity !== null,
		        	"total" => $entity !== null ? 1 : 0,
		        	"records" => array($entity)
		        ), 200)->setSerializationContext($this->getSerializerContext(array("get")))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Delete an existing record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="entity", description="EntityName", default="")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteEntitiesAction(ParamFetcherInterface $paramFetcher, $id) {
       	$entityName = $paramFetcher->get("entity");
    	$modelLoader = $this->get("togu.generator.model.config");
    	if(! $modelLoader->hasModel($entityName)) {
    		throw new NotFoundHttpException("Entity $entityName is not defined");
    	}
    	$manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository($modelLoader->getFullClassName($entityName))->find($id);
        $manager->remove($entity);
        $manager->flush();
        return $this->handleView(View::create(null, 204));
    }


    protected function getSerializerContext($groups = array(), $version = null) {
        $serializeContext = SerializationContext::create();
        $serializeContext->enableMaxDepthChecks();
        $serializeContext->setGroups(array_merge(
            array(\JMS\Serializer\Exclusion\GroupsExclusionStrategy::DEFAULT_GROUP),
            $groups
        ));
        if ($version !== null) $serializeContext->setVersion($version);
        return $serializeContext;
    }
}
