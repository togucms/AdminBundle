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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\JsonResponse;


class AdminInterfaceController extends Controller {

	public function indexAction() {
		$rootDir = $this->container->get('kernel')->getRootDir();
		$locator = new FileLocator($rootDir.'/../web/admin/interface');
		if ($this->container->has('profiler')) {
			$this->container->get('profiler')->disable();
		}

		return new Response(file_get_contents($locator->locate('index.html')));
	}

	public function applicationsAction() {
		$rootDir = $this->container->get('kernel')->getRootDir();
		$locator = new FileLocator($rootDir.'/togu');
		$value = Yaml::parse(file_get_contents($locator->locate('applications.yml')));

		$response = new JsonResponse();
		$response->setData($value);

		return $response;
	}

	public function modelsAction() {
		$modelLoader = $this->container->get('togu.generator.model.config');

		$retValue = array();
		foreach ($modelLoader->getModels() as $modelName) {
			$model = $modelLoader->getConfig($modelName);

			$modelConfig = array(
				"id" => $modelName,
				"model" => $modelLoader->getExtJSClassName($modelName),
				"label" => isset($model['label']) ? $model['label'] : "",
				"description" => isset($model['description']) ? $model['description'] : "",
				"section" => isset($model['section']) ? $model['section'] : null,
				"hiddenInGrid" => isset($model['hiddenInGrid']) && $model['hiddenInGrid'] === true,
				"fields" => $this->getFields($model)
			);
			if(isset($model['modelTree'])) {
				$modelConfig['modelTree'] = $model['modelTree'];
			}

			if($modelName == "rootModel") {
				$modelConfig['fields'][] = array(
					"id" => "page",
					"model" => array("type"=> "auto"),
					"form" => array("xtype" => "fields_editor_page")
				);
			}

			$retValue[] = $modelConfig;
		}

		$retValue[] = array(
			"id" => "page",
			"model" => $modelLoader->getExtJSClassName('page'),
			"fields" => array(),
			"hiddenInGrid" => true
		);

		$response = new JsonResponse();
		$response->setData(array(
			'success' => true,
			'models' => $retValue
		));

		return $response;
	}

	protected function getFields($model) {
		$fields = array();
		$allFields = array();
		$this->getAllFields($model, $allFields);

		if(isset($model['section']) && $model['section']['leaf'] === false) {
			$allFields['nextSection'] = array(
				"model" => array(
					"type" => "reference",
					"persist" => false,
					"defaultValue" => array()
				)
			);
		}

		foreach ($allFields as $fieldName => $field) {
			$fieldConfig = array(
					"id" => $fieldName,
					"model" => $field['model'],
					"label" => isset($field['label']) ? $field['label'] : null,
					"addMenu" => isset($field['addMenu']) ? $field['addMenu'] : null,
					"form" => isset($field['form']) ? $field['form'] : null,
					"gridColumn" => isset($field['gridColumn']) ? $field['gridColumn'] : null,
			);
			$fields[] = $fieldConfig;
		}
		return $fields;
	}

	protected function getAllFields($model, &$fields) {
		if(isset($model['extends'])) {
			$modelLoader = $this->container->get('togu.generator.model.config');
			$this->getAllFields($modelLoader->getConfig($model['extends']), $fields);
		}

		if(! isset($model['fields'])) {
			return;
		}

		foreach ($model['fields'] as $fieldName => $field) {
			$fields[$fieldName] = $field;
		}
	}

}
