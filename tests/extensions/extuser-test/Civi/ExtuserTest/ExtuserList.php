<?php
declare(strict_types = 1);

namespace Civi\ExtuserTest;

use CRM_ExtuserTest_ExtensionUtil as E;
use Civi\Core\Service\AutoService;

/**
 * @service extuser_list
 */
class ExtuserList extends AutoService {

  public function getFile(): string {
    // TODO: move to data folder
    return E::path('extuser_test.json');
  }

  public function getAll(): array {
    return json_decode(file_get_contents($this->getFile()), TRUE);
  }

  public function get(string $identifier): ?array {
    foreach ($this->getAll() as $row) {
      if ($row['uid'] === $identifier) {
        return $row;
      }
    }
    return NULL;
  }

  public function save(array $row): void {
    $row['timestamp'] = \CRM_Utils_Time::date('c');

    $all = $this->getAll();
    $found = FALSE;

    foreach (array_keys($all) as $rowId) {
      if ($all[$rowId]['uid'] === $row['uid']) {
        $all[$rowId] = $row;
        $found = TRUE;
      }
    }
    if (!$found) {
      $all[] = $row;
    }

    $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    \Civi::fs()->dumpFile($this->getFile(), $json);
  }

}
