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
 *
 * You should have received a copy of the GNU General Public License
 * along with Togu.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Togu\AdminBundle\Controller;

use Doctrine\ORM\EntityManager;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\Annotations\Route;
use \JMS\Serializer\SerializationContext;
use \Doctrine\DBAL\DBALException;
use \JMS\Serializer\DeserializationContext;
use Application\Togu\ApplicationModelsBundle\Document\Page;

/**
 * Class PageController
 */
class PageController extends FOSRestController {
    /**
     * Get detail of a Page record
     * @param              $id
	 *
     * @Route(requirements={"id"=".+"})      * @QueryParam(name="group", description="The JMS Serializer group", default="")
     * @QueryParam(name="depth", description="The depth to use for serialization", default="1")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getPageAction($id) {
        $manager = $this->get("doctrine_phpcr.odm.default_document_manager");
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Page')->find($id);
        $view = View::create($entity, 200)->setSerializationContext($this->getSerializerContext(array("get")));;
        return $this->handleView($view);
    }

    /**
     * Get list of Page record
     * @param ParamFetcherInterface $paramFetcher
     *
     * @QueryParam(name="page", requirements="\d+", default="1", description="Page of the list.")
     * @QueryParam(name="start", requirements="\d+", default="0", description="Offset of the list")
     * @QueryParam(name="limit", requirements="\d+", default="25", description="Number of record per fetch.")
     * @QueryParam(name="sort", description="Sort result by field in URL encoded JSON format", default="[]")
     * @QueryParam(name="filter", description="Search filter in URL encoded JSON format", default="[]")
     * @QueryParam(name="group", description="The JMS Serializer group", default="")
     * @QueryParam(name="depth", description="The depth to use for serialization", default="1")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getPagesAction(ParamFetcherInterface $paramFetcher) {
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
        $start = 0;
        if ($paramFetcher->get("start") === "0") {
            if ($paramFetcher->get("page") > 1) {
                $start = ($paramFetcher->get("page")-1) * $paramFetcher->get("limit");
            }
        } else {
            $start = $paramFetcher->get("start");
        }
        $list = $manager->getRepository('ApplicationToguApplicationModelsBundle:Page')->findBy(
            $filters,
            $sorters,
            $paramFetcher->get("limit"),
            $start
        );
        $list = array_values($list->toArray());
        $context = $this->getSerializerContext(array('list'));
        $view = View::create($list, 200)->setSerializationContext($context);
        return $this->handleView($view);
    }

    /**
     * Create a new Page record
     *
     * @Route(requirements={"id"=".+"})      * @return \Symfony\Component\HttpFoundation\Response
     */
    public function postPagesAction() {
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
            'Application\Togu\ApplicationModelsBundle\Document\Page',
            'json',
            DeserializationContext::create()->setGroups(array("Default", "post"))
        );
        $validator = $this->get('validator');
        $validations = $validator->validate($entity, array('Default', 'post'));
        if ($validations->count() === 0) {
            $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
            $manager->persist($entity);
            try {
                $manager->flush();
            } catch (DBALException $e) {
                return $this->handleView(
                    View::create(array('errors'=>array($e->getMessage())), 400)
                );
            }
            return $this->handleView(
                View::create($entity, 201, array('Location'=>$this->generateUrl(
                    "_togu_admin_bundle_api_get_page",
                    array('id'=>$entity->getId()),
                    true
                )))->setSerializationContext($this->getSerializerContext(array('Default', 'post')))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Update an existing Page record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})      * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putPageAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Page')->find($id);
        if ($entity === null) {
            return $this->handleView(View::create('', 404));
        }
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
            'Application\Togu\ApplicationModelsBundle\Document\Page',
            'json',
            DeserializationContext::create()->setGroups(array("Default", "put"))
        );
        $entity->setId($id);
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
                View::create($entity, 200)->setSerializationContext($this->getSerializerContext(array("get")))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Patch an existing Page record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})      * @return \Symfony\Component\HttpFoundation\Response
     */
    public function patchPageAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Page')->find($id);
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
            'Application\Togu\ApplicationModelsBundle\Document\Page',
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
                View::create($entity, 200)->setSerializationContext($this->getSerializerContext(array("get")))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Delete an existing Page record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})      * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deletePageAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Page')->find($id);
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
