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
class CRM_Financial_Form_Payment extends CRM_Core_Form {

  /**
   * @var string
   */
  protected $currency;

  public $_values = [];

  /**
   * @var int
   * Billing type ID
   */
  protected $_bltID;

  /**
   * @var array
   */
  public $_paymentFields;

  /**
   * @var array
   */
  public $_paymentProcessor;

  /**
   * @var bool
   */
  public $isBackOffice = FALSE;

  /**
   * @var string
   */
  public $_formName = '';

  /**
   * @var int|null
   */
  public ?int $paymentInstrumentID;

  /**
   * Set variables up before form is built.
   *
   * @throws \Exception
   */
  public function preProcess(): void {
    parent::preProcess();

    $this->_formName = CRM_Utils_Request::retrieve('formName', 'String', $this);

    $this->_values['custom_pre_id'] = CRM_Utils_Request::retrieve('pre_profile_id', 'Integer', $this);
    // These properties are set because it is how CRM_Core_Payment_ProcessorForm::preProcess
    // accesses them. Passing them in as properties might be more transparent.
    $this->_paymentProcessorID = CRM_Utils_Request::retrieve('processor_id', 'Integer', CRM_Core_DAO::$_nullObject,
      TRUE);
    $this->currency = CRM_Utils_Request::retrieve('currency', 'String', CRM_Core_DAO::$_nullObject,
      TRUE);
    $this->paymentInstrumentID = CRM_Utils_Request::retrieve('payment_instrument_id', 'Integer') ? (int) CRM_Utils_Request::retrieve('payment_instrument_id', 'Integer') : NULL;
    $this->isBackOffice = CRM_Utils_Request::retrieve('is_back_office', 'Integer');

    $this->assignBillingType();

    $this->_paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($this->_paymentProcessorID);

    CRM_Core_Payment_ProcessorForm::preProcess($this);

    $this->assign('suppressForm', TRUE);
    $this->controller->_generateQFKey = FALSE;
  }

  /**
   * Get currency
   *
   * @return string
   */
  public function getCurrency(): string {
    return (string) $this->currency;
  }

  /**
   * Build quickForm.
   */
  public function buildQuickForm() {
    CRM_Core_Payment_ProcessorForm::buildQuickForm($this);
  }

  /**
   * Set default values for the form.
   */
  public function setDefaultValues(): array {
    $contactID = $this->getContactID();
    CRM_Core_Payment_Form::setDefaultValues($this, $contactID);
    return $this->_defaults;
  }

  /**
   * Add JS to show icons for the accepted credit cards.
   *
   * @param int $paymentProcessorID
   * @param string $region
   */
  public static function addCreditCardJs($paymentProcessorID = NULL, $region = 'billing-block'): void {
    $creditCards = CRM_Financial_BAO_PaymentProcessor::getCreditCards($paymentProcessorID);
    if (empty($creditCards)) {
      $creditCards = CRM_Contribute_PseudoConstant::creditCard();
    }
    $creditCardTypes = [];
    foreach ($creditCards as $name => $label) {
      $creditCardTypes[$name] = [
        'label' => $label,
        'name' => $name,
        'css_key' => self::getCssLabelFriendlyName($name),
        'pattern' => self::getCardPattern($name),
      ];
    }

    CRM_Core_Resources::singleton()
      // CRM-20516: add BillingBlock script on billing-block region
      //  to support this feature in payment form snippet too.
      ->addScriptFile('civicrm', 'templates/CRM/Core/BillingBlock.js', 10, $region, FALSE)
      // workaround for CRM-13634
      // ->addSetting(array('config' => array('creditCardTypes' => $creditCardTypes)));
      ->addScript('CRM.config.creditCardTypes = ' . json_encode($creditCardTypes) . ';', '-9999', $region);
  }

  /**
   * Get css friendly labels for credit cards.
   *
   * We add the icons based on these css names which are lower cased
   * and only AlphaNumeric (+ _).
   *
   * @param string $key
   *
   * @return string
   */
  protected static function getCssLabelFriendlyName($key) {
    $key = str_replace(' ', '', $key);
    $key = preg_replace('/[^a-zA-Z0-9]/', '_', $key);
    $key = strtolower($key);

    return $key;
  }

  /**
   * Get the pattern that can be used to determine the card type.
   *
   * We do a strotolower comparison as we don't know what case people might have if they
   * are using a non-std one like dinersclub.
   *
   * @param string $key
   *
   * Based on http://davidwalsh.name/validate-credit-cards
   * See also https://en.wikipedia.org/wiki/Credit_card_numbers
   *
   * @return string
   */
  protected static function getCardPattern($key) {
    $cardMappings = [
      'mastercard' => '(5[1-5][0-9]{2}|2[3-6][0-9]{2}|22[3-9][0-9]|222[1-9]|27[0-1][0-9]|2720)[0-9]{12}',
      'visa' => '4(?:[0-9]{12}|[0-9]{15})',
      'amex' => '3[47][0-9]{13}',
      'dinersclub' => '3(?:0[0-5][0-9]{11}|[68][0-9]{12})',
      'carteblanche' => '3(?:0[0-5][0-9]{11}|[68][0-9]{12})',
      'discover' => '6011[0-9]{12}',
      'jcb' => '(?:3[0-9]{15}|(2131|1800)[0-9]{11})',
      'unionpay' => '62(?:[0-9]{14}|[0-9]{17})',
    ];
    return isset($cardMappings[strtolower($key)]) ? $cardMappings[strtolower($key)] : '';
  }

}
