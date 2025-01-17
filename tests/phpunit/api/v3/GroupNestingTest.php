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
 * Test class for GroupNesting API - civicrm_group_nesting_*
 *
 * @package   CiviCRM
 * @group headless
 */
class api_v3_GroupNestingTest extends CiviUnitTestCase {

  /**
   * Sets up the fixture, for example, opens a network connection.
   *
   * This method is called before a test is executed.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->ids['Group'] = [];
    $this->ids['Group']['parent'] = $this->callAPISuccess('Group', 'create', [
      'name' => 'Administrators',
      'title' => 'Administrators',
    ])['id'];
    $this->ids['Group']['child'] = $this->callAPISuccess('Group', 'create', [
      'name' => 'Newsletter Subscribers',
      'title' => 'Newsletter Subscribers',
      'parents' => $this->ids['Group']['parent'],
    ])['id'];
    $this->ids['Group']['child2'] = $this->callAPISuccess('Group', 'create', [
      'name' => 'Another Newsletter Subscribers',
      'title' => 'Another Newsletter Subscribers',
      'parents' => $this->ids['Group']['parent'],
    ])['id'];
    $this->ids['Group']['child3'] = $this->callAPISuccess('Group', 'create', [
      'name' => 'Super Special Newsletter Subscribers',
      'title' => 'Super Special Newsletter Subscribers',
      'parents' => [$this->ids['Group']['parent'], $this->ids['Group']['child']],
    ])['id'];

  }

  /**
   * Tears down the fixture.
   *
   * This method is called after a test is executed.
   *
   * @throws \Exception
   */
  protected function tearDown(): void {
    $this->quickCleanup(
      [
        'civicrm_group',
        'civicrm_group_nesting',
        'civicrm_contact',
        'civicrm_uf_join',
        'civicrm_uf_match',
      ]
    );
    parent::tearDown();
  }

  /**
   * Test civicrm_group_nesting_get with just one param (child_group_id).
   *
   * @dataProvider versionThreeAndFour
   */
  public function testGetWithChildGroupId(): void {
    $params = [
      'child_group_id' => $this->ids['Group']['child3'],
    ];

    $result = $this->callAPISuccess('group_nesting', 'get', $params);

    // expected data loaded in setUp
    $expected = [
      3 => [
        'id' => 3,
        'child_group_id' => $this->ids['Group']['child3'],
        'parent_group_id' => $this->ids['Group']['parent'],
      ],
      4 => [
        'id' => 4,
        'child_group_id' => $this->ids['Group']['child3'],
        'parent_group_id' => $this->ids['Group']['child'],
      ],
    ];

    $this->assertEquals($expected, $result['values']);
  }

  /**
   * Test civicrm_group_nesting_get with just one param (parent_group_id).
   *
   * @dataProvider versionThreeAndFour
   */
  public function testGetWithParentGroupId(): void {
    $params = [
      'parent_group_id' => $this->ids['Group']['parent'],
    ];

    $result = $this->callAPISuccess('group_nesting', 'get', $params);

    // expected data loaded in setUp
    $expected = [
      1 => [
        'id' => 1,
        'child_group_id' => $this->ids['Group']['child'],
        'parent_group_id' => $this->ids['Group']['parent'],
      ],
      2 => [
        'id' => 2,
        'child_group_id' => $this->ids['Group']['child2'],
        'parent_group_id' => $this->ids['Group']['parent'],
      ],
      3 => [
        'id' => 3,
        'child_group_id' => $this->ids['Group']['child3'],
        'parent_group_id' => $this->ids['Group']['parent'],
      ],
    ];

    $this->assertEquals($expected, $result['values']);
  }

  /**
   * Test civicrm_group_nesting_create.
   *
   * @throws \Exception
   *
   * @dataProvider versionThreeAndFour
   */
  public function testCreate(): void {
    $params = [
      'parent_group_id' => $this->ids['Group']['parent'],
      'child_group_id' => $this->ids['Group']['child2'],
    ];

    $this->callAPISuccess('group_nesting', 'create', $params);
    $this->callAPISuccessGetCount('GroupNesting', $params, 1);
  }

}
