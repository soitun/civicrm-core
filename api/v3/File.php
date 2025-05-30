<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * This api is a simple wrapper of the CiviCRM file DAO.
 *
 * Creating and updating files is a complex process and this api is usually insufficient.
 * Use the "Attachment" api instead for more robust file handling.
 *
 * @fixme no unit tests
 * @package CiviCRM_APIv3
 */

/**
 * Create a file record.
 * @note This is only one of several steps needed to create a file in CiviCRM.
 * Use the "Attachment" api to better handle all steps.
 *
 * @param array $params
 *   Array per getfields metadata.
 *
 * @return array
 */
function civicrm_api3_file_create($params) {

  civicrm_api3_verify_mandatory($params, 'CRM_Core_DAO_File', ['uri']);

  if (!isset($params['upload_date'])) {
    $params['upload_date'] = date("Ymd");
  }

  $fileDAO = new CRM_Core_DAO_File();
  $properties = [
    'id',
    'file_type_id',
    'mime_type',
    'uri',
    'document',
    'description',
    'upload_date',
  ];

  foreach ($properties as $name) {
    if (array_key_exists($name, $params)) {
      $fileDAO->$name = $params[$name];
    }
  }

  $fileDAO->save();

  $file = [];
  _civicrm_api3_object_to_array($fileDAO, $file);

  return civicrm_api3_create_success($file, $params, 'File', 'create', $fileDAO);
}

/**
 * Get a File.
 *
 * @param array $params
 *   Array per getfields metadata.
 *
 * @return array
 *   Array of all found file object property values.
 */
function civicrm_api3_file_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * Update an existing File.
 *
 * @param array $params
 *   Array per getfields metadata.
 *
 * @return array
 */
function civicrm_api3_file_update($params) {

  if (!isset($params['id'])) {
    return civicrm_api3_create_error('Required parameter missing');
  }

  $fileDAO = new CRM_Core_DAO_File();
  $fileDAO->id = $params['id'];
  if ($fileDAO->find(TRUE)) {
    $fileDAO->copyValues($params);
    if (!$params['upload_date'] && !$fileDAO->upload_date) {
      $fileDAO->upload_date = date("Ymd");
    }
    $fileDAO->save();
  }
  $file = [];
  $cloneDAO = clone($fileDAO);
  _civicrm_api3_object_to_array($cloneDAO, $file);
  return $file;
}

/**
 * Delete an existing File.
 *
 * @param array $params
 *   Array per getfields metadata.
 * @return array API Result Array
 * @throws CRM_Core_Exception
 */
function civicrm_api3_file_delete($params) {

  civicrm_api3_verify_mandatory($params, NULL, ['id']);
  $uri = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_File', $params['id'], 'uri');
  $path = \CRM_Core_Config::singleton()->customFileUploadDir . $uri;
  if (CRM_Core_BAO_File::deleteEntityFile('*', $params['id'])) {
    return civicrm_api3_create_success();
  }
  // Not all files are attachments
  elseif (file_exists($path) && unlink($path)) {
    CRM_Core_BAO_File::deleteRecord(['id' => $params['id']]);
    return civicrm_api3_create_success();
  }
  else {
    throw new CRM_Core_Exception('Error while deleting a file.');
  }
}
