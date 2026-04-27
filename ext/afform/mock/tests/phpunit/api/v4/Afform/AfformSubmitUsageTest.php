<?php
namespace Civi\ext\afform\mock\tests\phpunit\api\v4\Afform;

use api\v4\Afform\AfformUsageTestCase;
use Civi\Api4\Afform;
use Civi\Api4\AfformSubmission;

/**
 * Test case for Afform with autocomplete.
 *
 * @group headless
 */
class AfformSubmitUsageTest extends AfformUsageTestCase {

  public function testSubmitWithDisplayOnlyFields(): void {

    $layout = <<<EOHTML
<af-form ctrl="afform">
  <af-entity data="{contact_type: 'Individual'}" type="Contact" name="Individual1" label="Individual 1" actions="{create: true, update: true}" url-autofill="1" security="RBAC"  />
  <fieldset af-fieldset="Individual1" class="af-container" af-title="Individual 1">
    <af-field name="first_name" defn="{input_type: 'DisplayOnly', required: true}" />
    <af-field name="last_name" />
  </fieldset>
  <button class="af-button btn btn-primary" crm-icon="fa-check" ng-click="afform.submit()">Submit</button>
  <af-field defn="{input_type: 'Text', name: 'test_field'}" />
</af-form>
EOHTML;

    $this->useValues([
      'layout' => $layout,
      'permission' => \CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION,
    ]);

    $cid = $this->saveTestRecords('Individual', [
      'records' => [
        ['first_name' => 'One', 'last_name' => 'Person'],
      ],
    ])->column('id');

    $prefill = Afform::prefill()
      ->setName($this->formName)
      ->setFillMode('form')
      ->setArgs(['Individual1' => $cid])
      ->execute()
      ->indexBy('name');
    $this->assertCount(1, $prefill['Individual1']['values']);
    $this->assertEquals('One', $prefill['Individual1']['values'][0]['fields']['first_name']);
    $this->assertEquals('Person', $prefill['Individual1']['values'][0]['fields']['last_name']);

    // Submit with empty first_name: should not hit a validation error because DisplayOnly fields cannot be required
    $submission = [
      ['fields' => ['last_name' => 'Person']],
    ];
    $result = Afform::submit()
      ->setName($this->formName)
      ->setValues(['Individual1' => $submission])
      ->setArgs(['Individual1' => $cid])
      ->execute();
    $this->assertSame($cid[0], $result[0]['Individual1'][0]['id']);
  }

  public function testSubmitWithTokensInConfirmationMessage(): void {
    $layout = <<<EOHTML
<af-form ctrl="afform">
  <af-entity data="{source: 'Hello'}" type="Individual" name="Individual1" label="Individual 1" actions="{create: true, update: true}" security="RBAC"  />
  <fieldset af-fieldset="Individual1" class="af-container" af-title="Individual 1">
    <af-field name="first_name" />
    <af-field name="last_name" />
  </fieldset>
  <button class="af-button btn btn-primary" crm-icon="fa-check" ng-click="afform.submit()">Submit</button>
</af-form>
EOHTML;

    $this->useValues([
      'layout' => $layout,
      'permission' => \CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION,
      'confirmation_type' => 'show_confirmation_message',
      'confirmation_message' => 'Thank you "[Individual1.0.first_name] [Individual1.0.last_name]" You are now registered as [Individual1.0.source] ID_[Individual1.0.id].',
    ]);

    // Submit with new contact
    $submission = [
      ['fields' => ['first_name' => 'Jane', 'last_name' => 'Doe']],
    ];
    $result = Afform::submit()
      ->setName($this->formName)
      ->setValues(['Individual1' => $submission])
      ->execute();

    $contactId = $result[0]['Individual1'][0]['id'];
    $expectedMessage = "Thank you \"Jane Doe\" You are now registered as Hello ID_$contactId.";
    $this->assertSame($expectedMessage, $result[0]['message']);
  }

  public function testSubmitWithExtraFields(): void {
    $layout = <<<EOHTML
<af-form ctrl="afform">
  <af-entity data="{source: 'Hello'}" type="Individual" name="Individual1" label="Individual 1" actions="{create: true, update: true}" security="RBAC"  />
  <fieldset af-fieldset="Individual1" class="af-container" af-title="Individual 1">
    <af-field name="first_name" />
    <af-field name="last_name" />
    <af-field defn="{name: 'extra_field_1', input_type: 'Text'}" />
  </fieldset>
  <button class="af-button btn btn-primary" crm-icon="fa-check" ng-click="afform.submit()">Submit</button>
  <af-field defn="{name: 'extra_field_2', input_type: 'Text'}" />
</af-form>
EOHTML;

    $this->useValues([
      'layout' => $layout,
      'permission' => \CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION,
      'create_submission' => TRUE,
    ]);

    $submission = [
      'Individual1' => [
        'fields' => [
          'first_name' => 'Jane',
          'last_name' => 'Doe',
        ],
      ],
      'extra' => [
        'fields' => [
          'extra_field_1' => 'Extra 1',
          'extra_field_2' => 'Extra 2',
        ],
      ],
    ];
    $result = Afform::submit()
      ->setName($this->formName)
      ->setValues($submission)
      ->execute();

    $submission = AfformSubmission::get(FALSE)
      ->addOrderBy('id', 'DESC')
      ->setLimit(1)
      ->execute()->single()['data'];

    $this->assertEquals('Extra 1', $submission['extra']['fields']['extra_field_1']);
    $this->assertEquals('Extra 2', $submission['extra']['fields']['extra_field_2']);
    $this->assertEquals('Jane', $submission['Individual1']['fields']['first_name']);
    $this->assertEquals('Doe', $submission['Individual1']['fields']['last_name']);
  }

}
