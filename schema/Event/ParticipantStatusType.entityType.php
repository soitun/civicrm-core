<?php

return [
  'name' => 'ParticipantStatusType',
  'table' => 'civicrm_participant_status_type',
  'class' => 'CRM_Event_DAO_ParticipantStatusType',
  'getInfo' => fn() => [
    'title' => ts('Participant Status Type'),
    'title_plural' => ts('Participant Status Types'),
    'description' => ts('various types of CiviEvent participant statuses'),
    'log' => TRUE,
    'add' => '3.0',
    'label_field' => 'label',
  ],
  'getPaths' => fn() => [
    'add' => 'civicrm/admin/participant_status?action=add&reset=1',
    'update' => 'civicrm/admin/participant_status?action=update&id=[id]&reset=1',
    'delete' => 'civicrm/admin/participant_status?action=delete&id=[id]&reset=1',
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => ts('Participant Status Type ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => ts('unique participant status type id'),
      'add' => '3.0',
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'name' => [
      'title' => ts('Participant Status'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => ts('non-localized name of the status type'),
      'add' => '3.0',
      'unique_name' => 'participant_status',
      'usage' => [
        'import',
        'export',
        'duplicate_matching',
      ],
    ],
    'label' => [
      'title' => ts('Participant Status Label'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'localizable' => TRUE,
      'description' => ts('localized label for display of this status type'),
      'add' => '3.0',
    ],
    'class' => [
      'title' => ts('Participant Status Class'),
      'sql_type' => 'varchar(8)',
      'input_type' => 'Select',
      'description' => ts('the general group of status type this one belongs to'),
      'add' => '3.0',
      'pseudoconstant' => [
        'callback' => ['CRM_Event_PseudoConstant', 'participantStatusClassOptions'],
      ],
    ],
    'is_reserved' => [
      'title' => ts('Participant Status Is Reserved?'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'required' => TRUE,
      'description' => ts('whether this is a status type required by the system'),
      'add' => '3.0',
      'default' => FALSE,
    ],
    'is_active' => [
      'title' => ts('Participant Status is Active'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'required' => TRUE,
      'description' => ts('whether this status type is active'),
      'add' => '3.0',
      'default' => TRUE,
      'input_attrs' => [
        'label' => ts('Enabled'),
      ],
    ],
    'is_counted' => [
      'title' => ts('Participant Status Counts?'),
      'sql_type' => 'boolean',
      'input_type' => 'CheckBox',
      'required' => TRUE,
      'description' => ts('whether this status type is counted against event size limit'),
      'add' => '3.0',
      'default' => FALSE,
    ],
    'weight' => [
      'title' => ts('Order'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => ts('controls sort order'),
      'add' => '3.0',
    ],
    'visibility_id' => [
      'title' => ts('Participant Status Visibility'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => ts('whether the status type is visible to the public, an implicit foreign key to option_value.value related to the `visibility` option_group'),
      'add' => '3.0',
      'pseudoconstant' => [
        'option_group_name' => 'visibility',
      ],
    ],
  ],
];
