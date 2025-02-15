<?php
declare(strict_types = 1);

namespace Civi\Custom\EventSubscriber;

use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Group;

/**
 * @covers \Civi\Custom\EventSubscriber\FkEntityDeleteSubscriber
 *
 * @group headless
 */
final class FkEntityDeleteSubscriberTest extends \CiviUnitTestCase {

  private array $contactEntityReferenceField;

  protected function setUp(): void {
    parent::setUp();
    // Test that cascade deletion doesn't depend on current user's permissions.
    $this->setUserPermissions([
      'access CiviCRM',
      'access deleted contacts',
      'add contacts',
      'delete contacts',
      'edit all contacts',
      'view all contacts',
    ]);

    CustomGroup::create(FALSE)
      ->setValues([
        'name' => 'cg_group',
        'title' => 'Custom Group Group',
        'extends' => 'Group',
      ])->execute();

    $this->contactEntityReferenceField = CustomField::create(FALSE)
      ->setValues([
        'custom_group_id.name' => 'cg_group',
        'name' => 'contact',
        'data_type' => 'EntityReference',
        'fk_entity' => 'Contact',
        'label' => 'Contact',
        'html_type' => 'Select',
      ])->execute()->single();

    // Adding custom fields alters database schema and thus commits open transactions.
    $this->useTransaction();
  }

  protected function tearDown(): void {
    $this->setUserPermissions(NULL);
    parent::tearDown();

    CustomField::delete(FALSE)
      ->addWhere('id', '=', $this->contactEntityReferenceField['id'])
      ->execute();
    CustomGroup::delete(FALSE)
      ->addWhere('name', '=', 'cg_group')
      ->execute();
  }

  public function testCascade(): void {
    $this->updateContactEntityReferenceField('cascade');
    $contact1 = $this->createIndividual();
    $contact2 = $this->createIndividual();
    $group = $this->createGroup(['cg_group.contact' => $contact1['id']]);

    Contact::delete()
      ->setUseTrash(FALSE)
      ->addWhere('id', '=', $contact2['id'])
      ->execute();

    // If a not referenced entity is deleted nothing should happen.
    static::assertCount(1, Group::get(FALSE)
      ->addWhere('id', '=', $group['id'])
      ->addWhere('cg_group.contact', '=', $contact1['id'])
      ->execute()
    );

    Contact::delete()
      ->addWhere('id', '=', $contact1['id'])
      ->execute();

    // Nothing should happen on soft delete.
    static::assertCount(1, Group::get(FALSE)
      ->addWhere('id', '=', $group['id'])
      ->addWhere('cg_group.contact', '=', $contact1['id'])
      ->execute()
    );

    Contact::delete()
      ->setUseTrash(FALSE)
      ->addWhere('id', '=', $contact1['id'])
      ->execute();

    // Delete should be cascaded.
    static::assertCount(0, Group::get(FALSE)
      ->addWhere('id', '=', $group['id'])
      ->execute()
    );
  }

  private function updateContactEntityReferenceField(string $onDelete): void {
    CustomField::update(FALSE)
      ->setValues([
        'fk_entity_on_delete' => $onDelete,
      ])->addWhere('id', '=', $this->contactEntityReferenceField['id'])
      ->execute();
  }

  private function createIndividual(array $values = []): array {
    static $count = 0;
    ++$count;

    return Contact::create()
      ->setValues($values + [
        'contact_type' => 'Individual',
        'first_name' => 'First' . $count,
        'last_name' => 'Last' . $count,
      ])->execute()->single();
  }

  private function createGroup(array $values = []): array {
    static $count = 0;
    ++$count;

    return Group::create(FALSE)
      ->setValues($values + [
        'name' => 'TestGroup' . $count,
        'title' => 'TestTitle' . $count,
      ])->execute()->single();
  }

  /**
   * @phpstan-param list<string>|null $permissions
   */
  private function setUserPermissions(?array $permissions): void {
    $userPermissions = \CRM_Core_Config::singleton()->userPermissionClass;
    $userPermissions->permissions = $permissions;
  }

}
