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
class CRM_Utils_Type {
  const
    T_INT = 1,
    T_STRING = 2,
    T_ENUM = 2,
    T_DATE = 4,
    T_TIME = 8,
    T_BOOLEAN = 16,
    T_TEXT = 32,
    T_LONGTEXT = 32,
    T_BLOB = 64,
    T_TIMESTAMP = 256,
    T_FLOAT = 512,
    T_MONEY = 1024,
    T_EMAIL = 2048,
    T_URL = 4096,
    T_CCNUM = 8192,
    T_MEDIUMBLOB = 16384;

  // @TODO What's the point of these constants? Backwards compatibility?
  //
  // These are used for field size (<input type=text size=2>), but redundant TWO=2
  // usages are rare and should be eliminated. See CRM-18810.
  const
    TWO = 2,
    FOUR = 4,
    SIX = 6,
    EIGHT = 8,
    TWELVE = 12,
    SIXTEEN = 16,
    TWENTY = 20,
    MEDIUM = 20,
    THIRTY = 30,
    BIG = 30,
    FORTYFIVE = 45,
    HUGE = 45;

  /**
   * Maximum size of a MySQL BLOB or TEXT column in bytes.
   */
  const BLOB_SIZE = 65535;

  /**
   * Maximum value of a MySQL signed INT column.
   */
  const INT_MAX = 2147483647;

  /**
   * Gets the string representation for a data type.
   *
   * @param int $type
   *   Integer number identifying the data type.
   *
   * @return string
   *   String identifying the data type, e.g. 'Int' or 'String'.
   */
  public static function typeToString($type): string {
    // @todo Use constants in the case statements, e.g. "case T_INT:".
    // @todo return directly, instead of assigning a value.
    // @todo Use a lookup array, as a property or as a local variable.
    switch ($type) {
      case 1:
        $string = 'Int';
        break;

      case 2:
        $string = 'String';
        break;

      case 3:
        CRM_Core_Error::deprecatedFunctionWarning("Enum data type not supported.");
        $string = 'String';
        break;

      case 4:
        $string = 'Date';
        break;

      case 8:
        $string = 'Time';
        break;

      case 16:
        $string = 'Boolean';
        break;

      case 32:
        $string = 'Text';
        break;

      case 64:
        $string = 'Blob';
        break;

      // CRM-10404
      case 12:
      case 256:
        $string = 'Timestamp';
        break;

      case 512:
        $string = 'Float';
        break;

      case 1024:
        $string = 'Money';
        break;

      case 2048:
        $string = 'Date';
        break;

      case 4096:
        $string = 'Email';
        break;

      case 16384:
        $string = 'Mediumblob';
        break;
    }

    return $string ?? '';
  }

  /**
   * @return array
   *   An array of type in the form 'type name' => 'int representing type'
   */
  public static function getValidTypes() {
    return [
      'Int' => self::T_INT,
      'String' => self::T_STRING,
      'Date' => self::T_DATE,
      'Time' => self::T_TIME,
      'Boolean' => self::T_BOOLEAN,
      'Text' => self::T_TEXT,
      'Blob' => self::T_BLOB,
      'Timestamp' => self::T_TIMESTAMP,
      'Float' => self::T_FLOAT,
      'Money' => self::T_MONEY,
      'Email' => self::T_EMAIL,
      'Mediumblob' => self::T_MEDIUMBLOB,
    ];
  }

  /**
   * Get the data_type for the field.
   *
   * @param array $fieldMetadata
   *   Metadata about the field.
   *
   * @return string
   */
  public static function getDataTypeFromFieldMetadata($fieldMetadata) {
    if (isset($fieldMetadata['data_type'])) {
      return $fieldMetadata['data_type'];
    }
    if (empty($fieldMetadata['type'])) {
      // I would prefer to throw an e-notice but there is some,
      // probably unnecessary logic, that only retrieves activity fields
      // if they are 'in the profile' and probably they are not 'in'
      // until they are added - which might lead to ? who knows!
      return '';
    }
    return self::typeToString($fieldMetadata['type']);
  }

  /**
   * Helper function to call escape on arrays.
   *
   * @see escape
   */
  public static function escapeAll($data, $type, $abort = TRUE) {
    foreach ($data as $key => $value) {
      $data[$key] = CRM_Utils_Type::escape($value, $type, $abort);
    }
    return $data;
  }

  /**
   * Helper function to call validate on arrays
   *
   * @param mixed $data
   * @param string $type
   *
   * @return mixed
   *
   * @throws \CRM_Core_Exception
   *
   * @see validate
   */
  public static function validateAll($data, $type) {
    foreach ($data as $key => $value) {
      $data[$key] = CRM_Utils_Type::validate($value, $type);
    }
    return $data;
  }

  /**
   * Verify that a variable is of a given type, and apply a bit of processing.
   *
   * @param mixed $data
   *   The value to be verified/escaped.
   * @param string $type
   *   The type to verify against.
   * @param bool $abort
   *   If TRUE, the operation will throw an CRM_Core_Exception on invalid data.
   *
   * @return mixed
   *   The data, escaped if necessary.
   * @throws CRM_Core_Exception
   */
  public static function escape($data, $type, $abort = TRUE) {
    switch ($type) {
      case 'Integer':
      case 'Int':
      case 'Positive':
      case 'Float':
      case 'Money':
      case 'Date':
      case 'Timestamp':
      case 'ContactReference':
      case 'EntityReference':
      case 'MysqlOrderByDirection':
        $validatedData = self::validate($data, $type, $abort);
        if (isset($validatedData)) {
          return $validatedData;
        }
        break;

      // CRM-8925 for custom fields of this type
      case 'Country':
      case 'StateProvince':
        // Handle multivalued data in delimited or array format
        if (is_array($data) || (str_contains($data, CRM_Core_DAO::VALUE_SEPARATOR))) {
          $valid = TRUE;
          foreach (CRM_Utils_Array::explodePadded($data) as $item) {
            if (!CRM_Utils_Rule::positiveInteger($item)) {
              $valid = FALSE;
            }
          }
          if ($valid) {
            return $data;
          }
        }
        elseif (CRM_Utils_Rule::positiveInteger($data)) {
          return (int) $data;
        }
        break;

      case 'File':
        if (CRM_Utils_Rule::positiveInteger($data)) {
          return (int) $data;
        }
        break;

      case 'Link':
        if (CRM_Utils_Rule::url($data = trim($data))) {
          return $data;
        }
        break;

      case 'Boolean':
        if (CRM_Utils_Rule::boolean($data)) {
          return $data;
        }
        break;

      case 'String':
      case 'Memo':
      case 'Text':
        return CRM_Core_DAO::escapeString(self::validate($data, $type, $abort));

      case 'MysqlColumnNameOrAlias':
        if (CRM_Utils_Rule::mysqlColumnNameOrAlias($data)) {
          $data = str_replace('`', '', $data);
          $parts = explode('.', $data);
          $data = '`' . implode('`.`', $parts) . '`';

          return $data;
        }
        break;

      case 'MysqlOrderBy':
        if (CRM_Utils_Rule::mysqlOrderBy($data)) {
          $parts = explode(',', $data);

          // The field() syntax is tricky here because it uses commas & when
          // we separate by them we break it up. But we want to keep the clauses in order.
          // so we just clumsily re-assemble it. Test cover exists.
          $fieldClauseStart = NULL;
          foreach ($parts as $index => &$part) {
            if (substr($part, 0, 6) === 'field(') {
              // Looking to escape a string like 'field(contribution_status_id,3,4,5) asc'
              // to 'field(`contribution_status_id`,3,4,5) asc'
              $fieldClauseStart = $index;
              continue;
            }
            if ($fieldClauseStart !== NULL) {
              // this is part of the list of field options. Concatenate it back on.
              $parts[$fieldClauseStart] .= ',' . $part;
              unset($parts[$index]);
              if (!strstr($parts[$fieldClauseStart], ')')) {
                // we have not reached the end of the list.
                continue;
              }
              // We have the last piece of the field() clause, time to escape it.
              $parts[$fieldClauseStart] = self::mysqlOrderByFieldFunctionCallback($parts[$fieldClauseStart]);
              $fieldClauseStart = NULL;
              continue;

            }
            // Normal clause.
            $part = preg_replace_callback('/^(?:(?:((?:`[\w-]{1,64}`|[\w-]{1,64}))(?:\.))?(`[\w-]{1,64}`|[\w-]{1,64})(?: (asc|desc))?)$/i', ['CRM_Utils_Type', 'mysqlOrderByCallback'], trim($part));
          }
          return implode(', ', $parts);
        }
        break;

      default:
        throw new CRM_Core_Exception(
          $type . " is not a recognized (camel cased) data type."
        );
    }

    if ($abort) {
      $data = htmlentities($data);

      throw new CRM_Core_Exception("$data is not of the type $type");
    }
    return NULL;
  }

  /**
   * Verify that a variable is of a given type.
   *
   * @param mixed $data
   *   The value to validate.
   * @param string $type
   *   The type to validate against.
   * @param bool $abort
   *   If TRUE, the operation will CRM_Core_Error::fatal() on invalid data.
   * @param string $name
   *   The name of the attribute
   *
   * @return mixed
   *   The data, escaped if necessary
   *
   * @throws \CRM_Core_Exception
   */
  public static function validate($data, $type, $abort = TRUE, $name = 'One of parameters ') {

    $possibleTypes = [
      'Integer',
      'Int',
      'Positive',
      'CommaSeparatedIntegers',
      'Boolean',
      'Float',
      'Money',
      'Text',
      'String',
      'Link',
      'Memo',
      'Date',
      'Timestamp',
      'ContactReference',
      'EntityReference',
      'MysqlColumnNameOrAlias',
      'MysqlOrderByDirection',
      'MysqlOrderBy',
      'ExtensionKey',
      'Json',
      'Alphanumeric',
      'Color',
    ];
    if (!in_array($type, $possibleTypes)) {
      throw new CRM_Core_Exception('Invalid type, must be one of : ' . implode($possibleTypes));
    }
    switch ($type) {
      case 'Integer':
      case 'Int':
        if (CRM_Utils_Rule::integer($data)) {
          return (int) $data;
        }
        break;

      case 'Positive':
        if (CRM_Utils_Rule::positiveInteger($data)) {
          return (int) $data;
        }
        break;

      case 'Float':
      case 'Money':
        if (CRM_Utils_Rule::numeric($data)) {
          return $data;
        }
        break;

      case 'Text':
      case 'String':
      case 'Link':
      case 'Memo':
        return $data;

      case 'Date':
      case 'Timestamp':
        // a null timestamp is valid
        if (strlen(trim($data ?? '')) == 0) {
          return trim($data ?? '');
        }

        if ((preg_match('/^\d{14}$/', $data) ||
            preg_match('/^\d{8}$/', $data)
          ) &&
          CRM_Utils_Rule::mysqlDate($data)
        ) {
          return $data;
        }
        break;

      case 'ContactReference':
      case 'EntityReference':
        // null is valid
        if (strlen(trim($data)) == 0) {
          return trim($data);
        }

        if (CRM_Utils_Rule::positiveInteger($data)) {
          return (int) $data;
        }
        break;

      case 'MysqlOrderByDirection':
        if (CRM_Utils_Rule::mysqlOrderByDirection($data)) {
          return strtolower($data);
        }
        break;

      case 'ExtensionKey':
        if (CRM_Utils_Rule::checkExtensionKeyIsValid($data)) {
          return $data;
        }
        break;

      default:
        $check = lcfirst($type);
        if (CRM_Utils_Rule::$check($data)) {
          return $data;
        }
    }

    if ($abort) {
      // Note the string 'NULL' is just for display purposes here and to avoid
      // passing real null to htmlentities - it's not for database queries.
      $data = htmlentities($data ?? 'NULL');
      throw new CRM_Core_Exception("$name (value: $data) is not of the type $type");
    }

    return NULL;
  }

  /**
   * Validate that a value matches a PHP type.
   *
   * Note that, at a micro-level, this is probably slower than using real PHP type-checking, but it doesn't seem bad.
   * (In light benchmarking of ~1000 validations on an i3-10100, there is no obvious effect on the execution-time.)
   * Should be fast enough for validating business entities.
   *
   * Example usage: 'validatePhpType(123, 'int|double');`
   *
   * @param mixed $value
   * @param string|string[] $types
   *   The list of acceptable PHP types and/or classnames.
   *   Either an array or a string (with '|' delimiters).
   *   Note that 'null' is a distinct type.
   *   Ex: 'int'
   *   Ex: 'Countable|null'
   *   Ex: 'string|bool'
   *   Ex: 'string|false'
   * @param bool $isStrict
   *   If data is likely to come from another text medium, then you may want to
   *   allow (say) numbers and string-like-numbers to be used interchangably.
   *
   *   With $isStrict=TRUE, the string "123" does not match type "int". The int 456 does not match type "double". etc.
   *
   *   With $isStrict=FALSE, the string "123" will match types "string", "int", and "double".
   * @return bool
   */
  public static function validatePhpType($value, $types, bool $isStrict = TRUE) {
    if (is_string($types)) {
      $types = preg_split('/ *\| */', $types);
    }

    $checkTypeStrict = function($type, $value) {
      static $aliases = ['integer' => 'int', 'boolean' => 'bool', 'float' => 'double', 'NULL' => 'null'];
      switch ($type) {
        case 'mixed':
          return TRUE;

        case 'false':
        case 'FALSE':
        case 'true':
        case 'TRUE':
          $expectBool = mb_strtolower($type) === 'true';
          return $value === $expectBool;
      }
      $realType = gettype($value);
      if (($aliases[$realType] ?? $realType) === ($aliases[$type] ?? $type)) {
        return TRUE;
      }
      if ($realType === 'object' && $value instanceof $type) {
        return TRUE;
      }
      return FALSE;
    };
    $checkTypeRelaxed = function($type, $value) use ($checkTypeStrict) {
      switch ($type) {
        case 'string':
          return is_string($value) || is_int($value) || is_float($value);

        case 'bool':
        case 'boolean':
          return is_bool($value) || CRM_Utils_Rule::integer($value);

        case 'int':
        case' integer':
          return CRM_Utils_Rule::integer($value);

        case 'float':
        case 'double':
          return CRM_Utils_Rule::numeric($value);

        default:
          return $checkTypeStrict($type, $value);
      }
    };
    $checkType = $isStrict ? $checkTypeStrict : $checkTypeRelaxed;

    foreach ($types as $type) {
      $isTypedArray = substr($type, -2, 2) === '[]';
      if (!$isTypedArray && $checkType($type, $value)) {
        return TRUE;
      }
      if ($isTypedArray && is_array($value)) {
        $baseType = substr($type, 0, -2);
        foreach ($value as $vItem) {
          if (!\CRM_Utils_Type::validatePhpType($vItem, [$baseType], $isStrict)) {
            continue 2;
          }
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Preg_replace_callback for mysqlOrderByFieldFunction escape.
   *
   * Add backticks around the field name.
   *
   * @param string $clause
   *
   * @return string
   */
  public static function mysqlOrderByFieldFunctionCallback($clause) {
    return preg_replace('/field\((\w*)/', 'field(`${1}`', $clause);
  }

  /**
   * preg_replace_callback for MysqlOrderBy escape.
   */
  public static function mysqlOrderByCallback($matches) {
    $output = '';
    $matches = str_replace('`', '', $matches);

    // Table name.
    if (isset($matches[1]) && $matches[1]) {
      $output .= '`' . $matches[1] . '`.';
    }

    // Column name.
    if (isset($matches[2]) && $matches[2]) {
      $output .= '`' . $matches[2] . '`';
    }

    // Sort order.
    if (isset($matches[3]) && $matches[3]) {
      $output .= ' ' . $matches[3];
    }

    return $output;
  }

  /**
   * Get list of available Data Types for Option Groups
   *
   * @return array
   */
  public static function dataTypes() {
    $types = [
      'Integer',
      'String',
      'Date',
      'Time',
      'Timestamp',
      'Money',
      'Email',
    ];
    return array_combine($types, $types);
  }

  /**
   * Get all the types that are text-like.
   *
   * The returned types would all legitimately be compared to '' by mysql
   * in a query.
   *
   * e.g
   * WHERE display_name = '' is valid
   * WHERE id = '' is not and in some mysql configurations and queries
   * could cause an error.
   *
   * @return array
   */
  public static function getTextTypes(): array {
    return [
      self::T_STRING,
      self::T_ENUM,
      self::T_TEXT,
      self::T_LONGTEXT,
      self::T_BLOB,
      self::T_EMAIL,
      self::T_URL,
      self::T_MEDIUMBLOB,
    ];
  }

}
