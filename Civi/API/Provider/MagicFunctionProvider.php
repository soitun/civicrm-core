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

namespace Civi\API\Provider;

use Civi\API\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * This class manages the loading of API's using strict file+function naming
 * conventions.
 */
class MagicFunctionProvider implements EventSubscriberInterface, ProviderInterface {

  /**
   * @return array
   */
  public static function getSubscribedEvents() {
    return [
      'civi.api.resolve' => [
        ['onApiResolve', Events::W_MIDDLE],
      ],
    ];
  }

  /**
   * Local cache of function-mappings.
   *
   * array(string $cacheKey => array('function' => string, 'is_generic' => bool))
   *
   * @var array
   */
  private $cache;

  /**
   */
  public function __construct() {
    $this->cache = [];
  }

  /**
   * @param \Civi\API\Event\ResolveEvent $event
   *   API resolution event.
   */
  public function onApiResolve(\Civi\API\Event\ResolveEvent $event) {
    $apiRequest = $event->getApiRequest();
    $resolved = $this->resolve($apiRequest);
    if ($resolved['function']) {
      $apiRequest += $resolved;
      $event->setApiRequest($apiRequest);
      $event->setApiProvider($this);
      $event->stopPropagation();
    }
  }

  /**
   * @inheritDoc
   * @param array $apiRequest
   * @return array
   */
  public function invoke($apiRequest) {
    $function = $apiRequest['function'];
    if ($apiRequest['function'] && $apiRequest['is_generic']) {
      // Unlike normal API implementations, generic implementations require explicit
      // knowledge of the entity and action (as well as $params). Bundle up these bits
      // into a convenient data structure.
      if ($apiRequest['action'] === 'getsingle') {
        // strip any api nested parts here as otherwise chaining may happen twice
        // see https://lab.civicrm.org/dev/core/issues/643
        // testCreateBAODefaults fails without this.
        foreach ($apiRequest['params'] as $key => $param) {
          if ($key !== 'api.has_parent' && substr($key, 0, 4) === 'api.' || substr($key, 0, 4) === 'api_') {
            unset($apiRequest['params'][$key]);
          }
        }
      }
      $result = $function($apiRequest);

    }
    elseif ($apiRequest['function'] && !$apiRequest['is_generic']) {
      $result = $function($apiRequest['params']);
    }
    return $result;
  }

  /**
   * @inheritDoc
   * @param int $version
   * @return array
   */
  public function getEntityNames($version) {
    $entities = [];
    $include_dirs = array_unique(explode(PATH_SEPARATOR, get_include_path()));
    foreach ($include_dirs as $include_dir) {
      $api_dir = implode(DIRECTORY_SEPARATOR,
        [$include_dir, 'api', 'v' . $version]);
      // While it seems pointless to have a folder that's outside open_basedir
      // listed in include_path and that seems more like a configuration issue,
      // not everyone has control over the hosting provider's include_path and
      // this does happen out in the wild, so use our wrapper to avoid flooding
      // logs.
      if (!\CRM_Utils_File::isDir($api_dir)) {
        continue;
      }
      $iterator = new \DirectoryIterator($api_dir);
      foreach ($iterator as $fileinfo) {
        $file = $fileinfo->getFilename();

        // Check for entities with a master file ("api/v3/MyEntity.php")
        $parts = explode(".", $file);
        if (end($parts) == "php" && $file != "utils.php" && !preg_match('/Tests?.php$/', $file)) {
          // without the ".php"
          $entities[] = substr($file, 0, -4);
        }

        // Check for entities with standalone action files (eg "api/v3/MyEntity/MyAction.php").
        $action_dir = $api_dir . DIRECTORY_SEPARATOR . $file;
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $file) && is_dir($action_dir)) {
          if (count(glob("$action_dir/[A-Z]*.php")) > 0) {
            $entities[] = $file;
          }
        }
      }
    }
    $entities = array_diff($entities, ['Generic']);
    $entities = array_unique($entities);
    sort($entities);

    return $entities;
  }

  /**
   * @inheritDoc
   * @param int $version
   * @param string $entity
   * @return array
   */
  public function getActionNames($version, $entity) {
    $entity = _civicrm_api_get_camel_name($entity);
    $entities = $this->getEntityNames($version);
    if (!in_array($entity, $entities)) {
      return [];
    }
    $this->loadEntity($entity, $version);

    $functions = get_defined_functions();
    $actions = [];
    $prefix = 'civicrm_api' . $version . '_' . _civicrm_api_get_entity_name_from_camel($entity) . '_';
    $prefixGeneric = 'civicrm_api' . $version . '_generic_';
    foreach ($functions['user'] as $fct) {
      if (str_starts_with($fct, $prefix)) {
        $actions[] = substr($fct, strlen($prefix));
      }
      elseif (str_starts_with($fct, $prefixGeneric)) {
        $actions[] = substr($fct, strlen($prefixGeneric));
      }
    }
    return $actions;
  }

  /**
   * Look up the implementation for a given API request.
   *
   * @param array $apiRequest
   *   Array with keys:
   *   - entity: string, required.
   *   - action: string, required.
   *   - params: array.
   *   - version: scalar, required.
   *
   * @return array
   *   Array with keys:
   *   - function: callback (mixed)
   *   - is_generic: boolean
   */
  protected function resolve($apiRequest) {
    $cachekey = strtolower($apiRequest['entity']) . ':' . strtolower($apiRequest['action']) . ':' . $apiRequest['version'];
    if (isset($this->cache[$cachekey])) {
      return $this->cache[$cachekey];
    }

    $camelName = _civicrm_api_get_camel_name($apiRequest['entity'], $apiRequest['version']);
    $actionCamelName = _civicrm_api_get_camel_name($apiRequest['action']);

    // Determine if there is an entity-specific implementation of the action
    $stdFunction = $this->getFunctionName($apiRequest['entity'], $apiRequest['action'], $apiRequest['version']);
    if (function_exists($stdFunction)) {
      // someone already loaded the appropriate file
      // FIXME: This has the affect of masking bugs in load order; this is
      // included to provide bug-compatibility.
      $this->cache[$cachekey] = ['function' => $stdFunction, 'is_generic' => FALSE];
      return $this->cache[$cachekey];
    }

    $stdFiles = [
      // By convention, the $camelName.php is more likely to contain the
      // function, so test it first
      'api/v' . $apiRequest['version'] . '/' . $camelName . '.php',
      'api/v' . $apiRequest['version'] . '/' . $camelName . '/' . $actionCamelName . '.php',
    ];
    foreach ($stdFiles as $stdFile) {
      if (\CRM_Utils_File::isIncludable($stdFile)) {
        require_once $stdFile;
        if (function_exists($stdFunction)) {
          $this->cache[$cachekey] = ['function' => $stdFunction, 'is_generic' => FALSE];
          return $this->cache[$cachekey];
        }
      }
    }

    // Determine if there is a generic implementation of the action
    require_once 'api/v3/Generic.php';
    # $genericFunction = 'civicrm_api3_generic_' . $apiRequest['action'];
    $genericFunction = $this->getFunctionName('generic', $apiRequest['action'], $apiRequest['version']);
    $genericFiles = [
      // By convention, the Generic.php is more likely to contain the
      // function, so test it first
      'api/v' . $apiRequest['version'] . '/Generic.php',
      'api/v' . $apiRequest['version'] . '/Generic/' . $actionCamelName . '.php',
    ];
    foreach ($genericFiles as $genericFile) {
      if (\CRM_Utils_File::isIncludable($genericFile)) {
        require_once $genericFile;
        if (function_exists($genericFunction)) {
          $this->cache[$cachekey] = ['function' => $genericFunction, 'is_generic' => TRUE];
          return $this->cache[$cachekey];
        }
      }
    }

    $this->cache[$cachekey] = ['function' => FALSE, 'is_generic' => FALSE];
    return $this->cache[$cachekey];
  }

  /**
   * Determine the function name for a given API request.
   *
   * @param string $entity
   *   API entity name.
   * @param string $action
   *   API action name.
   * @param int $version
   *   API version.
   *
   * @return string
   */
  protected function getFunctionName($entity, $action, $version) {
    $entity = _civicrm_api_get_entity_name_from_camel($entity);
    return 'civicrm_api' . $version . '_' . $entity . '_' . $action;
  }

  /**
   * Load/require all files related to an entity.
   *
   * This should not normally be called because it's does a file-system scan; it's
   * only appropriate when introspection is really required (eg for "getActions").
   *
   * @param string $entity
   *   API entity name.
   * @param int $version
   *   API version.
   */
  protected function loadEntity($entity, $version) {
    $camelName = _civicrm_api_get_camel_name($entity, $version);

    // Check for master entity file; to match _civicrm_api_resolve(), only load the first one
    $stdFile = 'api/v' . $version . '/' . $camelName . '.php';
    if (\CRM_Utils_File::isIncludable($stdFile)) {
      require_once $stdFile;
    }

    // Check for standalone action files; to match _civicrm_api_resolve(), only load the first one
    // array($relativeFilePath => TRUE)
    $loaded_files = [];
    $include_dirs = array_unique(explode(PATH_SEPARATOR, get_include_path()));
    foreach ($include_dirs as $include_dir) {
      foreach ([$camelName, 'Generic'] as $name) {
        $action_dir = implode(DIRECTORY_SEPARATOR,
          [$include_dir, 'api', "v{$version}", $name]);
        // see note above in getEntityNames about open_basedir
        if (!\CRM_Utils_File::isDir($action_dir)) {
          continue;
        }

        $iterator = new \DirectoryIterator($action_dir);
        foreach ($iterator as $fileinfo) {
          $file = $fileinfo->getFilename();
          if (array_key_exists($file, $loaded_files)) {
            // action provided by an earlier item on include_path
            continue;
          }

          $parts = explode(".", $file);
          if (end($parts) == "php" && !preg_match('/Tests?\.php$/', $file)) {
            require_once $action_dir . DIRECTORY_SEPARATOR . $file;
            $loaded_files[$file] = TRUE;
          }
        }
      }
    }
  }

}
