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
  * Test SMS provider to allow for testing
  */
class CiviTestSMSProvider extends CRM_SMS_Provider {
  protected $sentMessage;
  protected $_id = 0;
  static private $_singleton = [];
  protected $provider;

  public function __construct($provider, $skipAuth = TRUE) {
    $this->provider = $provider;
  }

  public static function &singleton($providerParams = [], $force = FALSE) {
    if (isset($providerParams['provider'])) {
      $providers = CRM_SMS_BAO_SmsProvider::getProviders(NULL, ['name' => $providerParams['provider']]);
      $provider = current($providers);
      $providerID = $provider['id'] ?? NULL;
    }
    else {
      $providerID = $providerParams['provider_id'] ?? NULL;
    }
    $skipAuth   = $providerID ? FALSE : TRUE;
    $cacheKey   = (int) $providerID;

    if (!isset(self::$_singleton[$cacheKey]) || $force) {
      $provider = [];
      if ($providerID) {
        $provider = CRM_SMS_BAO_SmsProvider::getProviderInfo($providerID);
      }
      self::$_singleton[$cacheKey] = new CiviTestSMSProvider($provider, $skipAuth);
    }
    return self::$_singleton[$cacheKey];
  }

  public function send($recipients, $header, $message, $jobID = NULL, $userID = NULL) {
    $this->sentMessage = $message;
    $sid = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 16);
    $this->createActivity($sid, $message, $header, $jobID);
  }

  /**
   * Get the message that was sent.
   *
   * @return string
   */
  public function getSentMessage(): string {
    return $this->sentMessage;
  }

}
