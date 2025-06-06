<?php
// ADAPTED FROM tools/scripts/phpunit

ini_set('include_path', dirname(__DIR__) . PATH_SEPARATOR . ini_get('include_path'));

#  Relying on system timezone setting produces a warning,
#  doing the following prevents the warning message
if (file_exists('/etc/timezone')) {
  $timezone = trim(file_get_contents('/etc/timezone'));
  if (ini_set('date.timezone', $timezone) === FALSE) {
    echo "ini_set( 'date.timezone', '$timezone' ) failed\n";
  }
}

$GLOBALS['CIVICRM_FORCE_MODULES'][] = 'civitest';

function civitest_civicrm_scanClasses(array &$classes): void {
  $phpunit = \Civi::paths()->getPath('[civicrm.root]/tests/phpunit');
  if (str_contains(get_include_path(), $phpunit)) {
    \Civi\Core\ClassScanner::scanFolders($classes, $phpunit, 'CRM/*/WorkflowMessage', '_', '/Test$/');
    \Civi\Core\ClassScanner::scanFolders($classes, $phpunit, 'Civi/*/WorkflowMessage', '\\', '/Test$/');
    // Exclude all `*Test.php` files - if we load them, then phpunit gets confused.
  }
}

# Crank up the memory
ini_set('memory_limit', '2G');
define('CIVICRM_TEST', 1);
if (getenv('CIVICRM_UPGRADE_EVIL')) {
  define('CIVICRM_BOOTSTRAP_FORBIDDEN', TRUE);
}
// phpcs:disable
eval(cv('php:boot --level=settings', 'phpcode'));
// phpcs:enable

spl_autoload_register(function($class) {
  _phpunit_mockoloader('api\\v4\\', "tests/phpunit/api/v4/", $class);
  _phpunit_mockoloader('Civi\\Api4\\', "tests/phpunit/api/v4/Mock/Api4/", $class);
  if (substr($class, 0, 13) === 'CRM_Fake_DAO_') {
    // phpcs:disable
    eval('namespace { class ' . $class . ' extends \CRM_Core_DAO { public static function &fields() { $r = []; return $r; }}}');
    // phpcs:enable
  }
});

// ------------------------------------------------------------------------------

/**
 * @param $prefix
 * @param $base_dir
 * @param $class
 */
function _phpunit_mockoloader($prefix, $base_dir, $class) {
  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) {
    return;
  }

  global $civicrm_root;
  $relative_class = substr($class, $len);
  $file = $civicrm_root . '/' . $base_dir . str_replace('\\', '/', $relative_class) . '.php';
  if (file_exists($file)) {
    require $file;
  }
}

/**
 * Call the "cv" command.
 *
 * @param string $cmd
 *   The rest of the command to send.
 * @param string $decode
 *   Ex: 'json' or 'phpcode'.
 * @return string
 *   Response output (if the command executed normally).
 * @throws \RuntimeException
 *   If the command terminates abnormally.
 */
function cv($cmd, $decode = 'json') {
  // If xdebug is active when launching phpunit, we usually want to focus on phpunit.
  if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    $cmd = 'env XDEBUG_MODE=off XDEBUG_PORT= cv ' . $cmd;
  }
  else {
    $cmd = 'cv ' . $cmd;
  }
  $descriptorSpec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => STDERR];
  $oldOutput = getenv('CV_OUTPUT');
  putenv("CV_OUTPUT=json");
  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  putenv($oldOutput === FALSE ? "CV_OUTPUT" : "CV_OUTPUT=$oldOutput");
  fclose($pipes[0]);
  $result = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException("Command failed ($cmd):\n$result");
  }
  switch ($decode) {
    case 'raw':
      return $result;

    case 'phpcode':
      // If the last output is /*PHPCODE*/, then we managed to complete execution.
      if (substr(trim($result), 0, 12) !== "/*BEGINPHP*/" || substr(trim($result), -10) !== "/*ENDPHP*/") {
        throw new \RuntimeException("Command failed ($cmd):\n$result");
      }
      return $result;

    case 'json':
      return json_decode($result, 1);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}
