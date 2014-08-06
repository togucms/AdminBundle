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

use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\View\View;
use \JMS\Serializer\SerializationContext;
use \Doctrine\DBAL\DBALException;
use \JMS\Serializer\DeserializationContext;
use FOS\RestBundle\Controller\Annotations\Route;
use Application\Togu\ApplicationModelsBundle\Document\Page;
use Application\Togu\ApplicationModelsBundle\Document\Section;


/**
 * Class PageController
 * @package Togu\MediaBundle\Controller
 */
class SectionTreeController extends FOSRestController {

    /**
     * Get the Sections records corresponding to the url or null
     * @param ParamFetcherInterface $paramFetcher
     *
     * @Route(requirements={"url"=".+"})
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
	public function getIsurlavailableAction($url) {
		$manager = $this->get('doctrine_phpcr.odm.default_document_manager');

		try {
			$routeInfo = $this->get('router')->match($url);

			if(! isset($routeInfo['type']) || ! isset($routeInfo['_route'])) {
				$view = View::create(array(
					"forbidden" => true
				), 200);
				return $this->handleView($view);
			}
			$route = $manager->find(null, $routeInfo['_route']);
			$document = $route->getContent();
			if($document && $document instanceof Page) {
				$sectionConfig = $document->getSection()->getSectionConfig();

				$view = View::create(array(
					"id" => $sectionConfig->getId()
				), 200);
				return $this->handleView($view);
			}
		} catch (\Exception $e) {
		}
		$view = View::create(array(
			"available" => true
		), 200);
		return $this->handleView($view);
	}

    /**
     * Get list of a Sections records
     * @param ParamFetcherInterface $paramFetcher
     *
     * @Route(requirements={"id"=".+"})
     *
     * @QueryParam(name="group", description="The JMS Serializer group", default="")
     * @QueryParam(name="depth", description="The depth to use for serialization", default="1")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getSectiontreeAction($id, ParamFetcherInterface $paramFetcher) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');

        $parent = $manager->find(null, $id);

        if(! $parent instanceof Section) {
        	$parent = $parent->getSectionConfig();
        }

        $children = $parent->getNextSection();
        $list = array();
        foreach ($children as $section) {
        	$list[] = $section->getSectionConfig();
        }

        $context = $this->getSerializerContext(array('list'));
        $view = View::create($list, 200)->setSerializationContext($context);
        return $this->handleView($view);
    }

    /**
     * Create a new Section record
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function postSectiontreeAction() {
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
            'Application\Togu\ApplicationModelsBundle\Document\Section',
            'json',
            DeserializationContext::create()->setGroups(array("Default", "post"))
        );
		$modelLoader = $this->get('togu.generator.model.config');
		if(! $modelLoader->hasModel($entity->getType())) {
			throw new \InvalidArgumentException(sprintf('The model %s does not exist', $entity->getType()));
		}
        $validator = $this->get('validator');
        $validations = $validator->validate($entity, array('Default', 'post'));
        if ($validations->count() === 0) {
            $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
            $rootData = $manager->find(null, '/data');

            $sectionClassName = $modelLoader->getFullClassName($entity->getType());
            $section = new $sectionClassName(array(
				"sectionConfig" => $entity,
            	"parentDocument" => $rootData
            ));
            $entity->getParentSection()->getSectionConfig()->addNextSection($section);
            $entity->getPage()->setSection($section);
            $manager->persist($entity);
            $manager->persist($section);
            try {
                $manager->flush();
            } catch (DBALException $e) {
                return $this->handleView(
                    View::create(array('errors'=>array($e->getMessage())), 400)
                );
            }
            return $this->handleView(
                View::create(array(
                	"success" => true,
                	"records" => array($entity)
                ), 200)->setSerializationContext($this->getSerializerContext(array('Default', 'post')))
            );
        } else {
            return $this->handleView(
                View::create(array('errors'=>$validations), 400)
            );
        }
    }

    /**
     * Update an existing Section record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putSectiontreeAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Section')->find($id);
        if ($entity === null) {
            return $this->handleView(View::create('', 404));
        }
        $serializer = $this->get("tpg_extjs.phpcr_serializer");
        $entity = $serializer->deserialize(
            $this->getRequest()->getContent(),
            'Application\Togu\ApplicationModelsBundle\Document\Section',
            'json',
            DeserializationContext::create()->setGroups(array("Default", "put"))
        );
        $validator = $this->get('validator', array('Default', 'put'));
        $validations = $validator->validate($entity);
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
                	"success" => true,
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
     * Patch an existing Section record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function patchSectiontreeAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Section')->find($id);
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
            'Application\Togu\ApplicationModelsBundle\Document\Section',
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
                	"success" => true,
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
     * Delete an existing Section record
     * @param $id
     *
     * @Route(requirements={"id"=".+"})
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteSectiontreeAction($id) {
        $manager = $this->get('doctrine_phpcr.odm.default_document_manager');
        $entity = $manager->getRepository('ApplicationToguApplicationModelsBundle:Section')->find($id);
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
