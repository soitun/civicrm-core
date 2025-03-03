<?php

use Civi\Api4\CaseType;

/**
 * Case Types support an optional forking mechanism wherein the local admin
 * creates a custom DB-based definition that deviates from the file-based definition.
 * @group headless
 */
class CRM_Case_BAO_CaseTypeForkTest extends CiviCaseTestCase {

  public function setUp(): void {
    parent::setUp();
    CRM_Core_ManagedEntities::singleton(TRUE)->reconcile();
  }

  public function tearDown(): void {
    CaseType::delete(FALSE)
      ->addWhere('name', 'IN', ['UnforkableCaseType', 'ForkableCaseType'])
      ->execute();
    parent::tearDown();
  }

  /**
   * Test Manager contact is correctly assigned via case type def.
   */
  public function testManagerContact(): void {
    $caseTypeId = CRM_Core_DAO::getFieldValue('CRM_Case_DAO_CaseType', 'ForkableCaseType', 'id', 'name');
    $this->assertTrue(is_numeric($caseTypeId) && $caseTypeId > 0);

    $this->callAPISuccess('CaseType', 'create', [
      'id' => $caseTypeId,
      'definition' => [
        'caseRoles' => [
          ['name' => 'First role', 'manager' => 0],
          ['name' => 'Second role', 'creator' => 1, 'manager' => 1],
        ],
      ],
    ]);
    $relTypeID = $this->callAPISuccessGetValue('RelationshipType', [
      'return' => "id",
      'name_b_a' => "Second role",
    ]);
    //Check if manager is correctly retrieved from xml processor.
    $xmlProcessor = new CRM_Case_XMLProcessor_Process();
    $this->assertEquals($relTypeID . '_a_b', $xmlProcessor->getCaseManagerRoleId('ForkableCaseType'));
  }

  /**
   * Edit the definition of ForkableCaseType.
   */
  public function testForkable(): void {
    $caseTypeId = CRM_Core_DAO::getFieldValue('CRM_Case_DAO_CaseType', 'ForkableCaseType', 'id', 'name');
    $this->assertTrue(is_numeric($caseTypeId) && $caseTypeId > 0);

    $this->assertDBNull('CRM_Case_BAO_CaseType', $caseTypeId, 'definition', 'id', "Should not have DB-based definition");
    $this->assertTrue(CRM_Case_BAO_CaseType::isForkable($caseTypeId));
    $this->assertFalse(CRM_Case_BAO_CaseType::isForked($caseTypeId));

    $this->callAPISuccess('CaseType', 'create', [
      'id' => $caseTypeId,
      'definition' => [
        'activityTypes' => [
          ['name' => 'First act'],
          ['name' => 'Second act'],
        ],
      ],
    ]);

    $this->assertTrue(CRM_Case_BAO_CaseType::isForkable($caseTypeId));
    $this->assertTrue(CRM_Case_BAO_CaseType::isForked($caseTypeId));
    $this->assertDBNotNull('CRM_Case_BAO_CaseType', $caseTypeId, 'definition', 'id', "Should not have DB-based definition");

    $this->callAPISuccess('CaseType', 'create', [
      'id' => $caseTypeId,
      'definition' => 'null',
    ]);

    $this->assertDBNull('CRM_Case_BAO_CaseType', $caseTypeId, 'definition', 'id', "Should not have DB-based definition");
    $this->assertTrue(CRM_Case_BAO_CaseType::isForkable($caseTypeId));
    $this->assertFalse(CRM_Case_BAO_CaseType::isForked($caseTypeId));
  }

  /**
   * Attempt to edit the definition of UnforkableCaseType. This fails.
   */
  public function testUnforkable(): void {
    $caseTypeId = CRM_Core_DAO::getFieldValue('CRM_Case_DAO_CaseType', 'UnforkableCaseType', 'id', 'name');
    $this->assertTrue(is_numeric($caseTypeId) && $caseTypeId > 0);
    $this->assertDBNull('CRM_Case_BAO_CaseType', $caseTypeId, 'definition', 'id', "Should not have DB-based definition");

    $this->assertFalse(CRM_Case_BAO_CaseType::isForkable($caseTypeId));
    $this->assertFalse(CRM_Case_BAO_CaseType::isForked($caseTypeId));

    $this->callAPISuccess('CaseType', 'create', [
      'id' => $caseTypeId,
      'definition' => [
        'activityTypes' => [
          ['name' => 'First act'],
          ['name' => 'Second act'],
        ],
      ],
    ]);

    $this->assertFalse(CRM_Case_BAO_CaseType::isForkable($caseTypeId));
    $this->assertFalse(CRM_Case_BAO_CaseType::isForked($caseTypeId));
    $this->assertDBNull('CRM_Case_BAO_CaseType', $caseTypeId, 'definition', 'id', "Should not have DB-based definition");
  }

  /**
   * @param $caseTypes
   * @see \CRM_Utils_Hook::caseTypes
   */
  public function hook_caseTypes(&$caseTypes) {
    $caseTypes['ForkableCaseType'] = [
      'module' => 'civicrm',
      'name' => 'ForkableCaseType',
      'file' => __DIR__ . '/ForkableCaseType.xml',
    ];
    $caseTypes['UnforkableCaseType'] = [
      'module' => 'civicrm',
      'name' => 'UnforkableCaseType',
      'file' => __DIR__ . '/UnforkableCaseType.xml',
    ];
  }

}
