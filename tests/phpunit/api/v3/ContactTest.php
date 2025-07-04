<?php
/**
 * @file
 * File for the TestContact class.
 *
 *  (PHP 5)
 *
 * @author Walt Haas <walt@dharmatech.org> (801) 534-1262
 * @copyright Copyright CiviCRM LLC (C) 2009
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html
 *              GNU Affero General Public License version 3
 * @version   $Id: ContactTest.php 31254 2010-12-15 10:09:29Z eileen $
 * @package   CiviCRM
 *
 *   This file is part of CiviCRM
 *
 *   CiviCRM is free software; you can redistribute it and/or
 *   modify it under the terms of the GNU Affero General Public License
 *   as published by the Free Software Foundation; either version 3 of
 *   the License, or (at your option) any later version.
 *
 *   CiviCRM is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *   You should have received a copy of the GNU Affero General Public
 *   License along with this program.  If not, see
 *   <http://www.gnu.org/licenses/>.
 */

use Civi\Api4\Contact;

/**
 *  Test APIv3 civicrm_contact* functions
 *
 * @package CiviCRM_APIv3
 * @subpackage API_Contact
 * @group headless
 */
class api_v3_ContactTest extends CiviUnitTestCase {

  use CRMTraits_Custom_CustomDataTrait;

  protected $_entity;

  protected $_params;

  protected $_contactID;

  protected $_financialTypeId = 1;

  /**
   * Entity to be extended.
   *
   * @var string
   */
  protected string $entity = 'Contact';

  /**
   * Test setup for every test.
   *
   * Connect to the database, truncate the tables that will be used
   * and redirect stdin to a temporary file
   */
  public function setUp(): void {
    // Connect to the database.
    parent::setUp();
    $this->_entity = 'contact';
    $this->_params = [
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
    ];
  }

  /**
   * Restore the DB for the next test.
   *
   * @throws \Exception
   */
  public function tearDown(): void {
    $this->_apiversion = 3;
    $this->callAPISuccess('Setting', 'create', ['includeOrderByClause' => TRUE]);
    // truncate a few tables
    $tablesToTruncate = [
      'civicrm_email',
      'civicrm_website',
      'civicrm_relationship',
      'civicrm_uf_match',
      'civicrm_file',
      'civicrm_entity_file',
      'civicrm_phone',
      'civicrm_address',
      'civicrm_acl_contact_cache',
      'civicrm_group',
      'civicrm_group_contact',
      'civicrm_group_contact_cache',
      'civicrm_saved_search',
      'civicrm_prevnext_cache',
    ];
    $this->quickCleanUpFinancialEntities();
    $this->deleteNonDefaultRelationshipTypes();
    $this->restoreMembershipTypes();
    $this->quickCleanup($tablesToTruncate, TRUE);
    parent::tearDown();
  }

  /**
   * Test civicrm_contact_create.
   *
   * Verify that attempt to create individual contact with only
   * first and last names succeeds
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   *
   */
  public function testAddCreateIndividual(int $version): void {
    $this->_apiversion = $version;
    $oldCount = CRM_Core_DAO::singleValueQuery('select count(*) from civicrm_contact');
    $params = [
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);
    $this->assertIsNumeric($contact['id']);
    $this->assertTrue($contact['id'] > 0);
    $newCount = CRM_Core_DAO::singleValueQuery('select count(*) from civicrm_contact');
    $this->assertEquals($oldCount + 1, $newCount);

    $this->assertDBState('CRM_Contact_DAO_Contact',
      $contact['id'],
      $params
    );
  }

  /**
   * Test that it is possible to prevent cache clearing via option.
   *
   * Cache clearing is bypassed if 'options' => array('do_not_reset_cache' => 1 is used.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualNoCacheClear(): void {
    $contact = $this->callAPISuccess('contact', 'create', $this->_params);

    $smartGroupParams = ['form_values' => ['contact_type' => ['IN' => ['Household']]]];
    $savedSearch = CRM_Contact_BAO_SavedSearch::create($smartGroupParams);
    $groupID = $this->groupCreate(['saved_search_id' => $savedSearch->id]);

    $this->putGroupContactCacheInClearableState($groupID, $contact);

    $this->callAPISuccess('contact', 'create', ['id' => $contact['id']]);
    $this->assertEquals(0, CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM civicrm_group_contact_cache'));

    // Rinse & repeat, but with the option.
    $this->putGroupContactCacheInClearableState($groupID, $contact);
    CRM_Core_Config::setPermitCacheFlushMode(FALSE);
    $this->callAPISuccess('contact', 'create', ['id' => $contact['id']]);
    $this->assertEquals(1, CRM_Core_DAO::singleValueQuery('SELECT count(*) FROM civicrm_group_contact_cache'));
    CRM_Core_Config::setPermitCacheFlushMode(TRUE);
  }

  /**
   * Test for international string acceptance (CRM-10210).
   * Requires the database to be in utf8.
   *
   * @dataProvider getInternationalStrings
   *
   * @param string $string
   *   String to be tested.
   *
   *   Bool to see if we should check charset.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testInternationalStrings(string $string): void {
    $this->callAPISuccess('Contact', 'create', array_merge(
      $this->_params,
      ['first_name' => $string]
    ));

    $result = $this->callAPISuccessGetSingle('Contact', ['first_name' => $string, 'return' => 'first_name']);
    $this->assertEquals($string, $result['first_name']);

    $this->callAPISuccess('Contact', 'create', [
      'organization_name' => $string,
      'contact_type' => 'Organization',
    ]);
    $this->validateContactField('organization_name', $string, NULL, ['organization_name', '=', $string]);
  }

  /**
   * Get international string data for testing against api calls.
   */
  public static function getInternationalStrings(): array {
    $invocations = [];
    $invocations[] = ['Scarabée'];
    $invocations[] = ['Iñtërnâtiônàlizætiøn'];
    $invocations[] = ['これは日本語のテキストです。読めますか'];
    $invocations[] = ['देखें हिन्दी कैसी नजर आती है। अरे वाह ये तो नजर आती है।'];
    return $invocations;
  }

  /**
   * Test civicrm_contact_create.
   *
   * Verify that preferred language can be set.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testAddCreateIndividualWithPreferredLanguage(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
      'preferred_language' => 'es_ES',
    ];

    $contact = $this->callAPISuccess('Contact', 'create', $params);
    $this->getAndCheck($params, $contact['id'], 'Contact');
  }

  /**
   * Test civicrm_contact_create with sub-types.
   *
   * Verify that sub-types are created successfully and not deleted by
   * subsequent updates.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testIndividualSubType(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'first_name' => 'test abc',
      'contact_type' => 'Individual',
      'last_name' => 'test xyz',
      'contact_sub_type' => ['Student', 'Staff'],
    ];
    $contact = $this->callAPISuccess('contact', 'create', $params);
    $cid = $contact['id'];

    $params = [
      'id' => $cid,
      'middle_name' => 'foo',
    ];
    $this->callAPISuccess('contact', 'create', $params);

    $contact = $this->callAPISuccess('contact', 'get', ['id' => $cid]);

    $this->assertEquals(['Student', 'Staff'], $contact['values'][$cid]['contact_sub_type']);

    $this->callAPISuccess('Contact', 'create', [
      'id' => $cid,
      'contact_sub_type' => [],
    ]);

    $contact = $this->callAPISuccess('contact', 'get', ['id' => $cid]);
    $this->assertEmpty($contact['values'][$cid]['contact_sub_type']);
  }

  /**
   * Verify that we can retrieve contacts of different sub types
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testGetMultipleContactSubTypes(int $version): void {
    $this->_apiversion = $version;

    // This test presumes that there are no parents or students in the dataset

    // create a student
    $student = $this->callAPISuccess('contact', 'create', [
      'email' => 'student@example.com',
      'contact_type' => 'Individual',
      'contact_sub_type' => 'Student',
    ]);

    // create a parent
    $parent = $this->callAPISuccess('contact', 'create', [
      'email' => 'parent@example.com',
      'contact_type' => 'Individual',
      'contact_sub_type' => 'Parent',
    ]);

    // create a parent
    $this->callAPISuccess('contact', 'create', [
      'email' => 'parent@example.com',
      'contact_type' => 'Individual',
    ]);

    // get all students and parents
    $result = $this->callAPISuccess('Contact', 'get', ['return' => 'id', 'contact_sub_type' => ['IN' => ['Parent', 'Student']]])['values'];
    // check that we retrieved the student and the parent.
    // On MySQL 8 this can have different order in the array as there is no specific order set.
    $this->assertArrayHasKey($student['id'], $result);
    $this->assertArrayHasKey($parent['id'], $result);
    $this->assertCount(2, $result);
  }

  /**
   * Verify that attempt to create contact with empty params fails.
   */
  public function testCreateEmptyContact(): void {
    $this->callAPIFailure('contact', 'create', []);
  }

  /**
   * Verify that attempt to create contact with bad contact type fails.
   */
  public function testCreateBadTypeContact(): void {
    $params = [
      'email' => 'man1@yahoo.com',
      'contact_type' => 'Does not Exist',
    ];
    $this->callAPIFailure('contact', 'create', $params, "'Does not Exist' is not a valid option for field contact_type");
  }

  /**
   * Verify that attempt to create individual contact without required fields fails.
   */
  public function testCreateBadRequiredFieldsIndividual(): void {
    $params = [
      'middle_name' => 'This field is not required',
      'contact_type' => 'Individual',
    ];
    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Verify that attempt to create household contact without required fields fails.
   */
  public function testCreateBadRequiredFieldsHousehold(): void {
    $params = [
      'middle_name' => 'This field is not required',
      'contact_type' => 'Household',
    ];
    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Test required field check.
   *
   * Verify that attempt to create organization contact without required fields fails.
   */
  public function testCreateBadRequiredFieldsOrganization(): void {
    $params = [
      'middle_name' => 'This field is not required',
      'contact_type' => 'Organization',
    ];

    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Verify that attempt to create individual contact with only an email succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateEmailIndividual(): void {
    $primaryEmail = 'man3@yahoo.com';
    $notPrimaryEmail = 'man4@yahoo.com';
    $params = [
      'email' => $primaryEmail,
      'contact_type' => 'Individual',
      'location_type_id' => 1,
    ];

    $contact1 = $this->callAPISuccess('contact', 'create', $params);

    $this->assertEquals(3, $contact1['id']);
    $email1 = $this->callAPISuccess('email', 'get', ['contact_id' => $contact1['id']]);
    $this->assertEquals(1, $email1['count']);
    $this->assertEquals($primaryEmail, $email1['values'][$email1['id']]['email']);

    $this->callAPISuccess('email', 'create', ['contact_id' => $contact1['id'], 'is_primary' => 0, 'email' => $notPrimaryEmail]);

    // Case 1: Check with criteria primary 'email' => array('IS NOT NULL' => 1)
    $this->callAPISuccess('contact', 'get', ['email' => ['IS NOT NULL' => 1]]);
    $this->assertEquals($primaryEmail, $email1['values'][$email1['id']]['email']);

    // Case 2: Check with criteria primary 'email' => array('<>' => '')
    $this->callAPISuccess('contact', 'get', ['email' => ['<>' => '']]);
    $this->assertEquals($primaryEmail, $email1['values'][$email1['id']]['email']);

    // Case 3: Check with email_id='primary email id'
    $result = $this->callAPISuccessGetSingle('contact', ['email_id' => $email1['id']]);
    $this->assertEquals($contact1['id'], $result['id']);

    // Check no wildcard is appended
    $this->callAPISuccessGetCount('Contact', ['email' => 'man3@yahoo.co'], 0);

    $this->callAPISuccess('contact', 'delete', $contact1);
  }

  /**
   * Test creating individual by name.
   *
   * Verify create individual contact with only first and last names succeeds.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateNameIndividual(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
    ];

    $this->callAPISuccess('contact', 'create', $params);
  }

  /**
   * Test creating individual by display_name.
   *
   * Display name & sort name should be set.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateDisplayNameIndividual(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'display_name' => 'abc1',
      'contact_type' => 'Individual',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);
    $params['sort_name'] = 'abc1';
    $this->getAndCheck($params, $contact['id'], 'contact');
  }

  /**
   * Test that name searches are case insensitive.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetNameVariantsCaseInsensitive(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('contact', 'create', [
      'display_name' => 'Abc1',
      'contact_type' => 'Individual',
    ]);
    $this->callAPISuccessGetSingle('Contact', ['display_name' => 'aBc1']);
    $this->callAPISuccessGetSingle('Contact', ['sort_name' => 'aBc1']);
    Civi::settings()->set('includeNickNameInName', TRUE);
    $this->callAPISuccessGetSingle('Contact', ['display_name' => 'aBc1']);
    $this->callAPISuccessGetSingle('Contact', ['sort_name' => 'aBc1']);
    Civi::settings()->set('includeNickNameInName', FALSE);
  }

  /**
   * Test old keys still work.
   *
   * Verify that attempt to create individual contact with
   * first and last names and old key values works
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateNameIndividualOldKeys(): void {
    $params = [
      'individual_prefix' => 'Dr.',
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
      'individual_suffix' => 'Jr.',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);
    $result = $this->callAPISuccess('contact', 'getsingle', ['id' => $contact['id']]);

    $this->assertArrayKeyExists('prefix_id', $result);
    $this->assertArrayKeyExists('suffix_id', $result);
    $this->assertArrayKeyExists('gender_id', $result);
    $this->assertEquals(4, $result['prefix_id']);
    $this->assertEquals(1, $result['suffix_id']);
  }

  /**
   * Test preferred keys work.
   *
   * Verify that attempt to create individual contact with
   * first and last names and old key values works
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateNameIndividualRecommendedKeys2(): void {
    $params = [
      'prefix_id' => 'Dr.',
      'first_name' => 'abc1',
      'contact_type' => 'Individual',
      'last_name' => 'xyz1',
      'suffix_id' => 'Jr.',
      'gender_id' => 'Male',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);
    $result = $this->callAPISuccess('contact', 'getsingle', ['id' => $contact['id']]);

    $this->assertArrayKeyExists('prefix_id', $result);
    $this->assertArrayKeyExists('suffix_id', $result);
    $this->assertArrayKeyExists('gender_id', $result);
    $this->assertEquals(4, $result['prefix_id']);
    $this->assertEquals(1, $result['suffix_id']);
  }

  /**
   * Test household name is sufficient for create.
   *
   * Verify that attempt to create household contact with only
   * household name succeeds
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateNameHousehold(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'household_name' => 'The abc Household',
      'contact_type' => 'Household',
    ];
    $this->callAPISuccess('contact', 'create', $params);
  }

  /**
   * Test organization name is sufficient for create.
   *
   * Verify that attempt to create organization contact with only
   * organization name succeeds.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateNameOrganization(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'organization_name' => 'The abc Organization',
      'contact_type' => 'Organization',
    ];
    $this->callAPISuccess('contact', 'create', $params);
  }

  /**
   * Verify that attempt to create organization contact without organization name fails.
   */
  public function testCreateNoNameOrganization(): void {
    $params = [
      'first_name' => 'The abc Organization',
      'contact_type' => 'Organization',
    ];
    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Check that permissions on API key are restricted (CRM-18112).
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   *
   * @dataProvider versionThreeAndFour
   */
  public function testCreateApiKey(int $version): void {
    $this->_apiversion = $version;
    $config = CRM_Core_Config::singleton();
    $contactId = $this->individualCreate([
      'first_name' => 'A',
      'last_name' => 'B',
    ]);

    // Allow edit -- because permissions aren't being checked
    $config->userPermissionClass->permissions = [];
    $result = $this->callAPISuccess('Contact', 'create', [
      'id' => $contactId,
      'api_key' => 'original',
    ]);
    $this->assertEquals('original', $result['values'][$contactId]['api_key']);

    // Allow edit -- because we have adequate permission
    $config->userPermissionClass->permissions = ['access CiviCRM', 'edit all contacts', 'edit api keys'];
    $result = $this->callAPISuccess('Contact', 'create', [
      'check_permissions' => 1,
      'id' => $contactId,
      'api_key' => 'abcd1234',
    ]);
    $this->assertEquals('abcd1234', $result['values'][$contactId]['api_key']);

    // Disallow edit -- because we don't have permission
    $config->userPermissionClass->permissions = ['access CiviCRM', 'edit all contacts'];
    if ($version === 3) {
      $result = $this->callAPIFailure('Contact', 'create', [
        'check_permissions' => 1,
        'id' => $contactId,
        'api_key' => 'defg4321',
      ]);
      $this->assertMatchesRegularExpression(';Permission denied to modify api key;', $result['error_message']);
    }
    else {
      $this->callAPISuccess('Contact', 'create', [
        'check_permissions' => 1,
        'id' => $contactId,
        'api_key' => 'defg4321',
      ]);
      $this->callAPISuccess('Contact', 'get', ['id' => $contactId]);
      $this->assertEquals('abcd1234', CRM_Core_DAO::singleValueQuery(' SELECT api_key FROM civicrm_contact WHERE id = ' . $contactId));
    }
    // Return everything -- because permissions are not being checked
    $config->userPermissionClass->permissions = [];
    $result = $this->callAPISuccess('Contact', 'create', [
      'id' => $contactId,
      'first_name' => 'A2',
    ]);
    $this->assertEquals('A2', $result['values'][$contactId]['first_name']);
    $this->assertEquals('B', $result['values'][$contactId]['last_name']);
    $this->assertEquals('abcd1234', $result['values'][$contactId]['api_key']);

    // Return everything -- because we have adequate permission
    $config->userPermissionClass->permissions = ['access CiviCRM', 'edit all contacts', 'edit api keys'];
    $result = $this->callAPISuccess('Contact', 'create', [
      'check_permissions' => 1,
      'id' => $contactId,
      'first_name' => 'A3',
    ]);
    $this->assertEquals('A3', $result['values'][$contactId]['first_name']);
    $this->assertEquals('B', $result['values'][$contactId]['last_name']);
    $this->assertEquals('abcd1234', $result['values'][$contactId]['api_key']);

    // Should also be returned via join
    $joinResult = $this->callAPISuccessGetSingle('Email', [
      'check_permissions' => 1,
      'contact_id' => $contactId,
      'return' => 'contact_id.api_key',
    ]);
    $this->assertEquals('abcd1234', $joinResult['contact_id.api_key']);

    // Restricted return -- because we don't have permission
    $config->userPermissionClass->permissions = ['access CiviCRM', 'view all contacts', 'edit all contacts'];
    $result = $this->callAPISuccess('Contact', 'create', [
      'check_permissions' => 1,
      'id' => $contactId,
      'first_name' => 'A4',
    ]);
    $this->assertEquals('A4', $result['values'][$contactId]['first_name']);
    $this->assertEquals('B', $result['values'][$contactId]['last_name']);
    $this->assertTrue(empty($result['values'][$contactId]['api_key']));

    // Should also be restricted via join
    $joinResult = $this->callAPISuccessGetSingle('Email', [
      'check_permissions' => 1,
      'contact_id' => $contactId,
      'return' => ['email', 'contact_id.api_key'],
    ]);
    $this->assertTrue(empty($joinResult['contact_id.api_key']));
  }

  /**
   * Check with complete array + custom field.
   *
   * Note that the test is written on purpose without any
   * variables specific to participant so it can be replicated into other entities
   * and / or moved to the automated test suite
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testCreateWithCustom(int $version): void {
    $this->_apiversion = $version;
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);

    $params = $this->_params;
    $params['custom_' . $ids['custom_field_id']] = 'custom string';
    $result = $this->callAPISuccess($this->_entity, 'create', $params);

    $check = $this->callAPISuccess($this->_entity, 'get', [
      'return.custom_' . $ids['custom_field_id'] => 1,
      'id' => $result['id'],
    ]);
    $this->assertEquals('custom string', $check['values'][$check['id']]['custom_' . $ids['custom_field_id']]);

    $this->customFieldDelete($ids['custom_field_id']);
    $this->customGroupDelete($ids['custom_group_id']);
  }

  /**
   * CRM-12773 - expectation is that civicrm quietly ignores fields without values.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateWithNULLCustomCRM12773(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $params = $this->_params;
    $params['custom_' . $ids['custom_field_id']] = NULL;
    $this->callAPISuccess('contact', 'create', $params);
    $this->customFieldDelete($ids['custom_field_id']);
    $this->customGroupDelete($ids['custom_group_id']);
  }

  /**
   * Test create contact with custom checkbox with empty array
   */
  public function testCreateWithEmptyCustomCheckbox(): void {
    $this->callAPISuccess('OptionGroup', 'create', [
      'name' => 'checkbox_opts',
      'title' => 'Checkbox Options',
      'data_type' => 'String',
      'is_active' => 1,
    ]);
    $this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'checkbox_opts',
      'name' => 'checkbox_option_one',
      'label' => 'Checkbox Option One',
      'is_active' => 1,
      'value' => 1,
    ]);
    $custom_group_id = $this->createCustomGroup([
      'name' => 'checkbox_custom_group',
      'title' => 'Checkbox Group',
      'extends' => 'Contact',
    ]);
    $custom_field_id = $this->callAPISuccess('CustomField', 'create', [
      'name' => 'a_checkbox_field',
      'label' => 'A Checkbox Field',
      'custom_group_id' => $custom_group_id,
      'option_group_id' => 'checkbox_opts',
      'html_type' => 'CheckBox',
      'data_type' => 'String',
      'is_active' => 1,
    ])['id'];

    $params = $this->_params;
    $params['custom_' . $custom_field_id] = [];
    $contact_id = $this->callAPISuccess('Contact', 'create', $params)['id'];

    $result = $this->callAPISuccessGetSingle('Contact', ['id' => $contact_id, 'return' => ['custom_' . $custom_field_id]]);
    $this->assertSame('', $result['custom_' . $custom_field_id]);

    $this->customFieldDelete($custom_field_id);
    $this->customGroupDelete($custom_group_id);
  }

  /**
   * CRM-14232 test preferred language set to site default if not passed.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   *
   */
  public function testCreatePreferredLanguageUnset(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Snoop',
      'last_name' => 'Dog',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Dog']);
    $this->assertEquals('en_US', $result['preferred_language']);
  }

  /**
   * CRM-14232 test preferred language returns setting if not passed.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   *
   */
  public function testCreatePreferredLanguageSet(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('Setting', 'create', ['contact_default_language' => 'fr_FR']);
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Snoop',
      'last_name' => 'Dog',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Dog']);
    $this->assertEquals('fr_FR', $result['preferred_language']);
  }

  /**
   * CRM-14232 test preferred language returns setting if not passed where setting is NULL.
   * TODO: Api4
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreatePreferredLanguageNull(): void {
    $this->callAPISuccess('Setting', 'create', ['contact_default_language' => 'null']);
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Snoop',
      'last_name' => 'Dog',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Dog']);
    $this->assertEquals(NULL, $result['preferred_language']);
  }

  /**
   * CRM-14232 test preferred language returns setting if not passed where setting is NULL.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreatePreferredLanguagePassed(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('Setting', 'create', ['contact_default_language' => 'null']);
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Snoop',
      'last_name' => 'Dog',
      'contact_type' => 'Individual',
      'preferred_language' => 'en_AU',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Dog']);
    $this->assertEquals('en_AU', $result['preferred_language']);
  }

  /**
   * CRM-15792 - create/update datetime field for contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateContactCustomFldDateTime(): void {
    $customGroup = $this->customGroupCreate(['extends' => 'Individual', 'title' => 'datetime_test_group']);
    $dateTime = CRM_Utils_Date::currentDBDate();
    //check date custom field is saved along with time when time_format is set
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.CustomField.create' => [
        'custom_group_id' => $customGroup['id'],
        'name' => 'test_datetime',
        'label' => 'Demo Date',
        'html_type' => 'Select Date',
        'data_type' => 'Date',
        'time_format' => 2,
        'weight' => 4,
        'is_required' => 1,
        'is_searchable' => 0,
        'is_active' => 1,
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);
    $customFldId = $result['values'][$result['id']]['api.CustomField.create']['id'];
    $this->assertNotNull($result['id']);
    $this->assertNotNull($customFldId);

    $params = [
      'id' => $result['id'],
      "custom_$customFldId" => $dateTime,
      'api.CustomValue.get' => 1,
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);
    $this->assertNotNull($result['id']);
    $customFldDate = date('YmdHis', strtotime($result['values'][$result['id']]['api.CustomValue.get']['values'][0]['latest']));
    $this->assertNotNull($customFldDate);
    $this->assertEquals($dateTime, $customFldDate);
    $customValueId = $result['values'][$result['id']]['api.CustomValue.get']['values'][0]['id'];
    $dateTime = date('Ymd');
    //date custom field should not contain time part when time_format is null
    $params = [
      'id' => $result['id'],
      'api.CustomField.create' => [
        'id' => $customFldId,
        'html_type' => 'Select Date',
        'data_type' => 'Date',
        'time_format' => '',
      ],
      'api.CustomValue.create' => [
        'id' => $customValueId,
        'entity_id' => $result['id'],
        "custom_$customFldId" => $dateTime,
      ],
      'api.CustomValue.get' => 1,
    ];
    $result = $this->callAPISuccess('Contact', 'create', $params);
    $this->assertNotNull($result['id']);
    $customFldDate = date('Ymd', strtotime($result['values'][$result['id']]['api.CustomValue.get']['values'][0]['latest']));
    $customFldTime = date('His', strtotime($result['values'][$result['id']]['api.CustomValue.get']['values'][0]['latest']));
    $this->assertNotNull($customFldDate);
    $this->assertEquals($dateTime, $customFldDate);
    $this->assertEquals(000000, $customFldTime);
    $this->callAPISuccess('Contact', 'create', $params);
  }

  /**
   * Test creating a current employer through API.
   */
  public function testContactCreateCurrentEmployer(): void {
    // Here we will just do the get for set-up purposes.
    $count = $this->callAPISuccess('contact', 'getcount', [
      'organization_name' => 'new employer org',
      'contact_type' => 'Organization',
    ]);
    $this->assertEquals(0, $count);
    $employerResult = $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'current_employer' => 'new employer org',
    ]));
    // do it again as an update to check it doesn't cause an error
    $employerResult = $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'current_employer' => 'new employer org',
      'id' => $employerResult['id'],
    ]));
    $expectedCount = 1;
    $this->callAPISuccess('contact', 'getcount', [
      'organization_name' => 'new employer org',
      'contact_type' => 'Organization',
    ], $expectedCount);

    $result = $this->callAPISuccess('contact', 'getsingle', [
      'id' => $employerResult['id'],
    ]);

    $this->assertEquals('new employer org', $result['current_employer']);

  }

  /**
   * Test creating a current employer through API.
   *
   * Check it will re-activate a de-activated employer
   */
  public function testContactCreateDuplicateCurrentEmployerEnables(): void {
    // Set up  - create employer relationship.
    $employerResult = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['current_employer' => 'new employer org']));
    $relationship = $this->callAPISuccess('relationship', 'get', [
      'contact_id_a' => $employerResult['id'],
    ]);

    //disable & check it is disabled
    $this->callAPISuccess('relationship', 'create', ['id' => $relationship['id'], 'is_active' => 0]);
    $this->callAPISuccess('relationship', 'getvalue', [
      'id' => $relationship['id'],
      'return' => 'is_active',
    ], 0);

    // Re-set the current employer - thus enabling the relationship.
    $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'current_employer' => 'new employer org',
      'id' => $employerResult['id'],
    ]));
    //check is_active is now 1
    $relationship = $this->callAPISuccess('relationship', 'getsingle', ['return' => 'is_active']);
    $this->assertEquals(1, $relationship['is_active']);
  }

  /**
   * Check deceased contacts are not retrieved.
   *
   * Note at time of writing the default is to return default. This should possibly be changed & test added.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   *
   */
  public function testGetDeceasedRetrieved(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess($this->_entity, 'create', $this->_params);
    $c2 = $this->callAPISuccess($this->_entity, 'create', [
      'first_name' => 'bb',
      'last_name' => 'ccc',
      'contact_type' => 'Individual',
      'is_deceased' => 1,
    ]);
    $result = $this->callAPISuccess($this->_entity, 'get', ['is_deceased' => 0]);
    $this->assertArrayNotHasKey($c2['id'], $result['values']);
  }

  /**
   * Test that sort works - old syntax.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetSort(): void {
    $c1 = $this->callAPISuccess($this->_entity, 'create', $this->_params);
    $c2 = $this->callAPISuccess($this->_entity, 'create', [
      'first_name' => 'bb',
      'last_name' => 'ccc',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccess($this->_entity, 'get', [
      'sort' => 'first_name ASC',
      'return.first_name' => 1,
      'sequential' => 1,
      'rowCount' => 1,
      'contact_type' => 'Individual',
    ]);

    $this->assertEquals('abc1', $result['values'][0]['first_name']);
    $result = $this->callAPISuccess($this->_entity, 'get', [
      'sort' => 'first_name DESC',
      'return.first_name' => 1,
      'sequential' => 1,
      'rowCount' => 1,
    ]);
    $this->assertEquals('bb', $result['values'][0]['first_name']);

    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c1['id']]);
    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c2['id']]);
  }

  /**
   * Test the like operator works for Contact.get
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetEmailLike(): void {
    $this->individualCreate();
    $this->callAPISuccessGetCount('Contact', ['email' => ['LIKE' => 'an%']], 1);
    $this->callAPISuccessGetCount('Contact', ['email' => ['LIKE' => 'ab%']], 0);
  }

  /**
   * Test that we can retrieve contacts using array syntax.
   *
   * I.e 'id' => array('IN' => array('3,4')).
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   *
   */
  public function testGetINIDArray(int $version): void {
    $this->_apiversion = $version;
    $c1 = $this->callAPISuccess($this->_entity, 'create', $this->_params);
    $c2 = $this->callAPISuccess($this->_entity, 'create', [
      'first_name' => 'bb',
      'last_name' => 'ccc',
      'contact_type' => 'Individual',
    ]);
    $c3 = $this->callAPISuccess($this->_entity, 'create', [
      'first_name' => 'hh',
      'last_name' => 'll',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccess($this->_entity, 'get', ['id' => ['IN' => [$c1['id'], $c3['id']]]]);
    $this->assertEquals(2, $result['count']);
    $this->assertEquals([$c1['id'], $c3['id']], array_keys($result['values']));
    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c1['id']]);
    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c2['id']]);
    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c3['id']]);
  }

  /**
   * Test variants on deleted behaviour.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetDeleted(): void {
    $params = $this->_params;
    $contact1 = $this->callAPISuccess('contact', 'create', $params);
    $params['is_deleted'] = 1;
    $params['last_name'] = 'bcd';
    $contact2 = $this->callAPISuccess('contact', 'create', $params);
    $countActive = $this->callAPISuccess('contact', 'getcount', [
      'showAll' => 'active',
      'contact_type' => 'Individual',
    ]);
    $countAll = $this->callAPISuccess('contact', 'getcount', ['showAll' => 'all', 'contact_type' => 'Individual']);
    $countTrash = $this->callAPISuccess('contact', 'getcount', ['showAll' => 'trash', 'contact_type' => 'Individual']);
    $countDefault = $this->callAPISuccess('contact', 'getcount', ['contact_type' => 'Individual']);
    $countDeleted = $this->callAPISuccess('contact', 'getcount', [
      'contact_type' => 'Individual',
      'contact_is_deleted' => 1,
    ]);
    $countNotDeleted = $this->callAPISuccess('contact', 'getcount', [
      'contact_is_deleted' => 0,
      'contact_type' => 'Individual',
    ]);
    $this->callAPISuccess('contact', 'delete', ['id' => $contact1['id']]);
    $this->callAPISuccess('contact', 'delete', ['id' => $contact2['id']]);
    $this->assertEquals(1, $countNotDeleted, 'contact_is_deleted => 0 is respected');
    $this->assertEquals(1, $countActive);
    $this->assertEquals(1, $countTrash);
    $this->assertEquals(2, $countAll);
    $this->assertEquals(1, $countDeleted);
    $this->assertEquals(1, $countDefault, 'Only active by default in line');
  }

  /**
   * Test that sort works - new syntax.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetSortNewSyntax(int $version): void {
    $this->_apiversion = $version;
    $c1 = $this->callAPISuccess($this->_entity, 'create', $this->_params);
    $c2 = $this->callAPISuccess($this->_entity, 'create', [
      'first_name' => 'bb',
      'last_name' => 'ccc',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccess($this->_entity, 'getvalue', [
      'return' => 'first_name',
      'contact_type' => 'Individual',
      'options' => [
        'limit' => 1,
        'sort' => 'first_name',
      ],
    ]);
    $this->assertEquals('abc1', $result);

    $result = $this->callAPISuccess($this->_entity, 'getvalue', [
      'return' => 'first_name',
      'contact_type' => 'Individual',
      'options' => [
        'limit' => 1,
        'sort' => 'first_name DESC',
      ],
    ]);
    $this->assertEquals('bb', $result);

    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c1['id']]);
    $this->callAPISuccess($this->_entity, 'delete', ['id' => $c2['id']]);
  }

  /**
   * Test sort and limit for chained relationship get.
   *
   * https://issues.civicrm.org/jira/browse/CRM-15983
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testSortLimitChainedRelationshipGetCRM15983(int $version): void {
    $this->_apiversion = $version;
    // Some contact
    $create_result_1 = $this->callAPISuccess('contact', 'create', [
      'first_name' => 'Jules',
      'last_name' => 'Smos',
      'contact_type' => 'Individual',
    ]);

    // Create another contact with two relationships.
    $create_params = [
      'first_name' => 'Jos',
      'last_name' => 'Smos',
      'contact_type' => 'Individual',
      'api.relationship.create' => [
        [
          'contact_id_a' => '$value.id',
          'contact_id_b' => $create_result_1['id'],
          // spouse of:
          'relationship_type_id' => 2,
          'start_date' => '2005-01-12',
          'end_date' => '2006-01-11',
          'description' => 'old',
        ],
        [
          'contact_id_a' => '$value.id',
          'contact_id_b' => $create_result_1['id'],
          // spouse of (was married twice :))
          'relationship_type_id' => 2,
          'start_date' => '2006-07-01',
          'end_date' => '2010-07-01',
          'description' => 'new',
        ],
      ],
    ];
    $create_result = $this->callAPISuccess('contact', 'create', $create_params);

    // Try to retrieve the contact and the most recent relationship.
    $get_params = [
      'sequential' => 1,
      'id' => $create_result['id'],
      'api.relationship.get' => [
        'contact_id_a' => '$value.id',
        'options' => [
          'limit' => '1',
          'sort' => 'start_date DESC',
        ],
      ],
    ];
    $get_result = $this->callAPISuccess('contact', 'getsingle', $get_params);

    // Clean up.
    $this->callAPISuccess('contact', 'delete', [
      'id' => $create_result['id'],
    ]);

    // Assert.
    $this->assertEquals(1, $get_result['api.relationship.get']['count']);
    $this->assertEquals('new', $get_result['api.relationship.get']['values'][0]['description']);
  }

  /**
   * Test apostrophe works in get & create.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetApostropheCRM10857(int $version): void {
    $this->_apiversion = $version;
    $params = array_merge($this->_params, ['last_name' => "O'Connor"]);
    $this->callAPISuccess($this->_entity, 'create', $params);
    $result = $this->callAPISuccess($this->_entity, 'getsingle', [
      'last_name' => "O'Connor",
      'sequential' => 1,
    ]);
    $this->assertEquals("O'Connor", $result['last_name']);
  }

  /**
   * Test between accepts zero.
   *
   * In the past it incorrectly required !empty.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetBetweenZeroWorks(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess($this->_entity, 'get', [
      'contact_id' => ['BETWEEN' => [0, 9]],
    ]);
    $this->callAPISuccess($this->_entity, 'get', [
      'contact_id' => [
        'BETWEEN' => [
          (0 - 9),
          0,
        ],
      ],
    ]);
  }

  /**
   * Test retrieval by addressee id.
   * V3 only - the "skip_greeting_processing" param is not currently in v4
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetByAddresseeID(): void {
    $individual1ID = $this->individualCreate([
      'skip_greeting_processing' => 1,
      'addressee_id' => 'null',
      'email_greeting_id' => 'null',
      'postal_greeting_id' => 'null',
    ]);
    $individual2ID = $this->individualCreate();

    $this->assertEquals($individual1ID,
      $this->callAPISuccessGetValue('Contact', ['contact_type' => 'Individual', 'addressee_id' => ['IS NULL' => 1], 'return' => 'id'])
    );
    $this->assertEquals($individual1ID,
      $this->callAPISuccessGetValue('Contact', ['contact_type' => 'Individual', 'email_greeting_id' => ['IS NULL' => 1], 'return' => 'id'])
    );
    $this->assertEquals($individual1ID,
      $this->callAPISuccessGetValue('Contact', ['contact_type' => 'Individual', 'postal_greeting_id' => ['IS NULL' => 1], 'return' => 'id'])
    );

    $this->assertEquals($individual2ID,
      $this->callAPISuccessGetValue('Contact', ['contact_type' => 'Individual', 'addressee_id' => ['NOT NULL' => 1], 'return' => 'id'])
    );
  }

  /**
   * Check with complete array + custom field.
   *
   * Note that the test is written on purpose without any
   * variables specific to participant so it can be replicated into other
   * entities and / or moved to the automated test suite
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetWithCustom(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);

    $params = $this->_params;
    $params['custom_' . $ids['custom_field_id']] = 'custom string';
    $result = $this->callAPISuccess($this->_entity, 'create', $params);

    $check = $this->callAPISuccess($this->_entity, 'get', [
      'return.custom_' . $ids['custom_field_id'] => 1,
      'id' => $result['id'],
    ]);

    $this->assertEquals('custom string', $check['values'][$check['id']]['custom_' . $ids['custom_field_id']]);
    $fields = ($this->callAPISuccess('contact', 'getfields', $params));
    $this->assertIsArray($fields['values']['custom_' . $ids['custom_field_id']]);
    $this->customFieldDelete($ids['custom_field_id']);
    $this->customGroupDelete($ids['custom_group_id']);
  }

  public function testGetOptions(): void {
    $options = $this->callAPISuccess($this->_entity, 'getoptions', ['field' => 'worldregion_id']);
    $this->assertContains('Europe and Central Asia', $options['values']);

    $options = $this->callAPISuccess($this->_entity, 'getoptions', ['field' => 'country']);
    $this->assertContains('France', $options['values']);

    $options = $this->callAPISuccess($this->_entity, 'getoptions', ['field' => 'state_province']);
    $this->assertContains('Alaska', $options['values']);
  }

  public function testGetOptionsWithCustom(): void {
    $this->createCustomGroupWithFieldOfType(['extends' => $this->entity], 'select', 'foo');
    $this->callAPISuccess('CustomField', 'create', ['id' => $this->ids['CustomField']['fooselect'], 'is_active' => 0]);
    $options = $this->callAPISuccess($this->entity, 'getoptions', ['field' => 'custom_' . $this->ids['CustomField']['fooselect']]);
    $this->callAPISuccess('CustomField', 'create', ['id' => $this->ids['CustomField']['fooselect'], 'is_active' => 1]);
    $options = $this->callAPISuccess($this->entity, 'getoptions', ['field' => 'custom_' . $this->ids['CustomField']['fooselect']]);
    $this->assertEquals(['R' => 'Red', 'Y' => 'Yellow', 'G' => 'Green'], $options['values']);
  }

  /**
   * Tests that using 'return' with a custom field not of type contact does not inappropriately filter.
   *
   * https://lab.civicrm.org/dev/core/issues/1025
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetWithCustomOfActivityType(): void {
    $this->createCustomGroupWithFieldOfType(['extends' => 'Activity']);
    $this->createCustomGroupWithFieldOfType(['extends' => 'Contact'], 'text', 'contact_');
    $contactID = $this->individualCreate();
    $this->callAPISuccessGetSingle('Contact', ['id' => $contactID, 'return' => ['external_identifier', $this->getCustomFieldName('contact_text')]]);
  }

  /**
   * Check with complete array + custom field.
   *
   * Note that the test is written on purpose without any
   * variables specific to participant so it can be replicated into other
   * entities and / or moved to the automated test suite
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetWithCustomReturnSyntax(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);

    $params = $this->_params;
    $params['custom_' . $ids['custom_field_id']] = 'custom string';
    $result = $this->callAPISuccess($this->_entity, 'create', $params);
    $params = ['return' => 'custom_' . $ids['custom_field_id'], 'id' => $result['id']];
    $check = $this->callAPISuccess($this->_entity, 'get', $params);

    $this->assertEquals('custom string', $check['values'][$check['id']]['custom_' . $ids['custom_field_id']]);
    $this->customFieldDelete($ids['custom_field_id']);
    $this->customGroupDelete($ids['custom_group_id']);
  }

  /**
   * Check that address name, ID is returned if required.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetReturnAddress(): void {
    $contactID = $this->individualCreate();
    $result = $this->callAPISuccess('address', 'create', [
      'contact_id' => $contactID,
      'address_name' => 'My house',
      'location_type_id' => 'Home',
      'street_address' => '1 my road',
    ]);
    $addressID = $result['id'];

    $result = $this->callAPISuccessGetSingle('contact', [
      'return' => 'address_name, street_address, address_id',
      'id' => $contactID,
    ]);
    $this->assertEquals($addressID, $result['address_id']);
    $this->assertEquals('1 my road', $result['street_address']);
    $this->assertEquals('My house', $result['address_name']);

  }

  /**
   * Test group filter syntax.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetGroupIDFromContact(): void {
    $groupId = $this->groupCreate();
    $params = [
      'email' => 'man2@yahoo.com',
      'contact_type' => 'Individual',
      'location_type_id' => 1,
      'api.group_contact.create' => ['group_id' => $groupId],
    ];

    $this->callAPISuccess('contact', 'create', $params);
    // testing as integer
    $params = [
      'filter.group_id' => $groupId,
      'contact_type' => 'Individual',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals(1, $result['count']);
    // group 26 doesn't exist, but we can still search contacts in it.
    $params = [
      'filter.group_id' => 26,
      'contact_type' => 'Individual',
    ];
    $this->callAPISuccess('contact', 'get', $params);
    // testing as string
    $params = [
      'filter.group_id' => "$groupId, 26",
      'contact_type' => 'Individual',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals(1, $result['count']);
    $params = [
      'filter.group_id' => '26,27',
      'contact_type' => 'Individual',
    ];
    $this->callAPISuccess('contact', 'get', $params);

    // testing as string
    $params = [
      'filter.group_id' => [$groupId, 26],
      'contact_type' => 'Individual',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals(1, $result['count']);

    //test in conjunction with other criteria
    $params = [
      'filter.group_id' => [$groupId, 26],
      'contact_type' => 'Organization',
    ];
    $this->callAPISuccess('contact', 'get', $params);
    $params = [
      'filter.group_id' => [26, 27],
      'contact_type' => 'Individual',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals(0, $result['count']);
  }

  /**
   * Verify that attempt to create individual contact with two chained websites
   * succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualWithContributionDottedSyntax(): void {
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.contribution.create' => [
        'receive_date' => '2010-01-01',
        'total_amount' => 100.00,
        'financial_type_id' => $this->_financialTypeId,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 15345,
        'invoice_id' => 67990,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.website.create' => [
        'url' => 'https://civicrm.org',
      ],
      'api.website.create.2' => [
        'url' => 'https://chained.org',
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);

    // checking child function result not covered in callAPISuccess
    $this->assertAPISuccess($result['values'][$result['id']]['api.website.create']);
    $this->assertEquals('https://chained.org', $result['values'][$result['id']]['api.website.create.2']['values'][0]['url']);
    $this->assertEquals('https://civicrm.org', $result['values'][$result['id']]['api.website.create']['values'][0]['url']);

    // delete the contact
    $this->callAPISuccess('contact', 'delete', $result);
  }

  /**
   * Verify that attempt to create individual contact with chained contribution
   * and website succeeds.
   *
   */
  public function testCreateIndividualWithContributionChainedArrays(): void {
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.contribution.create' => [
        'receive_date' => '2010-01-01',
        'total_amount' => 100.00,
        'financial_type_id' => $this->_financialTypeId,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 12345,
        'invoice_id' => 67890,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.website.create' => [
        [
          'url' => 'https://civicrm.org',
        ],
        [
          'url' => 'https://chained.org',
          'website_type_id' => 2,
        ],
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);

    // the callAndDocument doesn't check the chained call
    $this->assertEquals(0, $result['values'][$result['id']]['api.website.create'][0]['is_error']);
    $this->assertEquals('https://chained.org', $result['values'][$result['id']]['api.website.create'][1]['values'][0]['url']);
    $this->assertEquals('https://civicrm.org', $result['values'][$result['id']]['api.website.create'][0]['values'][0]['url']);
  }

  /**
   * Test for direction when chaining relationships.
   *
   * https://issues.civicrm.org/jira/browse/CRM-16084
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testDirectionChainingRelationshipsCRM16084(int $version): void {
    $this->_apiversion = $version;
    // Some contact, called Jules.
    $create_result_1 = $this->callAPISuccess('contact', 'create', [
      'first_name' => 'Jules',
      'last_name' => 'Smos',
      'contact_type' => 'Individual',
    ]);

    // Another contact: Jos, child of Jules.
    $create_params = [
      'first_name' => 'Jos',
      'last_name' => 'Smos',
      'contact_type' => 'Individual',
      'api.relationship.create' => [
        [
          'contact_id_a' => '$value.id',
          'contact_id_b' => $create_result_1['id'],
          // child of
          'relationship_type_id' => 1,
        ],
      ],
    ];
    $create_result_2 = $this->callAPISuccess('contact', 'create', $create_params);

    // Mia is the child of Jos.
    $create_params = [
      'first_name' => 'Mia',
      'last_name' => 'Smos',
      'contact_type' => 'Individual',
      'api.relationship.create' => [
        [
          'contact_id_a' => '$value.id',
          'contact_id_b' => $create_result_2['id'],
          // child of
          'relationship_type_id' => 1,
        ],
      ],
    ];
    $create_result_3 = $this->callAPISuccess('contact', 'create', $create_params);

    // Get Jos and his children.
    $get_params = [
      'sequential' => 1,
      'id' => $create_result_2['id'],
      'api.relationship.get' => [
        'contact_id_b' => '$value.id',
        'relationship_type_id' => 1,
      ],
    ];
    $get_result = $this->callAPISuccess('contact', 'getsingle', $get_params);

    // Clean up first.
    $this->callAPISuccess('contact', 'delete', [
      'id' => $create_result_1['id'],
    ]);
    $this->callAPISuccess('contact', 'delete', [
      'id' => $create_result_2['id'],
    ]);

    // Assert.
    $this->assertEquals(1, $get_result['api.relationship.get']['count']);
    $this->assertEquals($create_result_3['id'], $get_result['api.relationship.get']['values'][0]['contact_id_a']);
  }

  /**
   * Verify that attempt to create individual contact with first, and last
   * names and email succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualWithNameEmail(): void {
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);

    $this->callAPISuccess('contact', 'delete', $contact);
  }

  /**
   * Verify that attempt to create individual contact just email succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualWithJustEmail(): void {
    $params = [
      'contact_type' => 'Individual',
      'email' => 'garlic@example.com',
    ];
    $contact = $this->callAPISuccess('contact', 'create', $params);
    $created = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id']]);
    $this->assertEquals('garlic@example.com', $created['display_name']);
    $this->assertEquals('garlic@example.com', $created['sort_name']);
    $this->callAPISuccessGetSingle('Email', [
      'email' => 'garlic@example.com',
      'is_primary' => 1,
      'location_type_id' => CRM_Core_BAO_LocationType::getDefault()->id,
      'contact_id' => $contact['id'],
    ]);
  }

  /**
   * Verify that updating a contact email updates their display name.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateIndividualWithJustEmailViaChain($version): void {
    $this->_apiversion = $version;
    $params = [
      'contact_type' => 'Individual',
      // In APIv4 this param will be ignored as 'email' is not a field on the Contact record
      'email' => 'onion@example.com',
      'api.Email.create' => [
        'email' => 'garlic@example.com',
        'is_primary' => 1,
      ],
    ];
    $contact = $this->callAPISuccess('contact', 'create', $params);
    $created = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id']]);
    $this->assertEquals('garlic@example.com', $created['display_name']);
    $this->assertEquals('garlic@example.com', $created['sort_name']);
    $this->callAPISuccessGetSingle('Email', [
      'email' => 'garlic@example.com',
      'is_primary' => 1,
      'location_type_id' => CRM_Core_BAO_LocationType::getDefault()->id,
      'contact_id' => $contact['id'],
    ]);
  }

  /**
   * Verify that attempt to create individual contact with no data fails.
   */
  public function testCreateIndividualWithOutNameEmail(): void {
    $params = [
      'contact_type' => 'Individual',
    ];
    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Test create individual contact with first &last names, email and location
   * type succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualWithNameEmailLocationType(): void {
    $params = [
      'first_name' => 'abc4',
      'last_name' => 'xyz4',
      'email' => 'man4@yahoo.com',
      'contact_type' => 'Individual',
      'location_type_id' => 1,
    ];
    $result = $this->callAPISuccess('contact', 'create', $params);

    $this->callAPISuccess('contact', 'delete', ['id' => $result['id']]);
  }

  /**
   * Verify that when changing employers the old employer relationship becomes
   * inactive.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateIndividualWithEmployer(): void {
    $employer = $this->organizationCreate();
    $employer2 = $this->organizationCreate();

    $params = [
      'email' => 'man4@yahoo.com',
      'contact_type' => 'Individual',
      'employer_id' => $employer,
    ];

    $result = $this->callAPISuccess('contact', 'create', $params);
    $relationships = $this->callAPISuccess('relationship', 'get', [
      'contact_id_a' => $result['id'],
      'sequential' => 1,
    ]);

    $this->assertEquals($employer, $relationships['values'][0]['contact_id_b']);

    // Add more random relationships to make the test more realistic
    foreach (['Employee of', 'Volunteer for'] as $relationshipType) {
      $relTypeId = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_RelationshipType', $relationshipType, 'id', 'name_a_b');
      $this->callAPISuccess('relationship', 'create', [
        'contact_id_a' => $result['id'],
        'contact_id_b' => $this->organizationCreate(),
        'is_active' => 1,
        'relationship_type_id' => $relTypeId,
      ]);
    }

    // Add second employer
    $params['employer_id'] = $employer2;
    $params['id'] = $result['id'];
    $result = $this->callAPISuccess('contact', 'create', $params);

    $relationships = $this->callAPISuccess('relationship', 'get', [
      'contact_id_a' => $result['id'],
      'sequential' => 1,
      'is_active' => 0,
    ]);

    $this->assertEquals($employer, $relationships['values'][0]['contact_id_b']);
  }

  /**
   * Verify that attempt to create household contact with details succeeds.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateHouseholdDetails(): void {
    $params = [
      'household_name' => 'abc8\'s House',
      'nick_name' => 'x House',
      'email' => 'man8@yahoo.com',
      'contact_type' => 'Household',
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);

    $this->callAPISuccess('contact', 'delete', $contact);
  }

  /**
   * Verify that attempt to create household contact with inadequate details fails.
   */
  public function testCreateHouseholdInadequateDetails(): void {
    $params = [
      'nick_name' => 'x House',
      'email' => 'man8@yahoo.com',
      'contact_type' => 'Household',
    ];
    $this->callAPIFailure('contact', 'create', $params);
  }

  /**
   * Verify successful update of individual contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpdateIndividualWithAll(): void {
    $contactID = $this->individualCreate();

    $params = [
      'id' => $contactID,
      'first_name' => 'abcd',
      'contact_type' => 'Individual',
      'nick_name' => 'This is nickname first',
      'do_not_email' => '1',
      'do_not_phone' => '1',
      'do_not_mail' => '1',
      'do_not_trade' => '1',
      'legal_identifier' => 'ABC23853ZZ2235',
      'external_identifier' => '1928837465',
      'image_URL' => 'https://some.url.com/image.jpg',
      'home_url' => 'https://www.example.org',
    ];

    $this->callAPISuccess('Contact', 'Update', $params);
    $getResult = $this->callAPISuccess('Contact', 'Get', $params);
    unset($params['contact_id']);
    //Todo - neither API v2 or V3 are testing for home_url - not sure if it is being set.
    //reducing this test partially back to api v2 level to get it through
    unset($params['home_url']);
    foreach ($params as $key => $value) {
      $this->assertEquals($value, $getResult['values'][$contactID][$key]);
    }
  }

  /**
   * Verify successful update of organization contact.
   *
   * @throws \Exception
   */
  public function testUpdateOrganizationWithAll(): void {
    $contactID = $this->organizationCreate();

    $params = [
      'id' => $contactID,
      'organization_name' => 'WebAccess India Pvt Ltd',
      'legal_name' => 'WebAccess',
      'sic_code' => 'ABC12DEF',
      'contact_type' => 'Organization',
    ];

    $this->callAPISuccess('Contact', 'Update', $params);
    $this->getAndCheck($params, $contactID, 'Contact');
  }

  /**
   * Test merging 2 organizations.
   *
   * CRM-20421: This test make sure that inherited memberships are deleted upon merging organization.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testMergeOrganizations(): void {
    $organizationID1 = $this->organizationCreate();
    $organizationID2 = $this->organizationCreate([], 1);
    $contact = $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'employer_id' => $organizationID1,
    ]));
    $contact = $contact['values'][$contact['id']];

    $membershipType = $this->createEmployerOfMembership();
    $membershipParams = [
      'membership_type_id' => $membershipType['id'],
      'contact_id' => $organizationID1,
      'start_date' => '01/01/2015',
      'join_date' => '01/01/2010',
      'end_date' => '12/31/2015',
    ];
    $ownerMembershipID = $this->contactMembershipCreate($membershipParams);

    $contactMembership = $this->callAPISuccessGetSingle('membership', ['contact_id' => $contact['id']]);

    $this->assertEquals($ownerMembershipID, $contactMembership['owner_membership_id'], 'Contact membership must be inherited from Organization');

    CRM_Dedupe_Merger::moveAllBelongings($organizationID2, $organizationID1, [
      'move_rel_table_memberships' => '0',
      'move_rel_table_relationships' => '1',
      'main_details' => [
        'contact_id' => $organizationID2,
        'contact_type' => 'Organization',
      ],
      'other_details' => [
        'contact_id' => $organizationID1,
        'contact_type' => 'Organization',
      ],
    ]);

    $contactMembership = $this->callAPISuccess('membership', 'get', [
      'contact_id' => $contact['id'],
    ]);

    $this->assertEquals(0, $contactMembership['count'], 'Contact membership must be deleted after merging organization without memberships.');
  }

  /**
   * Test the function that determines if 2 contacts have conflicts.
   *
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function testMergeGetConflicts(): void {
    [$contact1, $contact2] = $this->createDeeplyConflictedContacts();
    $conflicts = $this->callAPISuccess('Contact', 'get_merge_conflicts', ['to_keep_id' => $contact1, 'to_remove_id' => $contact2])['values'];
    $this->assertEquals([
      'safe' => [
        'conflicts' => [
          'contact' => [
            'first_name' => [$contact1 => 'Anthony', $contact2 => 'different', 'title' => 'First Name'],
            'external_identifier' => [$contact1 => 'unique and special', $contact2 => 'uniquer and specialler', 'title' => 'External Identifier'],
            $this->getCustomFieldName('text') => [$contact1 => 'mummy loves me', $contact2 => 'mummy loves me more', 'title' => 'Enter text here'],
          ],
          'address' => [
            [
              'location_type_id' => '1',
              'title' => 'Address 1 (Home)',
              'street_address' => [
                $contact1 => 'big house',
                $contact2 => 'medium house',
              ],
              'display' => [
                $contact1 => "big house\nsmall city, \n",
                $contact2 => "medium house\nsmall city, \n",
              ],
            ],
            [
              'location_type_id' => '2',
              'street_address' => [
                $contact1 => 'big office',
                $contact2 => 'medium office',
              ],
              'title' => 'Address 2 (Work)',
              'display' => [
                $contact1 => "big office\nsmall city, \n",
                $contact2 => "medium office\nsmall city, \n",
              ],
            ],
          ],
          'email' => [
            [
              'location_type_id' => '1',
              'email' => [
                $contact1 => 'bob@example.com',
                $contact2 => 'anthony_anderson@civicrm.org',
              ],
              'title' => 'Email 1 (Home)',
              'display' => [
                $contact1 => 'bob@example.com',
                $contact2 => 'anthony_anderson@civicrm.org',
              ],
            ],
          ],
        ],
        'resolved' => [],
      ],
    ], $conflicts);

    $this->callAPISuccess('Job', 'process_batch_merge');
    $defaultRuleGroupID = $this->callAPISuccessGetValue('RuleGroup', [
      'contact_type' => 'Individual',
      'used' => 'Unsupervised',
      'return' => 'id',
      'options' => ['limit' => 1],
    ]);

    $duplicates = $this->callAPISuccess('Dedupe', 'getduplicates', ['rule_group_id' => $defaultRuleGroupID]);
    $this->assertEquals($conflicts['safe']['conflicts'], $duplicates['values'][0]['safe']['conflicts']);
  }

  /**
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetConflictsAggressiveMode(): void {
    [$contact1, $contact2] = $this->createDeeplyConflictedContacts();
    $conflicts = $this->callAPISuccess('Contact', 'get_merge_conflicts', ['to_keep_id' => $contact1, 'to_remove_id' => $contact2, 'mode' => ['safe', 'aggressive']])['values'];
    $this->assertEquals([
      'contact' => [
        'external_identifier' => 'uniquer and specialler',
        'first_name' => 'different',
        'custom_1' => 'mummy loves me more',
      ],
    ], $conflicts['aggressive']['resolved']);
  }

  /**
   * Create inherited membership type for employer relationship.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  private function createEmployerOfMembership(): array {
    $params = [
      'domain_id' => CRM_Core_Config::domainID(),
      'name' => 'Organization Membership',
      'member_of_contact_id' => 1,
      'financial_type_id' => 1,
      'minimum_fee' => 10,
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
      'relationship_type_id' => 5,
      'relationship_direction' => 'b_a',
      'visibility' => 'Public',
      'is_active' => 1,
    ];
    $membershipType = $this->callAPISuccess('membership_type', 'create', $params);
    return (array) $membershipType['values'][$membershipType['id']];
  }

  /**
   * Verify successful update of household contact.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testUpdateHouseholdWithAll(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->householdCreate();

    $params = [
      'id' => $contactID,
      'household_name' => 'ABC household',
      'nick_name' => 'ABC House',
      'contact_type' => 'Household',
    ];

    $result = $this->callAPISuccess('Contact', 'Update', $params);

    $expected = [
      'contact_type' => 'Household',
      'is_opt_out' => 0,
      'sort_name' => 'ABC household',
      'display_name' => 'ABC household',
      'nick_name' => 'ABC House',
    ];
    $this->getAndCheck($expected, $result['id'], 'contact');
  }

  /**
   * Test civicrm_update() without contact type.
   *
   * Deliberately exclude contact_type as it should still cope using civicrm_api.
   *
   * CRM-7645.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testUpdateCreateWithID(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate();
    $this->callAPISuccess('Contact', 'Update', [
      'id' => $contactID,
      'first_name' => 'abcd',
      'last_name' => 'wxyz',
    ]);
  }

  /**
   * Test civicrm_contact_delete() with no contact ID.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testContactDeleteNoID(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'foo' => 'bar',
    ];
    $this->callAPIFailure('contact', 'delete', $params);
  }

  /**
   * Test civicrm_contact_delete() with error.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testContactDeleteError(int $version): void {
    $this->_apiversion = $version;
    $params = ['contact_id' => 999];
    $this->callAPIFailure('contact', 'delete', $params);
  }

  /**
   * Test civicrm_contact_delete().
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactDelete(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate();
    $params = [
      'id' => $contactID,
    ];
    $this->callAPISuccess('contact', 'delete', $params);
  }

  /**
   * Test civicrm_contact_get() return only first name.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetRetFirst(int $version): void {
    $this->_apiversion = $version;
    $contact = $this->callAPISuccess('contact', 'create', $this->_params);
    $params = [
      'contact_id' => $contact['id'],
      'return_first_name' => TRUE,
      'sort' => 'first_name',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals($contact['id'], $result['id']);
    $this->assertEquals('abc1', $result['values'][$contact['id']]['first_name']);
  }

  /**
   * Test civicrm_contact_get() return only first name & last name.
   *
   * Use comma separated string return with a space.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetReturnFirstLast(int $version): void {
    $this->_apiversion = $version;
    $contact = $this->callAPISuccess('contact', 'create', $this->_params);
    $params = [
      'contact_id' => $contact['id'],
      'return' => 'first_name, last_name',
    ];
    $result = $this->callAPISuccess('contact', 'getsingle', $params);
    $this->assertEquals('abc1', $result['first_name']);
    $this->assertEquals('xyz1', $result['last_name']);
    //check that other defaults not returns
    $this->assertArrayNotHasKey('sort_name', $result);
    $params = [
      'contact_id' => $contact['id'],
      'return' => 'first_name,last_name',
    ];
    $result = $this->callAPISuccess('contact', 'getsingle', $params);
    $this->assertEquals('abc1', $result['first_name']);
    $this->assertEquals('xyz1', $result['last_name']);
    //check that other defaults not returns
    $this->assertArrayNotHasKey('sort_name', $result);
  }

  /**
   * Test civicrm_contact_get() return only first name & last name.
   *
   * Use comma separated string return without a space
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetReturnFirstLastNoComma(int $version): void {
    $this->_apiversion = $version;
    $contact = $this->callAPISuccess('contact', 'create', $this->_params);
    $params = [
      'contact_id' => $contact['id'],
      'return' => 'first_name,last_name',
    ];
    $result = $this->callAPISuccess('contact', 'getsingle', $params);
    $this->assertEquals('abc1', $result['first_name']);
    $this->assertEquals('xyz1', $result['last_name']);
    //check that other defaults not returns
    $this->assertArrayNotHasKey('sort_name', $result);
  }

  /**
   * Test civicrm_contact_get() with default return properties.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetRetDefault(): void {
    $contactID = $this->individualCreate();
    $params = [
      'contact_id' => $contactID,
      'sort' => 'first_name',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals($contactID, $result['values'][$contactID]['contact_id']);
    $this->assertEquals('Anthony', $result['values'][$contactID]['first_name']);
  }

  /**
   * Test civicrm_contact_get) with empty params.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetEmptyParams(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('contact', 'get', []);
  }

  /**
   * Test civicrm_contact_get(,true) with no matches.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetOldParamsNoMatches(int $version): void {
    $this->_apiversion = $version;
    $this->individualCreate();
    $result = $this->callAPISuccess('contact', 'get', ['first_name' => 'Fred']);
    $this->assertEquals(0, $result['count']);
  }

  /**
   * Test civicrm_contact_get(,true) with one match.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetOldParamsOneMatch(): void {
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);

    $result = $this->callAPISuccess('contact', 'get', ['first_name' => 'Test']);
    $this->assertEquals($contactID, $result['values'][$contactID]['contact_id']);
    $this->assertEquals($contactID, $result['id']);
  }

  /**
   * Test civicrm_contact_get(,true) with space in sort_name.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetSpaceMatches(): void {
    $contactParams_1 = [
      'first_name' => 'Sanford',
      'last_name' => 'Blackwell',
      'sort_name' => 'Blackwell, Sanford',
      'contact_type' => 'Individual',
    ];
    $this->individualCreate($contactParams_1);

    $contactParams_2 = [
      'household_name' => 'Blackwell family',
      'sort_name' => 'Blackwell family',
      'contact_type' => 'Household',
    ];
    $this->individualCreate($contactParams_2);

    $result = $this->callAPISuccess('contact', 'get', ['sort_name' => 'Blackwell F']);
    // Since #22060 we expect both results as space is being replaced with wildcard
    $this->assertEquals(2, $result['count']);
  }

  /**
   * Test civicrm_contact_search_count().
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetEmail(): void {
    $params = [
      'email' => 'man2@yahoo.com',
      'contact_type' => 'Individual',
      'location_type_id' => 1,
    ];

    $contact = $this->callAPISuccess('contact', 'create', $params);

    $params = [
      'email' => 'man2@yahoo.com',
    ];
    $result = $this->callAPISuccess('contact', 'get', $params);
    $this->assertEquals('man2@yahoo.com', $result['values'][$result['id']]['email']);

    $this->callAPISuccess('contact', 'delete', $contact);
  }

  /**
   * Ensure consistent return format for option group fields.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testSetPreferredCommunicationNull(int $version): void {
    $this->_apiversion = $version;
    $contact = $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'preferred_communication_method' => ['Phone', 'SMS'],
    ]));
    $preferredCommunicationMethod = $this->callAPISuccessGetValue('Contact', [
      'id' => $contact['id'],
      'return' => 'preferred_communication_method',
    ]);
    $this->assertNotEmpty($preferredCommunicationMethod);
    $contact = $this->callAPISuccess('contact', 'create', array_merge($this->_params, [
      'preferred_communication_method' => 'null',
      'id' => $contact['id'],
    ]));
    $preferredCommunicationMethod = $this->callAPISuccessGetValue('Contact', [
      'id' => $contact['id'],
      'return' => 'preferred_communication_method',
    ]);
    $this->assertEmpty($preferredCommunicationMethod);
  }

  /**
   * Ensure consistent return format for option group fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function testPseudoFields(): void {
    $params = [
      'preferred_communication_method' => ['Phone', 'SMS'],
      'preferred_language' => 'en_US',
      'gender_id' => 'Female',
      'prefix_id' => 'Mrs.',
      'suffix_id' => 'II',
      'communication_style_id' => 'Formal',
    ];

    $contact = $this->callAPISuccess('contact', 'create', array_merge($this->_params, $params));

    $result = $this->callAPISuccess('contact', 'getsingle', ['id' => $contact['id']]);

    $this->assertEquals('en_US', $result['preferred_language']);
    $this->assertEquals(1, $result['communication_style_id']);
    $this->assertEquals(1, $result['gender_id']);
    $this->assertEquals('Female', $result['gender']);
    $this->assertEquals('Mrs.', $result['individual_prefix']);
    $this->assertEquals(1, $result['prefix_id']);
    $this->assertEquals('II', $result['individual_suffix']);
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Contact_BAO_Contact', 'suffix_id', 'II'), $result['suffix_id']);
    $this->callAPISuccess('contact', 'delete', $contact);
    $this->assertEquals([
      CRM_Core_PseudoConstant::getKey('CRM_Contact_BAO_Contact', 'preferred_communication_method', 'Phone'),
      CRM_Core_PseudoConstant::getKey('CRM_Contact_BAO_Contact', 'preferred_communication_method', 'SMS'),
    ], $result['preferred_communication_method']);
  }

  /**
   * Test birth date parameters.
   *
   * These include value, array & birth_date_high, birth_date_low
   * && deceased.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetBirthDate(): void {
    $contact1 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['birth_date' => 'first day of next month - 2 years']));
    $contact2 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['birth_date' => 'first day of  next month - 5 years']));
    $contact3 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['birth_date' => 'first day of next month -20 years']));

    $result = $this->callAPISuccess('contact', 'get', []);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -2 years')), $result['values'][$contact1['id']]['birth_date']);
    $result = $this->callAPISuccess('contact', 'get', ['birth_date' => 'first day of next month -5 years']);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -5 years')), $result['values'][$contact2['id']]['birth_date']);
    $result = $this->callAPISuccess('contact', 'get', ['birth_date_high' => date('Y-m-d', strtotime('-6 years'))]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -20 years')), $result['values'][$contact3['id']]['birth_date']);
    $result = $this->callAPISuccess('contact', 'get', [
      'birth_date_low' => date('Y-m-d', strtotime('-6 years')),
      'birth_date_high' => date('Y-m-d', strtotime('- 3 years')),
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -5 years')), $result['values'][$contact2['id']]['birth_date']);
    $result = $this->callAPISuccess('contact', 'get', [
      'birth_date_low' => '-6 years',
      'birth_date_high' => '- 3 years',
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -5 years')), $result['values'][$contact2['id']]['birth_date']);
  }

  /**
   * Test the greeting fields update sensibly.
   */
  public function testGreetingUpdates(): void {
    $contactID = $this->individualCreate();
    $greetingFields = ['email_greeting_id:name', 'email_greeting_display', 'email_greeting_custom'];
    $currentGreetings = $this->callAPISuccessGetSingle('Contact', ['id' => $contactID, 'version' => 4, 'return' => $greetingFields]);
    $this->assertEquals('Dear {contact.first_name}', $currentGreetings['email_greeting_id:name']);
    // Change to customized greeting.
    $this->callAPISuccess('Contact', 'create', [
      'id' => $contactID,
      'email_greeting_id' => 'Customized',
      'email_greeting_custom' => 'Howdy',
    ]);
    $currentGreetings = $this->callAPISuccessGetSingle('Contact', ['version' => 4, 'id' => $contactID, 'return' => $greetingFields]);
    $this->assertEquals('Customized', $currentGreetings['email_greeting_id:name']);
    $this->assertEquals('Howdy', $currentGreetings['email_greeting_custom']);
    $this->assertEquals('Howdy', $currentGreetings['email_greeting_display']);

    // Change back to standard, check email_greeting_custom set to NULL.
    $this->callAPISuccess('Contact', 'create', [
      'id' => $contactID,
      'email_greeting_id' => 'Dear {contact.first_name}',
    ]);
    $currentGreetings = $this->callAPISuccessGetSingle('Contact', ['id' => $contactID, 'version' => 4, 'return' => $greetingFields]);
    $this->assertNull($currentGreetings['email_greeting_custom']);

    $this->callAPISuccess('Contact', 'create', [
      'id' => $contactID,
      'email_greeting_custom' => 'Howdy',
    ]);
    $currentGreetings = $this->callAPISuccessGetSingle('Contact', ['version' => 4, 'id' => $contactID, 'return' => $greetingFields]);
    $this->assertEquals('Customized', $currentGreetings['email_greeting_id:name']);
    $this->assertEquals('Howdy', $currentGreetings['email_greeting_custom']);
    $this->assertEquals('Howdy', $currentGreetings['email_greeting_display']);
  }

  /**
   * Test Address parameters
   *
   * This include state_province, state_province_name, country
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetWithAddressFields(): void {
    $individuals = [
      [
        'first_name' => 'abc1',
        'contact_type' => 'Individual',
        'last_name' => 'xyz1',
        'api.address.create' => [
          'country' => 'United States',
          'state_province_id' => 'Michigan',
          'location_type_id' => 1,
        ],
      ],
      [
        'first_name' => 'abc2',
        'contact_type' => 'Individual',
        'last_name' => 'xyz2',
        'api.address.create' => [
          'country' => 'United States',
          'state_province_id' => 'Alabama',
          'location_type_id' => 1,
        ],
      ],
    ];
    foreach ($individuals as $params) {
      $contact = $this->callAPISuccess('contact', 'create', $params);
    }

    // Check whether Contact get API return successfully with below Address params.
    $fieldsToTest = [
      'country' => 'United States',
      'state_province_name' => ['IN' => ['Michigan', 'Alabama']],
      'state_province' => ['IN' => ['Michigan', 'Alabama']],
    ];
    foreach ($fieldsToTest as $field => $value) {
      $getParams = [
        'id' => $contact['id'],
        $field => $value,
      ];
      $result = $this->callAPISuccess('Contact', 'get', $getParams);
      $this->assertEquals(1, $result['count']);
    }
  }

  /**
   * Test Deceased date parameters.
   *
   * These include value, array & Deceased_date_high, Deceased date_low
   * && deceased.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetDeceasedDate(): void {
    $contact1 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['deceased_date' => 'first day of next month - 2 years']));
    $contact2 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['deceased_date' => 'first day of  next month - 5 years']));
    $contact3 = $this->callAPISuccess('contact', 'create', array_merge($this->_params, ['deceased_date' => 'first day of next month -20 years']));

    $result = $this->callAPISuccess('contact', 'get', []);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -2 years')), $result['values'][$contact1['id']]['deceased_date']);
    $result = $this->callAPISuccess('contact', 'get', ['deceased_date' => 'first day of next month -5 years']);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -5 years')), $result['values'][$contact2['id']]['deceased_date']);
    $result = $this->callAPISuccess('contact', 'get', ['deceased_date_high' => date('Y-m-d', strtotime('-6 years'))]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -20 years')), $result['values'][$contact3['id']]['deceased_date']);
    $result = $this->callAPISuccess('contact', 'get', [
      'deceased_date_low' => '-6 years',
      'deceased_date_high' => date('Y-m-d', strtotime('- 3 years')),
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(date('Y-m-d', strtotime('first day of next month -5 years')), $result['values'][$contact2['id']]['deceased_date']);
  }

  /**
   * Test for Contact.get id=@user:username.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetByUsername(): void {
    // Setup - create contact with a uf-match.
    $cid = $this->individualCreate([
      'contact_type' => 'Individual',
      'first_name' => 'testGetByUsername',
      'last_name' => 'testGetByUsername',
    ]);

    $ufMatchParams = [
      'domain_id' => CRM_Core_Config::domainID(),
      'uf_id' => 99,
      'uf_name' => 'the-email-matching-key-is-not-really-the-username',
      'contact_id' => $cid,
    ];
    $ufMatch = CRM_Core_BAO_UFMatch::create($ufMatchParams);
    $this->assertIsNumeric($ufMatch->id);

    // setup - mock the calls to CRM_Utils_System_*::getUfId
    $userSystem = $this->getMockBuilder('CRM_Utils_System_UnitTests')->setMethods(['getUfId'])->getMock();
    $userSystem->expects($this->once())
      ->method('getUfId')
      ->with($this->equalTo('exampleUser'))
      ->will($this->returnValue(99));
    CRM_Core_Config::singleton()->userSystem = $userSystem;

    // perform a lookup
    $result = $this->callAPISuccess('Contact', 'get', [
      'id' => '@user:exampleUser',
    ]);
    $this->assertEquals('testGetByUsername', $result['values'][$cid]['first_name']);

    // Check search of contacts with & without uf records
    $result = $this->callAPISuccess('Contact', 'get', ['uf_user' => 1]);
    $this->assertArrayHasKey($cid, $result['values']);

    $result = $this->callAPISuccess('Contact', 'get', ['uf_user' => 0]);
    $this->assertArrayNotHasKey($cid, $result['values']);
  }

  /**
   * Test to check return works OK.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetReturnValues(): void {
    $extraParams = [
      'nick_name' => 'Bob',
      'phone' => '456',
      'email' => 'e@mail.com',
    ];
    $contactID = $this->individualCreate($extraParams);
    //actually it turns out the above doesn't create a phone
    $this->callAPISuccess('phone', 'create', ['contact_id' => $contactID, 'phone' => '456']);
    $result = $this->callAPISuccess('contact', 'getsingle', ['id' => $contactID]);
    foreach ($extraParams as $key => $value) {
      $this->assertEquals($result[$key], $value);
    }
    //now we check they are still returned with 'return' key
    $result = $this->callAPISuccess('contact', 'getsingle', [
      'id' => $contactID,
      'return' => array_keys($extraParams),
    ]);
    foreach ($extraParams as $key => $value) {
      $this->assertEquals($result[$key], $value);
    }
  }

  /**
   * Test creating multiple phones using chaining.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   * @throws \Exception
   */
  public function testCRM13252MultipleChainedPhones(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->householdCreate();
    $this->callAPISuccessGetCount('phone', ['contact_id' => $contactID], 0);
    $params = [
      'contact_id' => $contactID,
      'household_name' => 'Household 1',
      'contact_type' => 'Household',
      'api.phone.create' => [
        0 => [
          'phone' => '111-111-1111',
          'location_type_id' => 1,
          'phone_type_id' => 1,
        ],
        1 => [
          'phone' => '222-222-2222',
          'location_type_id' => 1,
          'phone_type_id' => 2,
        ],
      ],
    ];
    $this->callAPISuccess('contact', 'create', $params);
    $this->callAPISuccessGetCount('phone', ['contact_id' => $contactID], 2);

  }

  /**
   * Test for Contact.get id=@user:username (with an invalid username).
   */
  public function testContactGetByUnknownUsername(): void {
    // setup - mock the calls to CRM_Utils_System_*::getUfId
    $userSystem = $this->getMockBuilder('CRM_Utils_System_UnitTests')->setMethods(['getUfId'])->getMock();
    $userSystem->expects($this->once())
      ->method('getUfId')
      ->with($this->equalTo('exampleUser'))
      ->will($this->returnValue(NULL));
    CRM_Core_Config::singleton()->userSystem = $userSystem;

    // perform a lookup
    $result = $this->callAPIFailure('Contact', 'get', [
      'id' => '@user:exampleUser',
    ]);
    $this->assertMatchesRegularExpression('/cannot be resolved to a contact ID/', $result['error_message']);
  }

  /**
   * Verify attempt to create individual with chained arrays and sequential.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetIndividualWithChainedArraysAndSequential(int $version): void {
    $this->_apiversion = $version;
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $params['custom_' . $ids['custom_field_id']] = 'custom string';

    $moreIDs = $this->CustomGroupMultipleCreateWithFields();
    $params = [
      'sequential' => 1,
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.website.create' => [
        [
          'url' => 'https://civicrm.org',
        ],
        [
          'url' => 'https://civicrm.org',
        ],
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);

    // delete the contact and custom groups
    $this->callAPISuccess('contact', 'delete', ['id' => $result['id']]);
    $this->customGroupDelete($ids['custom_group_id']);
    $this->customGroupDelete($moreIDs['custom_group_id']);

    $this->assertEquals($result['id'], $result['values'][0]['id']);
    $this->assertArrayKeyExists('api.website.create', $result['values'][0]);
  }

  /**
   * Verify attempt to create individual with chained arrays.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetIndividualWithChainedArrays(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $params['custom_' . $ids['custom_field_id']] = 'custom string';

    $moreIDs = $this->CustomGroupMultipleCreateWithFields();
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.contribution.create' => [
        'receive_date' => '2010-01-01',
        'total_amount' => 100.00,
        'financial_type_id' => 1,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 12345,
        'invoice_id' => 67890,
        'source' => 'SSF',
        'contribution_status_id' => 1,
      ],
      'api.contribution.create.1' => [
        'receive_date' => '2011-01-01',
        'total_amount' => 120.00,
        'financial_type_id' => $this->_financialTypeId = 1,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 12335,
        'invoice_id' => 67830,
        'source' => 'SSF',
        'contribution_status_id' => 1,
      ],
      'api.website.create' => [
        [
          'url' => 'https://civicrm.org',
        ],
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);
    $params = [
      'id' => $result['id'],
      'api.website.get' => [],
      'api.Contribution.get' => [
        'total_amount' => '120.00',
      ],
      'api.CustomValue.get' => 1,
      'api.Note.get' => 1,
    ];
    $result = $this->callAPISuccess('Contact', 'Get', $params);
    // delete the contact
    $this->callAPISuccess('contact', 'delete', $result);
    $this->customGroupDelete($ids['custom_group_id']);
    $this->customGroupDelete($moreIDs['custom_group_id']);
    $this->assertEquals(0, $result['values'][$result['id']]['api.website.get']['is_error']);
    $this->assertEquals('https://civicrm.org', $result['values'][$result['id']]['api.website.get']['values'][0]['url']);
  }

  /**
   * Verify attempt to create individual with chained arrays and sequential.
   *
   * @see https://issues.civicrm.org/jira/browse/CRM-15815
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateIndividualWithChainedArrayAndSequential(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'sequential' => 1,
      'first_name' => 'abc5',
      'last_name' => 'xyz5',
      'contact_type' => 'Individual',
      'email' => 'woman5@yahoo.com',
      'api.phone.create' => [
        ['phone' => '03-231 07 95'],
        ['phone' => '03-232 51 62'],
      ],
      'api.website.create' => [
        'url' => 'https://civicrm.org',
      ],
    ];
    $result = $this->callAPISuccess('Contact', 'create', $params);

    // I could try to parse the result to see whether the two phone numbers
    // and the website are there, but I am not sure about the correct format.
    // So I will just fetch it again before checking.
    // See also http://forum.civicrm.org/index.php/topic,35393.0.html
    $params = [
      'sequential' => 1,
      'id' => $result['id'],
      'api.website.get' => [],
      'api.phone.get' => [],
    ];
    $result = $this->callAPISuccess('Contact', 'get', $params);

    // delete the contact
    $this->callAPISuccess('contact', 'delete', $result);

    $this->assertEquals(2, $result['values'][0]['api.phone.get']['count']);
    $this->assertEquals(1, $result['values'][0]['api.website.get']['count']);
  }

  /**
   * Test retrieving an individual with chained array syntax.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetIndividualWithChainedArraysFormats(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $params['custom_' . $ids['custom_field_id']] = 'custom string';

    $moreIDs = $this->CustomGroupMultipleCreateWithFields();
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.contribution.create' => [
        'receive_date' => '2010-01-01',
        'total_amount' => 100.00,
        'financial_type_id' => $this->_financialTypeId,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.contribution.create.1' => [
        'receive_date' => '2011-01-01',
        'total_amount' => 120.00,
        'financial_type_id' => $this->_financialTypeId,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.website.create' => [
        [
          'url' => 'https://civicrm.org',
        ],
      ],
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);
    $params = [
      'id' => $result['id'],
      'api.website.getValue' => ['return' => 'url'],
      'api.Contribution.getCount' => [],
      'api.CustomValue.get' => 1,
      'api.Note.get' => 1,
      'api.Membership.getCount' => [],
    ];
    $result = $this->callAPISuccess('Contact', 'Get', $params);
    $this->assertEquals(2, $result['values'][$result['id']]['api.Contribution.getCount']);
    $this->assertEquals(0, $result['values'][$result['id']]['api.Note.get']['is_error']);
    $this->assertEquals('https://civicrm.org', $result['values'][$result['id']]['api.website.getValue']);

    $this->callAPISuccess('contact', 'delete', $result);
    $this->customGroupDelete($ids['custom_group_id']);
    $this->customGroupDelete($moreIDs['custom_group_id']);
  }

  /**
   * Test complex chaining.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetIndividualWithChainedArraysAndMultipleCustom(): void {
    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $params['custom_' . $ids['custom_field_id']] = 'custom string';
    $moreIDs = $this->CustomGroupMultipleCreateWithFields();
    $andMoreIDs = $this->CustomGroupMultipleCreateWithFields([
      'title' => 'another group',
      'name' => 'another name',
    ]);
    $params = [
      'first_name' => 'abc3',
      'last_name' => 'xyz3',
      'contact_type' => 'Individual',
      'email' => 'man3@yahoo.com',
      'api.contribution.create' => [
        'receive_date' => '2010-01-01',
        'total_amount' => 100.00,
        'financial_type_id' => 1,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 12345,
        'invoice_id' => 67890,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.contribution.create.1' => [
        'receive_date' => '2011-01-01',
        'total_amount' => 120.00,
        'financial_type_id' => 1,
        'payment_instrument_id' => 1,
        'non_deductible_amount' => 10.00,
        'fee_amount' => 50.00,
        'net_amount' => 90.00,
        'trxn_id' => 12335,
        'invoice_id' => 67830,
        'source' => 'SSF',
        'contribution_status_id' => 1,
        'skipCleanMoney' => 1,
      ],
      'api.website.create' => [
        [
          'url' => 'https://civicrm.org',
        ],
      ],
      'custom_' . $ids['custom_field_id'] => 'value 1',
      'custom_' . $moreIDs['custom_field_id'][0] => 'value 2',
      'custom_' . $moreIDs['custom_field_id'][1] => 'warm beer',
      'custom_' . $andMoreIDs['custom_field_id'][1] => 'vegemite',
    ];

    $result = $this->callAPISuccess('Contact', 'create', $params);
    $result = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'id' => $result['id'],
      'custom_' .
      $moreIDs['custom_field_id'][0] => 'value 3',
      'custom_' .
      $ids['custom_field_id'] => 'value 4',
    ]);

    $params = [
      'id' => $result['id'],
      'api.website.getValue' => ['return' => 'url'],
      'api.Contribution.getCount' => [],
      'api.CustomValue.get' => 1,
    ];
    $result = $this->callAPISuccess('Contact', 'Get', $params);

    $this->customGroupDelete($ids['custom_group_id']);
    $this->customGroupDelete($moreIDs['custom_group_id']);
    $this->customGroupDelete($andMoreIDs['custom_group_id']);
    $this->assertEquals(0, $result['values'][$result['id']]['api.CustomValue.get']['is_error']);
    $this->assertEquals('https://civicrm.org', $result['values'][$result['id']]['api.website.getValue']);
  }

  /**
   * Test checks usage of $values to pick & choose inputs.
   *
   * Api3 Only - chaining syntax is too funky for v4 (assuming entityTag
   * "entity_id" field will be filled by magic)
   *
   * @throws \CRM_Core_Exception
   */
  public function testChainingValuesCreate(): void {
    $params = [
      'display_name' => 'batman',
      'contact_type' => 'Individual',
      'api.tag.create' => [
        'name' => '$value.id',
        'description' => '$value.display_name',
        'format.only_id' => 1,
      ],
      'api.entity_tag.create' => ['tag_id' => '$value.api.tag.create'],
    ];
    $result = $this->callAPISuccess('Contact', 'Create', $params);
    $this->assertEquals(0, $result['values'][$result['id']]['api.entity_tag.create']['is_error']);
  }

  /**
   * Test TrueFalse format - I couldn't come up with an easy way to get an error on Get.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetFormatIsSuccessTrue(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);
    $params = ['id' => $contactID, 'format.is_success' => 1];
    $result = $this->callAPISuccess('Contact', 'Get', $params);
    $this->assertEquals(1, $result);
    $this->callAPISuccess('Contact', 'Delete', $params);
  }

  /**
   * Test TrueFalse format.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testContactCreateFormatIsSuccessFalse(int $version): void {
    $this->_apiversion = $version;

    $params = ['id' => 500, 'format.is_success' => 1];
    $result = $this->callAPISuccess('Contact', 'Create', $params);
    $this->assertEquals(0, $result);
  }

  /**
   * Test long display names.
   *
   * @see https://issues.civicrm.org/jira/browse/CRM-21258
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactCreateLongDisplayName(int $version): void {
    $this->_apiversion = $version;
    $result = $this->callAPISuccess('Contact', 'Create', [
      'first_name' => str_pad('a', 64, 'a'),
      'last_name' => str_pad('a', 64, 'a'),
      'contact_type' => 'Individual',
    ]);
    $this->assertEquals('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $result['values'][$result['id']]['display_name']);
    $this->assertEquals('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa, aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $result['values'][$result['id']]['sort_name']);
  }

  /**
   * Test that we can set the sort name via the api or alter it via a hook.
   *
   * As of writing this is being fixed for Organization & Household but it makes sense to do for individuals too.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testCreateAlterSortName(int $version): void {
    $this->_apiversion = $version;
    $organizationID = $this->organizationCreate(['organization_name' => 'The Justice League', 'sort_name' => 'Justice League, The']);
    $organization = $this->callAPISuccessGetSingle('Contact', ['return' => ['sort_name', 'display_name'], 'id' => $organizationID]);
    $this->assertEquals('Justice League, The', $organization['sort_name']);
    $this->assertEquals('The Justice League', $organization['display_name']);
    $this->hookClass->setHook('civicrm_pre', [$this, 'killTheJusticeLeague']);
    $this->organizationCreate(['id' => $organizationID, 'sort_name' => 'Justice League, The']);
    $organization = $this->callAPISuccessGetSingle('Contact', ['return' => ['sort_name', 'display_name', 'is_deceased'], 'id' => $organizationID]);
    $this->assertEquals('Steppenwolf wuz here', $organization['display_name']);
    $this->assertEquals('Steppenwolf wuz here', $organization['sort_name']);
    $this->assertEquals(1, $organization['is_deceased']);

    $householdID = $this->householdCreate();
    $household = $this->callAPISuccessGetSingle('Contact', ['return' => ['sort_name', 'display_name'], 'id' => $householdID]);
    $this->assertEquals('Steppenwolf wuz here', $household['display_name']);
    $this->assertEquals('Steppenwolf wuz here', $household['sort_name']);
  }

  /**
   * Implements hook_pre().
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function killTheJusticeLeague($op, $entity, $id, &$params): void {
    $params['sort_name'] = 'Steppenwolf wuz here';
    $params['display_name'] = 'Steppenwolf wuz here';
    $params['is_deceased'] = 1;
  }

  /**
   * Test Single Entity format.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetSingleEntityArray(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);
    $result = $this->callAPISuccess('Contact', 'GetSingle', ['id' => $contactID]);
    $this->assertEquals('Mr. Test Contact II', $result['display_name']);
    $this->callAPISuccess('Contact', 'Delete', ['id' => $contactID]);
  }

  /**
   * Test Single Entity format.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetFormatCountOnly(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);
    $params = ['id' => $contactID];
    $result = $this->callAPISuccess('Contact', 'GetCount', $params);
    $this->assertEquals('1', $result);
    $this->callAPISuccess('Contact', 'Delete', $params);
  }

  /**
   * Test id only format.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetFormatIDOnly(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);
    $params = ['id' => $contactID, 'format.only_id' => 1];
    $result = $this->callAPISuccess('Contact', 'Get', $params);
    $this->assertEquals($contactID, $result);
    $this->callAPISuccess('Contact', 'Delete', $params);
  }

  /**
   * Test id only format.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactGetFormatSingleValue(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate(['first_name' => 'Test', 'last_name' => 'Contact']);
    $params = ['id' => $contactID, 'return' => 'display_name'];
    $result = $this->callAPISuccess('Contact', 'getvalue', $params);
    $this->assertEquals('Mr. Test Contact II', $result);
    $this->callAPISuccess('Contact', 'Delete', $params);
  }

  /**
   * Test that permissions are respected when creating contacts.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactCreationPermissions(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'contact_type' => 'Individual',
      'first_name' => 'Foo',
      'last_name' => 'Bear',
      'check_permissions' => TRUE,
    ];
    $config = CRM_Core_Config::singleton();
    $config->userPermissionClass->permissions = ['access CiviCRM'];
    $result = $this->callAPIFailure('contact', 'create', $params);
    $this->assertStringContainsString('failed', $result['error_message'], 'lacking permissions should not be enough to create a contact');

    $config->userPermissionClass->permissions = ['access CiviCRM', 'add contacts', 'import contacts'];
    $this->callAPISuccess('contact', 'create', $params);
  }

  /**
   * Test that delete with skip undelete respects permissions.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactDeletePermissions(int $version): void {
    $this->_apiversion = $version;
    $contactID = $this->individualCreate();
    $this->quickCleanup(['civicrm_entity_tag', 'civicrm_tag']);
    $tag = $this->callAPISuccess('Tag', 'create', ['name' => uniqid('to be deleted')]);
    $this->callAPISuccess('EntityTag', 'create', ['entity_id' => $contactID, 'tag_id' => $tag['id']]);
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->callAPIFailure('Contact', 'delete', [
      'id' => $contactID,
      'check_permissions' => 1,
      'skip_undelete' => 1,
    ]);
    $this->callAPISuccessGetCount('EntityTag', ['entity_id' => $contactID], 1);
    $this->callAPISuccess('Contact', 'delete', [
      'id' => $contactID,
      'check_permissions' => 0,
      'skip_undelete' => 1,
    ]);
    $this->callAPISuccessGetCount('EntityTag', ['entity_id' => $contactID], 0);
    $this->quickCleanup(['civicrm_entity_tag', 'civicrm_tag']);
  }

  /**
   * Test update with check permissions set.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testContactUpdatePermissions(int $version): void {
    $this->_apiversion = $version;
    $params = [
      'contact_type' => 'Individual',
      'first_name' => 'Foo',
      'last_name' => 'Bear',
      'check_permissions' => TRUE,
    ];
    $result = $this->callAPISuccess('contact', 'create', $params);
    $config = CRM_Core_Config::singleton();
    $params = [
      'id' => $result['id'],
      'contact_type' => 'Individual',
      'last_name' => 'Bar',
      'check_permissions' => TRUE,
    ];

    $config->userPermissionClass->permissions = ['access CiviCRM'];
    $result = $this->callAPIFailure('contact', 'update', $params);
    if ($version === 3) {
      $this->assertEquals('Permission denied to modify contact record', $result['error_message']);
    }
    else {
      $this->assertEquals('ACL check failed', $result['error_message']);
    }

    $config->userPermissionClass->permissions = [
      'access CiviCRM',
      'add contacts',
      'view all contacts',
      'edit all contacts',
      'import contacts',
    ];
    $this->callAPISuccess('contact', 'update', $params);
  }

  /**
   * Test contact proximity api.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactProximity(): void {
    // first create a contact with a SF location with a specific
    // geocode
    $contactID = $this->organizationCreate();

    // now create the address
    $params = [
      'street_address' => '123 Main Street',
      'city' => 'San Francisco',
      'is_primary' => 1,
      'country_id' => 1228,
      'state_province_id' => 1004,
      'geo_code_1' => '37.79',
      'geo_code_2' => '-122.40',
      'location_type_id' => 1,
      'contact_id' => $contactID,
    ];

    $result = $this->callAPISuccess('address', 'create', $params);
    $this->assertEquals(1, $result['count']);

    // now do a proximity search with a close enough geocode and hope to match
    // that specific contact only!
    $proxParams = [
      'latitude' => 37.7,
      'longitude' => -122.3,
      'unit' => 'mile',
      'distance' => 10,
    ];
    $result = $this->callAPISuccess('contact', 'proximity', $proxParams);
    $this->assertEquals(1, $result['count']);
  }

  public function getSearchSortOptions(): array {
    $firstAlphabeticalContactBySortName = 'A Bobby, Bobby';
    $secondAlphabeticalContactBySortName = 'Aardvark, Bob';
    $secondAlphabeticalContactWithEmailBySortName = 'Bob, Bob';
    $firstAlphabeticalContactFirstNameBob = 'Aardvark, Bob';
    $secondAlphabeticalContactFirstNameBob = 'Bob, Bob';
    $bobLikeEmail = 'A Bobby, Bobby';

    return [
      'empty_search_basic' => [
        'search_parameters' => ['name' => '%'],
        'settings' => ['includeWildCardInName' => TRUE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactBySortName,
        'second_contact' => $secondAlphabeticalContactBySortName,
      ],
      'empty_search_basic_no_wildcard' => [
        'search_parameters' => ['name' => '%'],
        'settings' => ['includeWildCardInName' => FALSE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactBySortName,
        'second_contact' => $secondAlphabeticalContactBySortName,
      ],
      'single_letter_search_basic' => [
        'search_parameters' => ['name' => 'b'],
        'settings' => ['includeWildCardInName' => TRUE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactBySortName,
        'second_contact' => $secondAlphabeticalContactBySortName,
      ],
      'bob_search_basic' => [
        'search_parameters' => ['name' => 'bob'],
        'settings' => ['includeWildCardInName' => TRUE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactBySortName,
        'second_contact' => $secondAlphabeticalContactBySortName,
      ],
      // This test has been disabled as is proving to be problematic to reproduce due to MySQL sorting issues between different versions
      // 'bob_search_no_order_by' => array(
      //  'search_parameters' => array('name' => 'bob'),
      //  'settings' => array('includeWildCardInName' => TRUE, 'includeOrderByClause' => FALSE),
      //  'first_contact' => $firstContactByID,
      //  'second_contact' => $secondContactByID,
      //),
      'bob_search_no_wildcard' => [
        'search_parameters' => ['name' => 'bob'],
        'settings' => ['includeWildCardInName' => FALSE, 'includeOrderByClause' => TRUE],
        'second_contact' => $bobLikeEmail,
        'first_contact' => $secondAlphabeticalContactFirstNameBob,
      ],
      // This should be the same as just no wildcard as if we had an exactMatch while searching by
      // sort name it would rise to the top CRM-19547
      'bob_search_no_wildcard_no_order_by' => [
        'search_parameters' => ['name' => 'bob'],
        'settings' => ['includeWildCardInName' => FALSE, 'includeOrderByClause' => TRUE],
        'second_contact' => $bobLikeEmail,
        'first_contact' => $secondAlphabeticalContactFirstNameBob,
      ],
      'first_name_search_basic' => [
        'search_parameters' => ['name' => 'bob', 'field_name' => 'first_name'],
        'settings' => ['includeWildCardInName' => TRUE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactFirstNameBob,
        'second_contact' => $secondAlphabeticalContactFirstNameBob,
      ],
      'first_name_search_no_wildcard' => [
        'search_parameters' => ['name' => 'bob', 'field_name' => 'first_name'],
        'settings' => ['includeWildCardInName' => FALSE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactFirstNameBob,
        'second_contact' => $secondAlphabeticalContactFirstNameBob,
      ],
      // This test has been disabled as is proving to be problematic to reproduce due to MySQL sorting issues between different versions
      //'first_name_search_no_order_by' => array(
      //  'search_parameters' => array('name' => 'bob', 'field_name' => 'first_name'),
      //  'settings' => array('includeWildCardInName' => TRUE, 'includeOrderByClause' => FALSE),
      //  'first_contact' => $firstByIDContactFirstNameBob,
      //  'second_contact' => $secondByIDContactFirstNameBob,
      //),
      'email_search_basic' => [
        'search_parameters' => ['name' => 'bob', 'field_name' => 'email', 'table_name' => 'eml'],
        'settings' => ['includeWildCardInName' => FALSE, 'includeOrderByClause' => TRUE],
        'first_contact' => $firstAlphabeticalContactBySortName,
        'second_contact' => $secondAlphabeticalContactWithEmailBySortName,
      ],
    ];
  }

  /**
   * Full results returned.
   *
   * @implements CRM_Utils_Hook::aclWhereClause
   *
   * @param string $type
   * @param array $tables
   * @param array $whereTables
   * @param int $contactID
   * @param string|null $where
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function aclWhereNoBobH(string $type, array &$tables, array &$whereTables, int &$contactID, ?string &$where): void {
    $where = " (email <> 'bob@h.com' OR email IS NULL) ";
    $whereTables['civicrm_email'] = 'LEFT JOIN civicrm_email e ON contact_a.id = e.contact_id';
  }

  /**
   * Test get ref api - gets a list of references to an entity.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetReferenceCounts(): void {
    $result = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Testily',
      'last_name' => 'McHaste',
      'contact_type' => 'Individual',
      'api.Address.replace' => [
        'values' => [],
      ],
      'api.Email.replace' => [
        'values' => [
          [
            'email' => 'spam@dev.null',
            'is_primary' => 0,
            'location_type_id' => 1,
          ],
        ],
      ],
      'api.Phone.replace' => [
        'values' => [
          [
            'phone' => '234-567-0001',
            'is_primary' => 1,
            'location_type_id' => 1,
          ],
          [
            'phone' => '234-567-0002',
            'is_primary' => 0,
            'location_type_id' => 1,
          ],
        ],
      ],
    ]);
    foreach ([1, 2, 3] as $num) {
      $this->callAPISuccess('EntityTag', 'create', [
        'entity_table' => 'civicrm_contact',
        'entity_id' => $result['id'],
        'tag_id' => $this->tagCreate(['name' => "taggy $num"])['id'],
      ]);
    }

    //$dao = new CRM_Contact_BAO_Contact();
    //$dao->id = $result['id'];
    //$this->assertTrue((bool) $dao->find(TRUE));
    //
    //$refCounts = $dao->getReferenceCounts();
    //$this->assertTrue(is_array($refCounts));
    //$refCountsIdx = CRM_Utils_Array::index(array('name'), $refCounts);

    $refCounts = $this->callAPISuccess('Contact', 'getrefcount', [
      'id' => $result['id'],
    ]);
    $refCountsIdx = CRM_Utils_Array::index(['name'], $refCounts['values']);

    $this->assertEquals(1, $refCountsIdx['sql:civicrm_email:contact_id']['count']);
    $this->assertEquals('civicrm_email', $refCountsIdx['sql:civicrm_email:contact_id']['table']);
    $this->assertEquals(2, $refCountsIdx['sql:civicrm_phone:contact_id']['count']);
    $this->assertEquals('civicrm_phone', $refCountsIdx['sql:civicrm_phone:contact_id']['table']);
    $this->assertEquals(3, $refCountsIdx['sql:civicrm_entity_tag:entity_id']['count']);
    $this->assertEquals('civicrm_entity_tag', $refCountsIdx['sql:civicrm_entity_tag:entity_id']['table']);
    $this->assertNotTrue(isset($refCountsIdx['sql:civicrm_address:contact_id']));
  }

  /**
   * Test the use of sql operators.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testSQLOperatorsOnContactAPI(int $version): void {
    $this->_apiversion = $version;
    $this->individualCreate();
    $this->organizationCreate();
    $this->householdCreate();
    $contacts = $this->callAPISuccess('contact', 'get', ['legal_name' => ['IS NOT NULL' => TRUE]]);
    $this->assertEquals($contacts['count'], CRM_Core_DAO::singleValueQuery('select count(*) FROM civicrm_contact WHERE legal_name IS NOT NULL'));
    $contacts = $this->callAPISuccess('contact', 'get', ['legal_name' => ['IS NULL' => TRUE]]);
    $this->assertEquals($contacts['count'], CRM_Core_DAO::singleValueQuery('select count(*) FROM civicrm_contact WHERE legal_name IS NULL'));
  }

  /**
   * CRM-14743 - test api respects search operators.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetModifiedDateByOperators(int $version): void {
    $this->_apiversion = $version;
    $preExistingContactCount = CRM_Core_DAO::singleValueQuery('select count(*) FROM civicrm_contact');
    $contact1 = $this->individualCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-01-01', modified_date = '2013-01-01' WHERE id = " . $contact1;
    CRM_Core_DAO::executeQuery($sql);
    $contact2 = $this->individualCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-02-01', modified_date = '2013-02-01' WHERE id = " . $contact2;
    CRM_Core_DAO::executeQuery($sql);
    $contact3 = $this->householdCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-03-01', modified_date = '2013-03-01' WHERE id = " . $contact3;
    CRM_Core_DAO::executeQuery($sql);
    $contacts = $this->callAPISuccess('contact', 'get', ['modified_date' => ['<' => '2014-01-01']]);
    $this->assertEquals(3, $contacts['count']);
    $contacts = $this->callAPISuccess('contact', 'get', ['modified_date' => ['>' => '2014-01-01']]);
    $this->assertEquals($contacts['count'], $preExistingContactCount);
  }

  /**
   * CRM-14743 - test api respects search operators.
   *
   * @param int $version
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionThreeAndFour
   */
  public function testGetCreatedDateByOperators(int $version): void {
    $this->_apiversion = $version;
    $preExistingContactCount = CRM_Core_DAO::singleValueQuery('select count(*) FROM civicrm_contact');
    $contact1 = $this->individualCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-01-01' WHERE id = " . $contact1;
    CRM_Core_DAO::executeQuery($sql);
    $contact2 = $this->individualCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-02-01' WHERE id = " . $contact2;
    CRM_Core_DAO::executeQuery($sql);
    $contact3 = $this->householdCreate();
    $sql = "UPDATE civicrm_contact SET created_date = '2012-03-01' WHERE id = " . $contact3;
    CRM_Core_DAO::executeQuery($sql);
    $contacts = $this->callAPISuccess('contact', 'get', ['created_date' => ['<' => '2014-01-01']]);
    $this->assertEquals(3, $contacts['count']);
    $contacts = $this->callAPISuccess('contact', 'get', ['created_date' => ['>' => '2014-01-01']]);
    $this->assertEquals($contacts['count'], $preExistingContactCount);
  }

  /**
   * CRM-14263 check that API is not affected by search profile related bug.
   *
   * @throws \CRM_Core_Exception
   */
  public function testReturnCityProfile(): void {
    $contactID = $this->individualCreate();
    Civi::settings()->set('defaultSearchProfileID', 1);
    $this->callAPISuccess('address', 'create', [
      'contact_id' => $contactID,
      'city' => 'Cool City',
      'location_type_id' => 1,
    ]);
    $result = $this->callAPISuccess('contact', 'get', ['city' => 'Cool City', 'return' => 'contact_type']);
    $this->assertEquals(1, $result['count']);
  }

  /**
   * CRM-15443 - ensure getlist api does not return deleted contacts.
   */
  public function testGetlistExcludeConditions(): void {
    $name = 'Scarabée';
    $contact = $this->individualCreate(['last_name' => $name]);
    $this->individualCreate(['last_name' => $name, 'is_deceased' => 1]);
    $this->individualCreate(['last_name' => $name, 'is_deleted' => 1]);
    // We should get all but the deleted contact.
    $result = $this->callAPISuccess('contact', 'getlist', ['input' => $name]);
    $this->assertEquals(2, $result['count']);
    // Force-exclude the deceased contact.
    $result = $this->callAPISuccess('contact', 'getlist', [
      'input' => $name,
      'params' => ['is_deceased' => 0],
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals($contact, $result['values'][0]['id']);
  }

  /**
   * Test getlist gets an organization with a number that is not a valid organization ID.
   */
  public function testGetlistInvalidID(): void {
    $individualID = $this->individualCreate();
    $organizationID = $this->organizationCreate(['organization_name' => 'Org ' . $individualID]);
    $organizationID2 = $this->organizationCreate(['organization_name' => 'Org ' . $organizationID]);
    $result = $this->callAPISuccess('Contact', 'getlist', ['input' => $individualID, 'params' => ['contact_type' => 'Organization']]);
    $this->assertEquals(1, $result['count']);
    $result = $this->callAPISuccess('Contact', 'getlist', ['input' => $organizationID, 'params' => ['contact_type' => 'Organization']]);
    $this->assertEquals(2, $result['count']);
    $this->assertEquals($organizationID, $result['values'][0]['id']);
    $this->assertEquals($organizationID2, $result['values'][1]['id']);

    $result = $this->callAPISuccess('Contact', 'getlist', ['input' => $organizationID2, 'params' => ['contact_type' => 'Organization']]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals($organizationID2, $result['values'][0]['id']);
  }

  /**
   * Test contact getactions.
   */
  public function testGetActions(): void {
    $result = $this->callAPISuccess($this->_entity, 'getactions', []);
    $expected = [
      'create',
      'delete',
      'get',
      'getactions',
      'getcount',
      'getfields',
      'getlist',
      'getoptions',
      'getrefcount',
      'getsingle',
      'getvalue',
      'merge',
      'proximity',
      'replace',
      'setvalue',
      'update',
    ];
    $deprecated = [
      'update',
    ];
    foreach ($expected as $action) {
      $this->assertContains($action, $result['values'], "Expected action $action");
    }
    foreach ($deprecated as $action) {
      $this->assertArrayKeyExists($action, $result['deprecated']);
    }
  }

  /**
   * Test the duplicate check function.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDuplicateCheck(): void {
    $harry = [
      'first_name' => 'Harry',
      'last_name' => 'Potter',
      'email' => 'harry@hogwarts.edu',
      'contact_type' => 'Individual',
    ];
    $this->callAPISuccess('Contact', 'create', $harry);
    $result = $this->callAPISuccess('Contact', 'duplicatecheck', [
      'match' => $harry,
    ]);

    $this->assertEquals(1, $result['count']);
    $result = $this->callAPISuccess('Contact', 'duplicatecheck', [
      'match' => [
        'first_name' => 'Harry',
        'last_name' => 'Potter',
        'email' => 'no5@privet.drive',
        'contact_type' => 'Individual',
      ],
    ]);
    $this->assertEquals(0, $result['count']);
    $this->callAPIFailure('Contact', 'create', array_merge($harry, ['dupe_check' => 1]));
  }

  /**
   * Test that duplicates can be found even when phone type is specified.
   *
   * @param string $phoneKey
   *
   * @throws \CRM_Core_Exception
   * @dataProvider getPhoneStrings
   *
   */
  public function testGetMatchesPhoneWithType(string $phoneKey): void {
    $ruleGroup = $this->createRuleGroup();
    $this->callAPISuccess('Rule', 'create', [
      'dedupe_rule_group_id' => $ruleGroup['id'],
      'rule_table' => 'civicrm_phone',
      'rule_field' => 'phone_numeric',
      'rule_weight' => 8,
    ]);
    $contact1 = $this->individualCreate(['api.Phone.create' => ['phone' => 123]]);
    $dedupeParams = [
      $phoneKey => '123',
      'contact_type' => 'Individual',
    ];
    $dupes = $this->callAPISuccess('Contact', 'duplicatecheck', [
      'dedupe_rule_id' => $ruleGroup['id'],
      'match' => $dedupeParams,
    ])['values'];
    $this->assertEquals([$contact1 => ['id' => $contact1]], $dupes);
  }

  /**
   * @return array
   */
  public static function getPhoneStrings(): array {
    return [
      ['phone-Primary-1'],
      ['phone-Primary'],
      ['phone-3-1'],
    ];
  }

  /**
   * Test the duplicate check function.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDuplicateCheckRuleNotReserved(): void {
    $harry = [
      'first_name' => 'Harry',
      'last_name' => 'Potter',
      'email' => 'harry@hogwarts.edu',
      'contact_type' => 'Individual',
    ];
    $defaultRule = $this->callAPISuccess('RuleGroup', 'getsingle', ['used' => 'Unsupervised', 'is_reserved' => 1]);
    $this->callAPISuccess('RuleGroup', 'create', ['id' => $defaultRule['id'], 'is_reserved' => 0]);
    $this->callAPISuccess('Contact', 'create', $harry);
    $result = $this->callAPISuccess('Contact', 'duplicatecheck', [
      'match' => $harry,
    ]);

    $this->assertEquals(1, $result['count']);
    $this->callAPISuccess('RuleGroup', 'create', ['id' => $defaultRule['id'], 'is_reserved' => 1]);
  }

  /**
   * Test variants on retrieving contact by type.
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetByContactType(): void {
    $individual = $this->callAPISuccess('Contact', 'create', [
      'email' => 'individual@test.com',
      'contact_type' => 'Individual',
    ]);
    $household = $this->callAPISuccess('Contact', 'create', [
      'household_name' => 'household@test.com',
      'contact_type' => 'Household',
    ]);
    $organization = $this->callAPISuccess('Contact', 'create', [
      'organization_name' => 'organization@test.com',
      'contact_type' => 'Organization',
    ]);
    // Test with id - getsingle will throw an exception if not found
    $this->callAPISuccess('Contact', 'getsingle', [
      'id' => $individual['id'],
      'contact_type' => 'Individual',
    ]);
    $this->callAPISuccess('Contact', 'getsingle', [
      'id' => $individual['id'],
      'contact_type' => ['IN' => ['Individual']],
      'return' => 'id',
    ]);
    $this->callAPISuccess('Contact', 'getsingle', [
      'id' => $organization['id'],
      'contact_type' => ['IN' => ['Individual', 'Organization']],
    ]);
    // Test as array
    $result = $this->callAPISuccess('Contact', 'get', [
      'contact_type' => ['IN' => ['Individual', 'Organization']],
      'options' => ['limit' => 0],
      'return' => 'id',
    ]);
    $this->assertContains($organization['id'], array_keys($result['values']));
    $this->assertContains($individual['id'], array_keys($result['values']));
    $this->assertNotContains($household['id'], array_keys($result['values']));
    // Test as string
    $result = $this->callAPISuccess('Contact', 'get', [
      'contact_type' => 'Household',
      'options' => ['limit' => 0],
      'return' => 'id',
    ]);
    $this->assertNotContains($organization['id'], array_keys($result['values']));
    $this->assertNotContains($individual['id'], array_keys($result['values']));
    $this->assertContains($household['id'], array_keys($result['values']));
  }

  /**
   * Test merging 2 contacts.
   *
   * Someone kindly bequeathed us the legacy of mixed up use of main_id &
   * other_id in the params for contact.merge api.
   *
   * This test protects that legacy.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergeBizarreOldParams(): void {
    $this->createLoggedInUser();
    $otherContact = $this->callAPISuccess('contact', 'create', $this->_params);
    $mainContact = $this->callAPISuccess('contact', 'create', $this->_params);
    $this->callAPISuccess('contact', 'merge', [
      'main_id' => $mainContact['id'],
      'other_id' => $otherContact['id'],
    ]);
    $contacts = $this->callAPISuccess('contact', 'get', $this->_params);
    $this->assertEquals($otherContact['id'], $contacts['id']);
  }

  /**
   * Test merging 2 contacts.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMerge(): void {
    $this->createLoggedInUser();
    $this->ids['contact'][0] = $this->callAPISuccess('Contact', 'create', $this->_params)['id'];
    $this->ids['contact'][1] = $this->callAPISuccess('Contact', 'create', $this->_params)['id'];
    $retainedContact = $this->doMerge();

    $activity = $this->callAPISuccess('Activity', 'getsingle', [
      'target_contact_id' => $retainedContact['id'],
      'activity_type_id' => 'Contact Merged',
    ]);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($activity['activity_date_time'])));
    $activity2 = $this->callAPISuccess('Activity', 'getsingle', [
      'target_contact_id' => $this->ids['contact'][1],
      'activity_type_id' => 'Contact Deleted by Merge',
    ]);
    $this->assertEquals($activity['id'], $activity2['parent_id']);
    $this->assertEquals('Normal', civicrm_api3('option_value', 'getvalue', [
      'value' => $activity['priority_id'],
      'return' => 'label',
      'option_group_id' => 'priority',
    ]));

  }

  /**
   * Test that a blank location does not overwrite a location with data.
   *
   * This is a poor data edge case where a contact has an address record with
   * no meaningful data. This record should be removed in favour of the one
   * with data.
   *
   * @dataProvider  getBooleanDataProvider
   *
   * @param bool $isReverse
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergeWithBlankLocationData(bool $isReverse): void {
    $this->createLoggedInUser();
    $this->ids['contact'][0] = $this->callAPISuccess('contact', 'create', $this->_params)['id'];
    $this->ids['contact'][1] = $this->callAPISuccess('contact', 'create', $this->_params)['id'];
    $contactIDWithBlankAddress = ($isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0]);
    $contactIDWithoutBlankAddress = ($isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1]);
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $contactIDWithBlankAddress,
      'location_type_id' => 1,
    ]);
    $this->callAPISuccess('Address', 'create', [
      'country_id' => 'MX',
      'contact_id' => $contactIDWithoutBlankAddress,
      'street_address' => 'First on the left after you cross the border',
      'postal_code' => 90210,
      'location_type_id' => 1,
    ]);

    $contact = $this->doMerge($isReverse);
    $this->assertEquals('Mexico', $contact['country']);
    $this->assertEquals('90210', $contact['postal_code']);
    $this->assertEquals('First on the left after you cross the border', $contact['street_address']);
  }

  /**
   * Test merging 2 contacts with custom fields.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testMergeCustomFields(): void {
    $contact1 = $this->individualCreate();
    // Not sure this is quite right but it does get it into the file table
    $file = $this->callAPISuccess('Attachment', 'create', [
      'name' => 'header.txt',
      'mime_type' => 'text/plain',
      'description' => 'My test description',
      'content' => 'My test content',
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contact1,
    ]);

    $this->createCustomGroupWithFieldsOfAllTypes();
    $fileField = $this->getCustomFieldName('file');
    $linkField = $this->getCustomFieldName('link');
    $dateField = $this->getCustomFieldName('select_date');
    $selectField = $this->getCustomFieldName('select_string');
    $countryField = $this->getCustomFieldName('country');
    $multiCountryField = $this->getCustomFieldName('multi_country');
    $referenceField = $this->getCustomFieldName('contact_reference');
    $stateField = $this->getCustomFieldName('state');
    $multiStateField = $this->getCustomFieldName('multi_state');
    $booleanStateField = $this->getCustomFieldName('boolean');

    $countriesByName = array_flip(CRM_Core_PseudoConstant::country(FALSE, FALSE));
    $statesByName = array_flip(CRM_Core_PseudoConstant::stateProvince(FALSE, FALSE));
    $customFieldValues = [
      $fileField => $file['id'],
      $linkField => 'https://example.org',
      $dateField => '2018-01-01 17:10:56',
      $selectField => 'G',
      $countryField => $countriesByName['New Zealand'],
      $multiCountryField => [$countriesByName['New Zealand'], $countriesByName['Australia']],
      $referenceField => $this->householdCreate(),
      $stateField => $statesByName['Victoria'],
      $multiStateField => [$statesByName['Victoria'], $statesByName['Tasmania']],
      $booleanStateField => 1,
    ];
    $this->callAPISuccess('Contact', 'create', array_merge([
      'id' => $contact1,
    ], $customFieldValues));

    $contact2 = $this->individualCreate();
    $this->callAPISuccess('contact', 'merge', [
      'to_keep_id' => $contact2,
      'to_remove_id' => $contact1,
      'auto_flip' => FALSE,
    ]);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact2, 'return' => array_keys($customFieldValues)]);
    $this->assertEquals($contact2, CRM_Core_DAO::singleValueQuery('SELECT entity_id FROM civicrm_entity_file WHERE file_id = ' . $file['id']));
    foreach ($customFieldValues as $key => $value) {
      $this->assertEquals($value, $contact[$key]);
    }
  }

  /**
   * Test merging a contact that is the target of a contact reference field on another contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergeContactReferenceCustomFieldTarget(): void {
    $this->createCustomGroupWithFieldOfType([], 'contact_reference');
    $contact1 = $this->individualCreate();
    $contact2 = $this->individualCreate();
    $contact3 = $this->individualCreate([$this->getCustomFieldName('contact_reference') => $contact2]);
    $this->callAPISuccess('contact', 'merge', [
      'to_keep_id' => $contact1,
      'to_remove_id' => $contact2,
      'auto_flip' => FALSE,
    ]);
    $this->assertEquals($contact1, $this->callAPISuccessGetValue('Contact', ['id' => $contact3, 'return' => $this->getCustomFieldName('contact_reference')]));
  }

  /**
   * Test merging a contact that is the target of a contact reference field on another contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergeMultiContactReferenceCustomFieldTarget(): void {
    $this->createCustomGroupWithFieldOfType([], 'contact_reference', NULL, ['serialize' => 1]);
    $fieldName = $this->getCustomFieldName('contact_reference');
    $contact1 = $this->individualCreate();
    $refA = $this->individualCreate();
    $refB = $this->individualCreate();
    $contact2 = $this->individualCreate([$fieldName => [$refA, $refB]]);
    $contact3 = $this->individualCreate([$fieldName => $refA]);
    $this->callAPISuccess('contact', 'merge', [
      'to_keep_id' => $contact1,
      'to_remove_id' => $refA,
      'auto_flip' => FALSE,
    ]);
    $result2 = $this->callAPISuccessGetValue('Contact', ['id' => $contact2, 'return' => $fieldName]);
    $this->assertEquals([$contact1, $refB], $result2);
    $result3 = $this->callAPISuccessGetValue('Contact', ['id' => $contact3, 'return' => $fieldName]);
    $this->assertEquals([$contact1], $result3);
  }

  /**
   * Test merging when a multiple record set is in use.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testMergeMultipleCustomValues(): void {
    $customGroupID = $this->createCustomGroup(['is_multiple' => TRUE]);
    $this->ids['CustomField']['text'] = (int) $this->createTextCustomField(['custom_group_id' => $customGroupID])['id'];
    $contact1 = $this->individualCreate([$this->getCustomFieldName('text') => 'blah']);
    $contact2 = $this->individualCreate([$this->getCustomFieldName('text') => 'de blah']);
    $this->callAPISuccess('contact', 'merge', [
      'to_keep_id' => $contact1,
      'to_remove_id' => $contact2,
      'auto_flip' => FALSE,
    ]);
    $column = $this->getCustomFieldColumnName('text');
    $table = $this->getCustomGroupTable();
    $this->assertEquals('blah,de blah', CRM_Core_DAO::singleValueQuery(
      "SELECT GROUP_CONCAT($column) FROM $table WHERE entity_id = $contact1"
    ));
  }

  /**
   * Test retrieving merged contacts.
   *
   * The goal here is to start with a contact deleted by merged and find out the contact that is the current version of them.
   */
  public function testMergedGet(): void {
    $contactIDs[0] = $this->individualCreate();
    $contactIDs[1] = $this->individualCreate();
    $contactIDs[2] = $this->individualCreate();
    $contactIDs[3] = $this->individualCreate();

    // First do an 'unnatural merge' - they 'like to merge into the lowest but this will mean that contact 0 merged to contact [3].
    // When the batch merge runs.... the new lowest contact is contact[1]. All contacts will merge into that contact,
    // including contact[3], resulting in only 3 existing at the end. For each contact the correct answer to 'who did I eventually
    // wind up being should be [1]
    $this->callAPISuccess('Contact', 'merge', ['to_remove_id' => $contactIDs[0], 'to_keep_id' => $contactIDs[3]]);

    $this->callAPISuccess('Job', 'process_batch_merge', []);
    foreach ($contactIDs as $contactID) {
      if ($contactID === $contactIDs[1]) {
        continue;
      }
      $result = $this->callAPISuccess('Contact', 'getmergedto', ['sequential' => 1, 'contact_id' => $contactID]);
      $this->assertEquals(1, $result['count']);
      $this->assertEquals($contactIDs[1], $result['values'][0]['id']);
    }

    $result = $this->callAPISuccess('Contact', 'getmergedfrom', ['contact_id' => $contactIDs[1]])['values'];
    $mergedContactIDs = array_merge(array_diff($contactIDs, [$contactIDs[1]]));
    $this->assertEquals($mergedContactIDs, array_keys($result));
  }

  /**
   * Test retrieving merged contacts.
   *
   * The goal here is to start with a contact deleted by merged and find out the contact that is the current version of them.
   *
   * @throws \CRM_Core_Exception
   */
  public function testMergedGetWithPermanentlyDeletedContact(): void {
    $this->ids['Contact'][] = $this->individualCreate();
    $this->ids['Contact'][] = $this->individualCreate();
    $this->ids['Contact'][] = $this->individualCreate();
    $this->ids['Contact'][] = $this->individualCreate();

    // First do an 'unnatural merge' - they 'like to merge into the lowest but this will mean that contact 0 merged to contact [3].
    // When the batch merge runs.... the new lowest contact is contact[1]. All contacts will merge into that contact,
    // including contact[3], resulting in only 3 existing at the end. For each contact the correct answer to 'who did I eventually
    // wind up being should be [1]
    $this->callAPISuccess('Contact', 'merge', ['to_remove_id' => $this->ids['Contact'][0], 'to_keep_id' => $this->ids['Contact'][3]]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->ids['Contact'][3], 'skip_undelete' => TRUE]);
    $this->callAPIFailure('Contact', 'getmergedto', ['sequential' => 1, 'contact_id' => $this->ids['Contact'][0]]);
    $title = CRM_Contact_Page_View::setTitle($this->ids['Contact'][0], TRUE);
    $this->assertStringContainsString('civicrm/profile/view&amp;reset=1&amp;gid=7&amp;id=3&amp;snippet=4', $title);
  }

  /**
   * Test merging 2 contacts with delete to trash off.
   *
   * We are checking that there is no error due to attempting to add an
   * activity for the deleted contact.
   *
   * @see https://issues.civicrm.org/jira/browse/CRM-18307
   * @throws \CRM_Core_Exception
   */
  public function testMergeNoTrash(): void {
    $this->createLoggedInUser();
    $this->callAPISuccess('Setting', 'create', ['contact_undelete' => FALSE]);
    $otherContact = $this->callAPISuccess('contact', 'create', $this->_params);
    $retainedContact = $this->callAPISuccess('contact', 'create', $this->_params);
    $this->callAPISuccess('contact', 'merge', [
      'to_keep_id' => $retainedContact['id'],
      'to_remove_id' => $otherContact['id'],
      'auto_flip' => FALSE,
    ]);
    $this->callAPISuccess('Setting', 'create', ['contact_undelete' => TRUE]);
  }

  /**
   * Ensure format with return=group shows comma-separated group IDs.
   *
   * @see https://issues.civicrm.org/jira/browse/CRM-19426
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetReturnGroup(): void {
    $contact_params = [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Group member',
      'email' => 'test@example.org',
    ];
    $create_contact = $this->callApiSuccess('Contact', 'create', $contact_params);
    $this->assertEquals(0, $create_contact['is_error']);
    $this->assertIsInt($create_contact['id']);

    $created_contact_id = $create_contact['id'];

    // Set up multiple groups, add the contact to the groups.
    $test_groups = ['Test group A', 'Test group B'];
    foreach ($test_groups as $title) {
      // Use this contact as group owner, since we know they exist.
      $group_params = [
        'title' => $title,
        'created_id' => $created_contact_id,
      ];
      $create_group = $this->callApiSuccess('Group', 'create', $group_params);
      $this->assertEquals(0, $create_group['is_error']);
      $this->assertIsInt($create_group['id']);

      $created_group_ids[] = $create_group['id'];

      // Add contact to the new group.
      $group_contact_params = [
        'contact_id' => $created_contact_id,
        'group_id' => $create_group['id'],
      ];
      $create_group_contact = $this->callApiSuccess('GroupContact', 'create', $group_contact_params);
      $this->assertEquals(0, $create_group_contact['is_error']);
      $this->assertIsInt($create_group_contact['added']);
    }

    // Use the Contact,get API to retrieve the contact
    $contact_get_params = [
      'id' => $created_contact_id,
      'return' => 'group',
    ];
    $contact_get = $this->callApiSuccess('Contact', 'get', $contact_get_params);
    $this->assertIsArray($contact_get['values'][$created_contact_id]);
    $this->assertIsString($contact_get['values'][$created_contact_id]['groups']);

    // Ensure they are shown as being in each created group.
    $contact_group_ids = explode(',', $contact_get['values'][$created_contact_id]['groups']);
    foreach ($created_group_ids as $created_group_id) {
      $this->assertContainsEquals($created_group_id, $contact_group_ids);
    }
  }

  /**
   * CRM-20144 Verify that passing title of group works as well as id
   * Tests the following formats
   * contact.get group='title1'
   * contact.get group=id1
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetWithGroupTitle(): void {
    $contact_params = [
      'contact_type' => 'Individual',
      'first_name' => 'Test2',
      'last_name' => 'Group member',
      'email' => 'test@example.org',
    ];
    $create_contact = $this->callApiSuccess('Contact', 'create', $contact_params);
    $created_contact_id = $create_contact['id'];
    // Set up multiple groups, add the contact to the groups.
    $test_groups = ['Test group C', 'Test group D'];
    foreach ($test_groups as $title) {
      $group_params = [
        'title' => $title,
        'created_id' => $created_contact_id,
      ];
      $create_group = $this->callApiSuccess('Group', 'create', $group_params);
      $created_group_id = $create_group['id'];

      // Add contact to the new group.
      $group_contact_params = [
        'contact_id' => $created_contact_id,
        'group_id' => $create_group['id'],
      ];
      $this->callApiSuccess('GroupContact', 'create', $group_contact_params);
      unset(Civi::$statics['CRM_ACL_API']);
      $contact_get = $this->callAPISuccess('contact', 'get', ['group' => $title, 'return' => 'group']);
      $this->assertEquals(1, $contact_get['count']);
      $this->assertEquals($created_contact_id, $contact_get['id']);
      $contact_groups = explode(',', $contact_get['values'][$created_contact_id]['groups']);
      $this->assertContains((string) $create_group['id'], $contact_groups);
      $contact_get2 = $this->callAPISuccess('contact', 'get', ['group' => $created_group_id, 'return' => 'group']);
      $this->assertEquals($created_contact_id, $contact_get2['id']);
      $contact_groups2 = explode(',', $contact_get2['values'][$created_contact_id]['groups']);
      $this->assertContains((string) $create_group['id'], $contact_groups2);
      $this->callAPISuccess('group', 'delete', ['id' => $created_group_id]);
    }
    $this->callAPISuccess('contact', 'delete', ['id' => $created_contact_id, 'skip_undelete' => TRUE]);
  }

  /**
   * CRM-20144 Verify that passing title of group works as well as id
   * Tests the following formats
   * contact.get group=array('title1', title1)
   * contact.get group=array('IN' => array('title1', 'title2)
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetWithGroupTitleMultipleGroups(): void {
    $contact_params = [
      'contact_type' => 'Individual',
      'first_name' => 'Test2',
      'last_name' => 'Group member',
      'email' => 'test@example.org',
    ];
    $create_contact = $this->callAPISuccess('Contact', 'create', $contact_params);
    $created_contact_id = $create_contact['id'];
    $createdGroupsIds = [];
    // Set up multiple groups, add the contact to the groups.
    $test_groups = ['Test group C', 'Test group D'];
    foreach ($test_groups as $title) {
      $group_params = [
        'title' => $title,
        'created_id' => $created_contact_id,
      ];
      $create_group = $this->callAPISuccess('Group', 'create', $group_params);
      unset(Civi::$statics['CRM_ACL_API']);
      $createdGroupsIds[] = $create_group['id'];
      $createdGroupTitles[] = $title;
      // Add contact to the new group.
      $group_contact_params = [
        'contact_id' => $created_contact_id,
        'group_id' => $create_group['id'],
      ];
      $this->callAPISuccess('GroupContact', 'create', $group_contact_params);
    }
    $contact_get = $this->callAPISuccess('contact', 'get', ['group' => $createdGroupTitles, 'return' => 'group']);
    $this->assertEquals(1, $contact_get['count']);
    $this->assertEquals($created_contact_id, $contact_get['id']);
    $contact_groups = explode(',', $contact_get['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups);
    }
    $this->callAPISuccess('contact', 'get', ['group' => ['IN' => $createdGroupTitles]]);
    $contact_get2 = $this->callAPISuccess('contact', 'get', ['group' => ['IN' => $createdGroupTitles], 'return' => 'group']);
    $this->assertEquals($created_contact_id, $contact_get2['id']);
    $contact_groups2 = explode(',', $contact_get2['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups2);
    }
    foreach ($createdGroupsIds as $id) {
      $this->callAPISuccess('group', 'delete', ['id' => $id]);
    }
    $this->callAPISuccess('contact', 'delete', ['id' => $created_contact_id, 'skip_undelete' => TRUE]);
  }

  /**
   * CRM-20144 Verify that passing title of group works as well as id
   * Tests the following formats
   * contact.get group=array('title1' => 1)
   * contact.get group=array('title1' => 1, 'title2' => 1)
   * contact.get group=array('id1' => 1)
   * contact.get group=array('id1' => 1, id2 => 1)
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetWithGroupTitleMultipleGroupsLegacyFormat(): void {
    $contact_params = [
      'contact_type' => 'Individual',
      'first_name' => 'Test2',
      'last_name' => 'Group member',
      'email' => 'test@example.org',
    ];
    $create_contact = $this->callAPISuccess('Contact', 'create', $contact_params);
    $created_contact_id = $create_contact['id'];
    $createdGroupsIds = [];
    // Set up multiple groups, add the contact to the groups.
    $test_groups = ['Test group C', 'Test group D'];
    foreach ($test_groups as $title) {
      $group_params = [
        'title' => $title,
        'created_id' => $created_contact_id,
      ];
      $create_group = $this->callAPISuccess('Group', 'create', $group_params);
      $createdGroupsIds[] = $create_group['id'];
      $createdGroupTitles[] = $title;
      // Add contact to the new group.
      $group_contact_params = [
        'contact_id' => $created_contact_id,
        'group_id' => $create_group['id'],
      ];
      $this->callAPISuccess('GroupContact', 'create', $group_contact_params);
    }
    unset(Civi::$statics['CRM_ACL_API']);
    $contact_get = $this->callAPISuccess('contact', 'get', ['group' => [$createdGroupTitles[0] => 1], 'return' => 'group']);
    $this->assertEquals(1, $contact_get['count']);
    $this->assertEquals($created_contact_id, $contact_get['id']);
    $contact_groups = explode(',', $contact_get['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups);
    }
    $contact_get2 = $this->callAPISuccess('contact', 'get', ['group' => [$createdGroupTitles[0] => 1, $createdGroupTitles[1] => 1], 'return' => 'group']);
    $this->assertEquals(1, $contact_get2['count']);
    $this->assertEquals($created_contact_id, $contact_get2['id']);
    $contact_groups2 = explode(',', $contact_get2['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups2);
    }
    $contact_get3 = $this->callAPISuccess('contact', 'get', ['group' => [$createdGroupsIds[0] => 1], 'return' => 'group']);
    $this->assertEquals($created_contact_id, $contact_get3['id']);
    $contact_groups3 = explode(',', $contact_get3['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups3);
    }
    $contact_get4 = $this->callAPISuccess('contact', 'get', ['group' => [$createdGroupsIds[0] => 1, $createdGroupsIds[1] => 1], 'return' => 'group']);
    $this->assertEquals($created_contact_id, $contact_get4['id']);
    $contact_groups4 = explode(',', $contact_get4['values'][$created_contact_id]['groups']);
    foreach ($createdGroupsIds as $id) {
      $this->assertContains((string) $id, $contact_groups4);
    }
    foreach ($createdGroupsIds as $id) {
      $this->callAPISuccess('group', 'delete', ['id' => $id]);
    }
    $this->callAPISuccess('contact', 'delete', ['id' => $created_contact_id, 'skip_undelete' => TRUE]);
  }

  /**
   * Test the prox_distance functionality works.
   *
   * This is primarily testing functionality in the BAO_Query object that 'happens to be'
   * accessible via the api.
   *
   */
  public function testContactGetProximity(): void {
    $this->individualCreate();
    $contactID = $this->individualCreate();
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $contactID,
      'is_primary' => 1,
      'city' => 'Whangarei',
      'street_address' => 'Dent St',
      'geo_code_1' => '-35.8743325',
      'geo_code_2' => '174.4567136',
      'location_type_id' => 'Home',
    ]);
    $contact = $this->callAPISuccess('Contact', 'get', [
      'prox_distance' => 100,
      'prox_geo_code_1' => '-35.72192',
      'prox_geo_code_2' => '174.32034',
    ]);
    $this->assertEquals(1, $contact['count']);
    $this->assertEquals($contactID, $contact['id']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testLoggedInUserAPISupportToken(): void {
    $cid = $this->createLoggedInUser();
    $contact = $this->callAPISuccess('contact', 'get', ['id' => 'user_contact_id']);
    $this->assertEquals($cid, $contact['id']);
  }

  /**
   * @param $groupID
   * @param $contact
   */
  protected function putGroupContactCacheInClearableState($groupID, $contact): void {
    // We need to force the situation where there is invalid data in the cache and it
    // is due to be cleared.
    CRM_Core_DAO::executeQuery("
      INSERT INTO civicrm_group_contact_cache (group_id, contact_id)
      VALUES ($groupID, {$contact['id']})
    ");
    CRM_Core_DAO::executeQuery("UPDATE civicrm_group SET cache_date = '2017-01-01'");
    // Reset so it does not skip.
    Civi::$statics['CRM_Contact_BAO_GroupContactCache']['is_refresh_init'] = FALSE;
  }

  /**
   * CRM-21041 Test if 'communication style' is set to site default if not passed.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   * @throws \CRM_Core_Exception
   */
  public function testCreateCommunicationStyleUnset(int $version): void {
    $this->_apiversion = $version;
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'contact_type' => 'Individual',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Doe']);
    $this->assertEquals(1, $result['communication_style_id']);
  }

  /**
   * CRM-21041 Test if 'communication style' is set if value is passed.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateCommunicationStylePassed(): void {
    $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'contact_type' => 'Individual',
      'communication_style_id' => 'Familiar',
    ]);
    $result = $this->callAPISuccessGetSingle('Contact', ['last_name' => 'Doe']);
    $params = [
      'option_group_id' => 'communication_style',
      'label' => 'Familiar',
      'return' => 'value',
    ];
    $optionResult = civicrm_api3('OptionValue', 'get', $params);
    $communicationStyle = reset($optionResult['values']);
    $this->assertEquals($communicationStyle['value'], $result['communication_style_id']);
  }

  /**
   * Test that creating a contact with various contact greetings works.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testContactGreetingsCreate(int $version): void {
    $this->_apiversion = $version;
    // Api v4 takes a return parameter like postal_greeting_display which matches the field.
    // v3 has a customised parameter 'postal_greeting'. The v4 parameter is more correct so
    // we will not change it to match v3. The keyString value allows the test to support both.
    $keyString = $version === 4 ? '_display' : '';

    $contact = $this->callAPISuccess('Contact', 'create', ['first_name' => 'Alan', 'last_name' => 'MouseMouse', 'contact_type' => 'Individual']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('Dear Alan', $contact['postal_greeting_display']);

    $contact = $this->callAPISuccess('Contact', 'create', ['id' => $contact['id'], 'postal_greeting_id' => 2]);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('Dear Alan MouseMouse', $contact['postal_greeting_display']);

    $contact = $this->callAPISuccess('Contact', 'create', ['organization_name' => 'Alan\'s Show', 'contact_type' => 'Organization']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => "postal_greeting$keyString, addressee$keyString, email_greeting$keyString"]);
    $this->assertEquals('', $contact['postal_greeting_display']);
    $this->assertEquals('', $contact['email_greeting_display']);
    $this->assertEquals('Alan\'s Show', $contact['addressee_display']);
  }

  /**
   * Test that creating a contact with various contact greetings works.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testContactGreetingsCreateWithCustomField(int $version): void {
    $this->_apiversion = $version;
    // Api v4 takes a return parameter like postal_greeting_display which matches the field.
    // v3 has a customised parameter 'postal_greeting'. The v4 parameter is more correct so
    // we will not change it to match v3. The keyString value allows the test to support both.
    $keyString = $version === 4 ? '_display' : '';

    $ids = $this->entityCustomGroupWithSingleFieldCreate(__FUNCTION__, __FILE__);
    $contact = $this->callAPISuccess('Contact', 'create', ['first_name' => 'Alan', 'contact_type' => 'Individual', 'custom_' . $ids['custom_field_id'] => 'Mice']);

    // Change postal greeting to involve a custom field.
    $postalOption = $this->callAPISuccessGetSingle('OptionValue', ['option_group_id' => 'postal_greeting', 'filter' => 1, 'is_default' => 1]);
    $this->callAPISuccess('OptionValue', 'create', [
      'id' => $postalOption['id'],
      'name' => 'Dear {contact.first_name} {contact.custom_' . $ids['custom_field_id'] . '}',
      'label' => 'Dear {contact.first_name} {contact.custom_' . $ids['custom_field_id'] . '}',
    ]);

    // Update contact & see if postal greeting now reflects the new string.
    $this->callAPISuccess('Contact', 'create', ['id' => $contact['id'], 'last_name' => 'MouseyMousey']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('Dear Alan Mice', $contact['postal_greeting_display']);

    // Set contact to have no postal greeting & check it is correct.
    $this->callAPISuccess('Contact', 'create', ['id' => $contact['id'], 'postal_greeting_id' => 'null']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contact['id'], 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('', $contact['postal_greeting_display']);

    //Cleanup
    $this->callAPISuccess('OptionValue', 'create', ['id' => $postalOption['id'], 'name' => 'Dear {contact.first_name}']);
    $this->customFieldDelete($ids['custom_field_id']);
    $this->customGroupDelete($ids['custom_group_id']);
  }

  /**
   * Test that smarty variables are parsed if they exist in the greeting template.
   *
   * In this test we have both a Civi token & a Smarty token and we check both are processed.
   *
   * @param int $version
   *
   * @dataProvider versionThreeAndFour
   */
  public function testGreetingParseSmarty(int $version): void {
    $this->_apiversion = $version;
    // Api v4 takes a return parameter like postal_greeting_display which matches the field.
    // v3 has a customised parameter 'postal_greeting'. The v4 parameter is more correct so
    // we will not change it to match v3. The keyString value allows the test to support both.
    $keyString = $version === 4 ? '_display' : '';
    $postalOption = $this->callAPISuccessGetSingle('OptionValue', ['option_group_id' => 'postal_greeting', 'filter' => 1, 'is_default' => 1]);
    $this->callAPISuccess('OptionValue', 'create', [
      'id' => $postalOption['id'],
      'name' => "Dear {contact.first_name} {if \'{contact.first_name}\' === \'Tim\'}The Wise{/if}",
      'label' => "Dear {contact.first_name} {if '{contact.first_name}' === 'Tim'} The Wise{/if}",
    ]);
    $contactID = $this->individualCreate();
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contactID, 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('Dear Anthony', $contact['postal_greeting_display']);

    $this->callAPISuccess('Contact', 'create', ['id' => $contactID, 'first_name' => 'Tim']);
    $contact = $this->callAPISuccessGetSingle('Contact', ['id' => $contactID, 'return' => 'postal_greeting' . $keyString]);
    $this->assertEquals('Dear Tim The Wise', $contact['postal_greeting_display']);
  }

  /**
   * Test getunique api call for Contact entity
   */
  public function testContactGetUnique(): void {
    $result = $this->callAPISuccess($this->_entity, 'getunique', []);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals(['external_identifier'], $result['values']['UI_external_identifier']);
  }

  /**
   * API test to retrieve contact from group having different group title and name.
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetFromGroup(): void {
    $groupId = $this->groupCreate([
      'name' => 'Test_Group',
      'domain_id' => 1,
      'title' => 'New Test Group Created',
      'description' => 'New Test Group Created',
      'is_active' => 1,
      'visibility' => 'User and User Admin Only',
    ]);
    $contact = $this->callAPISuccess('contact', 'create', $this->_params);
    $groupContactCreateParams = [
      'contact_id' => $contact['id'],
      'group_id' => $groupId,
      'status' => 'Pending',
    ];
    $this->callAPISuccess('groupContact', 'create', $groupContactCreateParams);
    $this->callAPISuccess('groupContact', 'get', $groupContactCreateParams);
    $this->callAPISuccess('Contact', 'getcount', ['group' => 'Test_Group']);
  }

  /**
   * Test the related contacts filter.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSmartGroupsForRelatedContacts(): void {
    $relationshipType1 = $this->callAPISuccess('RelationshipType', 'create', [
      'name_a_b' => 'Child of - test',
      'name_b_a' => 'Parent of - test',
    ]);
    $relationshipType2 = $this->callAPISuccess('relationship_type', 'create', [
      'name_a_b' => 'Household Member of - test',
      'name_b_a' => 'Household Member is - test',
    ]);
    $h1 = $this->householdCreate();
    $c1 = $this->individualCreate(['last_name' => 'Adams']);
    $c2 = $this->individualCreate(['last_name' => 'Adams']);
    $this->callAPISuccess('relationship', 'create', [
      'contact_id_a' => $c1,
      'contact_id_b' => $c2,
      'is_active' => 1,
      // Child of
      'relationship_type_id' => $relationshipType1['id'],
    ]);
    $this->callAPISuccess('relationship', 'create', [
      'contact_id_a' => $c1,
      'contact_id_b' => $h1,
      'is_active' => 1,
      // Household Member of
      'relationship_type_id' => $relationshipType2['id'],
    ]);
    $this->callAPISuccess('relationship', 'create', [
      'contact_id_a' => $c2,
      'contact_id_b' => $h1,
      'is_active' => 1,
      // Household Member of
      'relationship_type_id' => $relationshipType2['id'],
    ]);

    $ssParams = [
      'form_values' => [
        // Child of
        'display_relationship_type' => $relationshipType1['id'] . '_a_b',
        'sort_name' => 'Adams',
      ],
    ];
    $g1ID = $this->smartGroupCreate($ssParams, ['name' => 'group', 'title' => 'group']);
    $ssParams = [
      'form_values' => [
        // Household Member of
        'display_relationship_type' => $relationshipType2['id'] . '_a_b',
      ],
    ];
    $g2ID = $this->smartGroupCreate($ssParams, ['name' => 'smart_group', 'title' => 'smart group']);
    $ssParams = [
      'form_values' => [
        // Household Member is
        'display_relationship_type' => $relationshipType2['id'] . '_b_a',
      ],
    ];
    // the reverse of g2 which adds another layer for overlap at related contact filter
    $g3ID = $this->smartGroupCreate($ssParams, ['name' => 'my group', 'title' => 'my group']);
    CRM_Contact_BAO_GroupContactCache::loadAll();
    $this->callAPISuccessGetCount('contact', ['group' => $g1ID], 1);
    $this->callAPISuccessGetCount('contact', ['group' => $g2ID], 2);
    $this->callAPISuccessGetCount('contact', ['group' => $g3ID], 1);
  }

  /**
   * Test creating a note from the contact.create API call when only passing the note as a string.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateNoteInCreate(): void {
    $loggedInContactID = $this->createLoggedInUser();
    $this->_params['note'] = 'Test note created by API Call as a String';
    $contact = $this->callAPISuccess('Contact', 'create', $this->_params);
    $note = $this->callAPISuccess('Note', 'get', ['contact_id' => $loggedInContactID]);
    $this->assertEquals('Test note created by API Call as a String', $note['values'][$note['id']]['note']);
    $note = $this->callAPISuccess('Note', 'get', ['entity_id' => $contact['id']]);
    $this->assertEquals('Test note created by API Call as a String', $note['values'][$note['id']]['note']);
    $this->callAPISuccess('Contact', 'delete', ['id' => $contact['id'], 'skip_undelete' => TRUE]);
  }

  /**
   * Test Creating a note from the contact.create api call when passing the note params as an array.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCreateNoteInCreateArrayFormat(): void {
    $contact1 = $this->callAPISuccess('Contact', 'create', ['first_name' => 'Alan', 'last_name' => 'MouseMouse', 'contact_type' => 'Individual']);
    $this->_params['note'] = [['note' => 'Test note created by API Call as array', 'contact_id' => $contact1['id']]];
    $contact2 = $this->callAPISuccess('Contact', 'create', $this->_params);
    $note = $this->callAPISuccess('Note', 'get', ['contact_id' => $contact1['id']]);
    $this->assertEquals('Test note created by API Call as array', $note['values'][$note['id']]['note']);
    $note = $this->callAPISuccess('Note', 'get', ['entity_id' => $contact2['id']]);
    $this->assertEquals('Test note created by API Call as array', $note['values'][$note['id']]['note']);
  }

  /**
   * Verify that passing tag IDs to Contact.get works
   *
   * Tests the following formats
   * - Contact.get tag='id1'
   * - Contact.get tag='id1,id2'
   * - Contact.get tag='id1, id2'
   *
   * @throws \CRM_Core_Exception
   */
  public function testContactGetWithTag(): void {
    $contact = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Tagged',
      'email' => 'test@example.org',
    ]);
    $tags = [];
    foreach (['Tag A', 'Tag B'] as $name) {
      $tags[] = $this->callAPISuccess('Tag', 'create', [
        'name' => $name,
      ]);
    }

    // assign contact to "Tag B"
    $this->callAPISuccess('EntityTag', 'create', [
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contact['id'],
      'tag_id' => $tags[1]['id'],
    ]);

    // test format Contact.get tag='id1'
    $contact_get = $this->callAPISuccess('Contact', 'get', [
      'tag' => $tags[1]['id'],
      'return' => 'tag',
    ]);
    $this->assertEquals(1, $contact_get['count']);
    $this->assertEquals($contact['id'], $contact_get['id']);
    $this->assertEquals('Tag B', $contact_get['values'][$contact['id']]['tags']);

    // test format Contact.get tag='id1,id2'
    $contact_get = $this->callAPISuccess('Contact', 'get', [
      'tag' => $tags[0]['id'] . ',' . $tags[1]['id'],
      'return' => 'tag',
    ]);
    $this->assertEquals(1, $contact_get['count']);
    $this->assertEquals($contact['id'], $contact_get['id']);
    $this->assertEquals('Tag B', $contact_get['values'][$contact['id']]['tags']);

    // test format Contact.get tag='id1, id2'
    $contact_get = $this->callAPISuccess('Contact', 'get', [
      'tag' => $tags[0]['id'] . ', ' . $tags[1]['id'],
      'return' => 'tag',
    ]);
    $this->assertEquals(1, $contact_get['count']);
    $this->assertEquals($contact['id'], $contact_get['id']);
    $this->assertEquals('Tag B', $contact_get['values'][$contact['id']]['tags']);

    foreach ($tags as $tag) {
      $this->callAPISuccess('Tag', 'delete', ['id' => $tag['id']]);
    }
    $this->callAPISuccess('Contact', 'delete', [
      'id' => $contact['id'],
      'skip_undelete' => TRUE,
    ]);
  }

  /**
   * Create pair of contacts with multiple conflicts.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function createDeeplyConflictedContacts(): array {
    $this->createCustomGroupWithFieldOfType();
    $contact1 = $this->individualCreate([
      'email' => 'bob@example.com',
      'api.address.create' => ['location_type_id' => 'work', 'street_address' => 'big office', 'city' => 'small city'],
      'api.address.create.2' => ['location_type_id' => 'home', 'street_address' => 'big house', 'city' => 'small city'],
      'external_identifier' => 'unique and special',
      $this->getCustomFieldName('text') => 'mummy loves me',
    ]);
    $contact2 = $this->individualCreate([
      'first_name' => 'different',
      'api.address.create.1' => ['location_type_id' => 'home', 'street_address' => 'medium house', 'city' => 'small city'],
      'api.address.create.2' => ['location_type_id' => 'work', 'street_address' => 'medium office', 'city' => 'small city'],
      'external_identifier' => 'uniquer and specialler',
      'api.email.create' => ['location_type_id' => 'Other', 'email' => 'bob@example.com'],
      $this->getCustomFieldName('text') => 'mummy loves me more',
    ]);
    return [$contact1, $contact2];
  }

  /**
   * Combinations of versions and privacy choices.
   *
   * @return array
   */
  public static function versionAndPrivacyOption(): array {
    $version = [3, 4];
    $fields = ['do_not_mail', 'do_not_email', 'do_not_sms', 'is_opt_out', 'do_not_trade'];
    $tests = [];
    foreach ($fields as $field) {
      foreach ($version as $v) {
        $tests[] = [$v, 1, $field, 1];
        $tests[] = [$v, 0, $field, 3];
        $tests[] = [$v, ['!=' => 1], $field, 3];
        $tests[] = [$v, ['!=' => 0], $field, 1];
      }
    }
    return $tests;
  }

  /**
   * CRM-14743 - test api respects search operators.
   *
   * @param int $version
   *
   * @param $query
   * @param $field
   * @param $expected
   *
   * @throws \CRM_Core_Exception
   * @dataProvider versionAndPrivacyOption
   */
  public function testGetContactsByPrivacyFlag(int $version, $query, $field, $expected): void {
    $this->_apiversion = $version;
    $contact1 = $this->individualCreate();
    $contact2 = $this->individualCreate([$field => 1]);
    $contact = $this->callAPISuccess('Contact', 'get', [$field => $query]);
    $this->assertEquals($expected, $contact['count']);
    $this->callAPISuccess('Contact', 'delete', ['id' => $contact1, 'skip_undelete' => 1]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $contact2, 'skip_undelete' => 1]);
  }

  /**
   * Do the merge on the 2 contacts.
   *
   * @param bool $isReverse
   *
   * @return array|int
   * @throws \CRM_Core_Exception
   */
  protected function doMerge(bool $isReverse = FALSE) {
    $this->callAPISuccess('Contact', 'merge', [
      'to_keep_id' => $isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0],
      'to_remove_id' => $isReverse ? $this->ids['contact'][0] : $this->ids['contact'][1],
      'auto_flip' => FALSE,
    ]);
    return $this->callAPISuccessGetSingle('Contact', ['id' => $isReverse ? $this->ids['contact'][1] : $this->ids['contact'][0]]);
  }

  /**
   * Test a lack of fatal errors when the where contains an emoji.
   *
   * By default our DBs are not 🦉 compliant. This test will age
   * out when we are.
   */
  public function testEmojiInWhereClause(): void {
    $schemaNeedsAlter = \CRM_Core_BAO_SchemaHandler::databaseSupportsUTF8MB4();
    if ($schemaNeedsAlter) {
      CRM_Core_DAO::executeQuery("
        ALTER TABLE civicrm_contact MODIFY COLUMN
        `first_name` VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'First Name.',
        CHARSET utf8 COLLATE utf8_unicode_ci
      ");
      Civi::$statics['CRM_Core_BAO_SchemaHandler'] = [];
    }
    $this->callAPISuccess('Contact', 'get', [
      'debug' => 1,
      'first_name' => '🦉Claire',
    ]);
    if ($schemaNeedsAlter) {
      CRM_Core_DAO::executeQuery("
        ALTER TABLE civicrm_contact MODIFY COLUMN
        `first_name` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'First Name.',
        CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
      ");
      Civi::$statics['CRM_Core_BAO_SchemaHandler'] = [];
    }
  }

  /**
   * @param string $fieldName
   * @param mixed $expected
   * @param int|null $contactID
   * @param array|null $criteria
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function validateContactField(string $fieldName, $expected, ?int $contactID, ?array $criteria = NULL): void {
    $api = Contact::get()->addSelect($fieldName);
    if ($criteria) {
      $api->setWhere([$criteria]);
    }
    if ($contactID) {
      $api->addWhere('id', '=', $contactID);
    }
    $this->assertEquals($expected, $api->execute()->first()[$fieldName]);
  }

  /**
   * Test fetching custom field value that needs to be escaped.
   */
  public function testGetEscapedCustomField(): void {
    $testValues = [
      'test-value',
      // This value will be escaped.
      "test-'value",
    ];

    // Create a custom contact field group + field.
    $customGroupId = $this->customGroupCreate([
      'title' => 'testGetEscapedCustomField',
    ])['id'];
    $customFieldId = $this->customFieldCreate([
      'custom_group_id' => $customGroupId,
      'label' => 'testGetEscapedCustomField',
    ])['id'];
    $customFieldParam = 'custom_' . $customFieldId;

    // Create and fetch contacts using the test values.
    foreach ($testValues as $value) {
      // Create a new contact and insert the test value into the custom field.
      $contactId = $this->callAPISuccess('Contact', 'create', [
        'contact_type' => 'Individual',
        'first_name' => 'Test',
        'last_name' => 'Contact',
        $customFieldParam => $value,
      ])['id'];

      // Verify the test value was inserted correctly.
      $contactData = $this->callAPISuccess('Contact', 'getsingle', [
        'id' => $contactId,
        'return' => [$customFieldParam],
      ]);
      $this->assertEquals($value, $contactData[$customFieldParam]);

      // All of these comparison operators should return a result.
      $comparisonQueries = [
        ['LIKE' => $value],
        ['IN' => [$value]],
        // Equals.
        $value,
      ];

      // Fetch the new contact using different comparison operators.
      foreach ($comparisonQueries as $query) {
        $this->callAPISuccess('Contact', 'getsingle', [
          'id' => $contactId,
          $customFieldParam => $query,
        ]);
      }
    }
  }

}
