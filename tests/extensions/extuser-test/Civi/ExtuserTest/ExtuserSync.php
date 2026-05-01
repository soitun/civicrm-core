<?php
declare(strict_types = 1);

namespace Civi\ExtuserTest;

use CRM_ExtuserTest_ExtensionUtil as E;
use Civi\Api4\Contact;
use Civi\Api4\User;
use Civi\Api4\UserRole;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @service extuser_sync
 */
class ExtuserSync extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      '&civi.standalone.loadUser' => ['onLoadUser', 0],
      '&civi.standalone.checkPassword' => ['onCheckPassword', 0],
    ];
  }

  public function onLoadUser(string $identifier, ?array &$user): void {
    $row = \Civi::service('extuser_list')->get($identifier);
    if (!$row) {
      return;
    }

    if (!$user) {
      $user = $this->createUser($identifier, $row);
    }
    elseif ($user && strtotime($user['when_updated']) < strtotime($row['timestamp'])) {
      $user = $this->updateUser($user, $identifier, $row);
    }
  }

  public function onCheckPassword(array $user, string $password, ?bool &$success): void {
    if ($row = \Civi::service('extuser_list')->get($user['username'])) {
      $success = hash_equals($row['sketch'], hash('sha256', $password));
    }
  }

  public function createUser(string $identifier, array $row): array {
    $user = NULL;
    // If there's a failure during an initialization step, it could be hard to cleanup...
    \CRM_Core_Transaction::create(TRUE)->run(function () use ($identifier, $row, &$user) {
      $contact = Contact::create(FALSE)
        ->setValues([
          'contact_type' => 'Individual',
          'first_name' => $row['givenName'],
          'last_name' => $row['sn'],
          'email_primary.email' => $row['mail'],
        ])
        ->execute()
        ->single();

      $user = User::create(FALSE)
        ->setValues([
          'is_active' => TRUE,
          'contact_id' => $contact['id'],
          'username' => $identifier,
          'uf_name' => $row['mail'],
        ])
        ->execute()
        ->single();

      $this->setRole($user['id'], $row['role']);
    });

    return $user;
  }

  public function updateUser(array $user, string $identifier, array $row): array {
    Contact::update(FALSE)
      ->addWhere('id', '=', $user['contact_id'])
      ->setValues([
        'contact_type' => 'Individual',
        'first_name' => $row['givenName'],
        'last_name' => $row['sn'],
        'email_primary.email' => $row['mail'],
      ])
      ->execute()
      ->single();

    $user = User::update(FALSE)
      ->addWhere('username', '=', $identifier)
      ->setValues([
        'is_active' => TRUE,
        // 'contact_id' => $contact['id'],
        // 'username' => $identifier,
        'uf_name' => $row['mail'],
      ])
      ->execute()
      ->single();

    $this->setRole($user['id'], $row['role']);

    return $user;
  }

  public function setRole(int $userId, string $role): void {
    UserRole::replace(FALSE)
      ->addWhere('user_id', '=', $userId)
      ->setMatch(['role_id'])
      ->addRecord([
        'role_id.name' => $role,
      ])
      ->execute();
  }

}
