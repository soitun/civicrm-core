<?php

namespace Civi\Schema;

class SchemaHelperTest extends \CiviUnitTestCase {

  public function testGetExistingTables(): void {
    $tables = \Civi::schemaHelper()->getExistingTables(['civicrm_activity', 'civicrm_contact', 'CiviCRM_TAG']);
    $this->assertEquals(['civicrm_activity', 'civicrm_contact', 'civicrm_tag'], array_values($tables));
  }

  public function testTableExists(): void {
    $this->assertTrue(\Civi::schemaHelper()->tableExists('civicrm_activity'));
    // Function is case-insensitive.
    $this->assertTrue(\Civi::schemaHelper()->tableExists('CiviCRM_Activity'));
    $this->assertFalse(\Civi::schemaHelper()->tableExists('civicrm_false_nothing'));
  }

}
