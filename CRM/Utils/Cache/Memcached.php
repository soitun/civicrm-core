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
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Utils_Cache_Memcached implements CRM_Utils_Cache_Interface {

  // TODO Consider native implementation.
  use CRM_Utils_Cache_NaiveMultipleTrait;

  const DEFAULT_HOST = 'localhost';
  const DEFAULT_PORT = 11211;
  const DEFAULT_TIMEOUT = 3600;
  const DEFAULT_PREFIX = '';

  /**
   * This is an aggregate limit, including all prefixes and key items.
   */
  const MAX_KEY_LEN = 200;

  /**
   * If another process clears namespace, we'll find out in ~5 sec.
   */
  const NS_LOCAL_TTL = 5;

  /**
   * The host name of the memcached server
   *
   * @var string
   */
  protected $_host = self::DEFAULT_HOST;

  /**
   * The port on which to connect on
   *
   * @var int
   */
  protected $_port = self::DEFAULT_PORT;

  /**
   * The default timeout to use
   *
   * @var int
   */
  protected $_timeout = self::DEFAULT_TIMEOUT;

  /**
   * The prefix prepended to cache keys.
   *
   * If we are using the same memcache instance for multiple CiviCRM
   * installs, we must have a unique prefix for each install to prevent
   * the keys from clobbering each other.
   *
   * @var string
   */
  protected $_prefix = self::DEFAULT_PREFIX;

  /**
   * The actual memcache object.
   *
   * @var Memcached
   */
  protected $_cache;

  /**
   * @var null|array
   *
   * This is the effective prefix. It may be bumped up whenever the dataset is flushed.
   *
   * @see https://github.com/memcached/memcached/wiki/ProgrammingTricks#deleting-by-namespace
   */
  protected $_truePrefix = NULL;

  /**
   * Constructor.
   *
   * @param array $config
   *   An array of configuration params.
   *
   * @return \CRM_Utils_Cache_Memcached
   */
  public function __construct($config) {
    if (isset($config['host'])) {
      $this->_host = $config['host'];
    }
    if (isset($config['port'])) {
      $this->_port = $config['port'];
    }
    if (isset($config['timeout'])) {
      $this->_timeout = $config['timeout'];
    }
    if (isset($config['prefix'])) {
      $this->_prefix = $config['prefix'];
    }

    $this->_cache = new Memcached();

    if (!$this->_cache->addServer($this->_host, $this->_port)) {
      // dont use fatal here since we can go in an infinite loop
      echo 'Could not connect to Memcached server';
      CRM_Utils_System::civiExit();
    }
  }

  /**
   * @param $key
   * @param $value
   * @param null|int|\DateInterval $ttl
   *
   * @return bool
   * @throws Exception
   */
  public function set($key, $value, $ttl = NULL) {
    CRM_Utils_Cache::assertValidKey($key);
    if (is_int($ttl) && $ttl <= 0) {
      return $this->delete($key);
    }
    $expires = CRM_Utils_Date::convertCacheTtlToExpires($ttl, $this->_timeout);

    $key = $this->cleanKey($key);
    if (!$this->_cache->set($key, serialize($value), $expires)) {
      if (PHP_SAPI === 'cli' || (Civi\Core\Container::isContainerBooted() && CRM_Core_Permission::check('view debug output'))) {
        throw new CRM_Utils_Cache_CacheException("Memcached::set($key) failed: " . $this->_cache->getResultMessage());
      }
      else {
        Civi::log()->error("Memcached::set($key) failed: " . $this->_cache->getResultMessage());
        throw new CRM_Utils_Cache_CacheException("Memcached::set($key) failed");
      }
      return FALSE;

    }
    return TRUE;
  }

  /**
   * @param $key
   * @param mixed $default
   *
   * @return mixed
   */
  public function get($key, $default = NULL) {
    CRM_Utils_Cache::assertValidKey($key);
    $key = $this->cleanKey($key);
    $result = $this->_cache->get($key);
    switch ($this->_cache->getResultCode()) {
      case Memcached::RES_SUCCESS:
        return unserialize($result);

      case Memcached::RES_NOTFOUND:
        return $default;

      default:
        Civi::log()->error("Memcached::get($key) failed: " . $this->_cache->getResultMessage());
        throw new CRM_Utils_Cache_CacheException("Memcached set ($key) failed");
    }
  }

  /**
   * @param string $key
   *
   * @return bool
   * @throws \Psr\SimpleCache\CacheException
   */
  public function has($key) {
    CRM_Utils_Cache::assertValidKey($key);
    $key = $this->cleanKey($key);
    if ($this->_cache->get($key) !== FALSE) {
      return TRUE;
    }
    switch ($this->_cache->getResultCode()) {
      case Memcached::RES_NOTFOUND:
        return FALSE;

      case Memcached::RES_SUCCESS:
        return TRUE;

      default:
        Civi::log()->error("Memcached::has($key) failed: " . $this->_cache->getResultMessage());
        throw new CRM_Utils_Cache_CacheException("Memcached set ($key) failed");
    }
  }

  /**
   * @param $key
   *
   * @return mixed
   */
  public function delete($key) {
    CRM_Utils_Cache::assertValidKey($key);
    $key = $this->cleanKey($key);
    if ($this->_cache->delete($key)) {
      return TRUE;
    }
    $code = $this->_cache->getResultCode();
    return ($code == Memcached::RES_DELETED || $code == Memcached::RES_NOTFOUND);
  }

  /**
   * @param $key
   *
   * @return mixed|string
   */
  public function cleanKey($key) {
    $truePrefix = $this->getTruePrefix();
    $maxLen = self::MAX_KEY_LEN - strlen($truePrefix);
    $key = preg_replace('/\s+|\W+/', '_', $key);
    if (strlen($key) > $maxLen) {
      // In memcache, the total path length is limited, and keys are case-sensitive. Base64 seems good.
      $digest = base64_encode(hash('sha256', $key, TRUE));
      $subKeyLen = $maxLen - 1 - strlen($digest);
      $key = substr($key, 0, $subKeyLen) . "_" . $digest;
    }
    return $truePrefix . $key;
  }

  /**
   * @return bool
   */
  public function flush() {
    $this->_truePrefix = NULL;
    if ($this->_cache->delete($this->_prefix)) {
      return TRUE;
    }
    $code = $this->_cache->getResultCode();
    return ($code == Memcached::RES_DELETED || $code == Memcached::RES_NOTFOUND);
  }

  public function clear() {
    return $this->flush();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    return FALSE;
  }

  protected function getTruePrefix() {
    if ($this->_truePrefix === NULL || $this->_truePrefix['expires'] < time()) {
      $key = $this->_prefix;
      $value = $this->_cache->get($key);
      if ($this->_cache->getResultCode() === Memcached::RES_NOTFOUND) {
        $value = uniqid();
        // Indefinite.
        $this->_cache->add($key, $value, 0);
      }
      $this->_truePrefix = [
        'value' => $value,
        'expires' => time() + self::NS_LOCAL_TTL,
      ];
    }
    return $this->_prefix . $this->_truePrefix['value'] . '/';
  }

}
