<?php
namespace Civi\ext\afform\mock\tests\phpunit\api\v4\Afform;

use api\v4\Afform\AfformUsageTestCase;
use Civi\Api4\Afform;

/**
 * Test case for Afform with validation.
 *
 * @group headless
 */
class AfformValidateUsageTest extends AfformUsageTestCase {

  public function testSubmitWithRequiredOnlyFields(): void {
    $layout = <<<EOHTML
<af-form ctrl="afform">
  <af-entity data="{contact_type: 'Individual'}" type="Contact" name="Individual1" label="Individual 1" actions="{create: true, update: true}" url-autofill="1" security="RBAC"  />
  <fieldset af-fieldset="Individual1" class="af-container" af-title="Individual 1">
    <af-field name="first_name" defn="{required: true}" />
    <af-field name="last_name" defn="{required: true}" />
    <af-field name="middle_name" />
  </fieldset>
  <button class="af-button btn btn-primary" crm-icon="fa-check" ng-click="afform.submit()">Submit</button>
</af-form>
EOHTML;

    $this->useValues([
      'layout' => $layout,
      'permission' => \CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION,
    ]);

    // Submit with empty first and last names. Should get 2 validation errors.
    $submission = [
      ['fields' => ['middle_name' => 'Person']],
    ];
    try {
      Afform::submit()
        ->setName($this->formName)
        ->setValues(['Individual1' => $submission])
        ->execute();
      $this->fail('Should have thrown exception');
    }
    catch (\CRM_Core_Exception $e) {
      $msg = $e->getMessage();
      $this->assertStringContainsString('First Name is a required field', $msg);
      $this->assertStringContainsString('Last Name is a required field', $msg);
    }
  }

  public function testSubmitWithMaxLengthValidation(): void {
    $layout = <<<EOHTML
<af-form ctrl="afform">
  <af-entity data="{contact_type: 'Individual'}" type="Contact" name="Individual1" label="Individual 1" actions="{create: true, update: true}" url-autofill="1" security="RBAC"  />
  <fieldset af-fieldset="Individual1" class="af-container" af-title="Individual 1">
    <af-field name="first_name" defn="{input_attrs: {maxlength: 5}}" />
  </fieldset>
  <button class="af-button btn btn-primary" crm-icon="fa-check" ng-click="afform.submit()">Submit</button>
</af-form>
EOHTML;

    $this->useValues([
      'layout' => $layout,
      'permission' => \CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION,
    ]);

    // Submit with first name exceeding maxlength. Should get validation error.
    $submission = [
      ['fields' => ['first_name' => 'TooLongName']],
    ];
    try {
      Afform::submit()
        ->setName($this->formName)
        ->setValues(['Individual1' => $submission])
        ->execute();
      $this->fail('Should have thrown exception');
    }
    catch (\CRM_Core_Exception $e) {
      $msg = $e->getMessage();
      $this->assertStringContainsString('First Name', $msg);
      $this->assertStringContainsString('length of 5', $msg);
    }

    Afform::submit()
      ->setName($this->formName)
      ->setValues(['Individual1' => [['fields' => ['first_name' => 'Short']]]])
      ->execute();
  }

}
