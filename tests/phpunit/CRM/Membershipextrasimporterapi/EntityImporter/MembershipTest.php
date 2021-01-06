<?php

use CRM_Membershipextrasimporterapi_EntityImporter_Membership as MembershipImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_MembershipTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'membership_external_id' => 'test1',
    'membership_type' => 'Student',
    'membership_join_date' => '20180101000000',
    'membership_start_date' => '20190101000000',
    'membership_end_date' => '20191231000000',
    'membership_status' => 'Current',
    'membership_is_status_overridden' => 0,
  ];

  private $contactId;

  private $recurContributionId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    $recurContributionParams = ['contact_id' => $this->contactId, 'amount' => 50, 'frequency_interval' => 1];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];

    MembershipTypeFabricator::fabricate(['name' => 'Student', 'minimum_fee' => 50]);
  }

  public function testImportNewMembership() {
    $beforeImportIds = $this->getMembershipsByContactId($this->contactId);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $afterImportIds = $this->getMembershipsByContactId($this->contactId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newMembershipId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportExistingMembershipWillNotCreateNewOne() {
    $this->sampleRowData['payment_plan_external_id'] = 'test2';

    $firstImport = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $firstMembershipId = $firstImport->import();

    $secondImport = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $secondMembershipId = $secondImport->import();

    $this->assertEquals($firstMembershipId, $secondMembershipId);
  }

  public function testImportWillSetCorrectRecurContributionId() {
    $this->sampleRowData['membership_external_id'] = 'test3';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $this->assertEquals($this->recurContributionId, $newMembership['contribution_recur_id']);
  }

  public function testImportWillCreateExternalIdCustomFieldRecord() {
    $this->sampleRowData['membership_external_id'] = 'test4';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_membership_ext_id WHERE external_id = %1 AND entity_id = {$newMembershipId}";
    $membershipId = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->sampleRowData['membership_external_id'], 'String']]);
    $membershipId->fetch();

    $this->assertEquals(1, $membershipId->N);
  }

  public function testImportWillSetCorrectJoinDateValue() {
    $this->sampleRowData['membership_external_id'] = 'test5';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['membership_join_date']);
    $expectedDate = $expectedDate->format('Y-m-d');

    $storedDate = DateTime::createFromFormat('Y-m-d', $newMembership['join_date']);
    $storedDate = $storedDate->format('Y-m-d');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportWithNoJoinDateThrowException() {
    $this->sampleRowData['membership_external_id'] = 'test6';
    unset($this->sampleRowData['membership_join_date']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException::class);
    $this->expectExceptionCode(400);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $membershipImporter->import();
  }

  public function testImportWillSetCorrectStartDateValue() {
    $this->sampleRowData['membership_external_id'] = 'test7';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['membership_start_date']);
    $expectedDate = $expectedDate->format('Y-m-d');

    $storedDate = DateTime::createFromFormat('Y-m-d', $newMembership['start_date']);
    $storedDate = $storedDate->format('Y-m-d');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportWithNoStartDateThrowException() {
    $this->sampleRowData['membership_external_id'] = 'test8';
    unset($this->sampleRowData['membership_start_date']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException::class);
    $this->expectExceptionCode(400);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $membershipImporter->import();
  }

  public function testImportWillSetCorrectEndDateValue() {
    $this->sampleRowData['membership_external_id'] = 'test9';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['membership_end_date']);
    $expectedDate = $expectedDate->format('Y-m-d');

    $storedDate = DateTime::createFromFormat('Y-m-d', $newMembership['end_date']);
    $storedDate = $storedDate->format('Y-m-d');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportWithNoEndDateThrowException() {
    $this->sampleRowData['membership_external_id'] = 'test10';
    unset($this->sampleRowData['membership_end_date']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException::class);
    $this->expectExceptionCode(400);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $membershipImporter->import();
  }

  public function testImportWillSetCorrectMembershipTypeValue() {
    $this->sampleRowData['membership_external_id'] = 'test11';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedTypeId = $this->getMembershipTypeId($this->sampleRowData['membership_type']);

    $this->assertEquals($expectedTypeId, $newMembership['membership_type_id']);
  }

  public function testImportWithNoMembershipTypeThrowException() {
    $this->sampleRowData['membership_external_id'] = 'test12';
    unset($this->sampleRowData['membership_type']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException::class);
    $this->expectExceptionCode(100);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $membershipImporter->import();
  }

  public function testImportWillSetCorrectMembershipStatusValue() {
    $this->sampleRowData['membership_external_id'] = 'test13';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedStatusId = $this->getMembershipStatusId($this->sampleRowData['membership_status']);

    $this->assertEquals($expectedStatusId, $newMembership['status_id']);
  }

  public function testImportWithNoMembershipStatusWillAutomaticallyCalculateIt() {
    $this->sampleRowData['membership_external_id'] = 'test14';
    unset($this->sampleRowData['membership_status']);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $expectedStatusId = $this->getMembershipStatusId('Expired');

    $this->assertEquals($expectedStatusId, $newMembership['status_id']);
  }

  public function testImportWillSetCorrectIsStatusOverrideValue() {
    $this->sampleRowData['membership_external_id'] = 'test15';
    $this->sampleRowData['membership_is_status_overridden'] = CRM_Member_StatusOverrideTypes::PERMANENT;

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $this->assertEquals(CRM_Member_StatusOverrideTypes::PERMANENT, $newMembership['is_override']);
  }

  public function testImportWillDefaultIsStatusOverrideToNo() {
    $this->sampleRowData['membership_external_id'] = 'test16';
    unset($this->sampleRowData['membership_is_status_overridden']);

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $this->assertEquals(CRM_Member_StatusOverrideTypes::NO, $newMembership['is_override']);
  }

  public function testImportWithOverrideEndDateSetWillDefaultIsStatusOverrideToCorrectValue() {
    $this->sampleRowData['membership_external_id'] = 'test17';
    $this->sampleRowData['membership_status_override_end_date'] = '20300101000000';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $this->assertEquals(CRM_Member_StatusOverrideTypes::UNTIL_DATE, $newMembership['is_override']);
  }

  public function testImportWithOverrideEndDateWillSetItToCorrectValue() {
    $this->sampleRowData['membership_external_id'] = 'test18';
    $this->sampleRowData['membership_status_override_end_date'] = '20300101000000';

    $membershipImporter = new MembershipImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newMembershipId = $membershipImporter->import();

    $newMembership = $this->getMembershipById($newMembershipId);

    $this->assertEquals('2030-01-01', $newMembership['status_override_end_date']);
  }

  private function getMembershipsByContactId($contactId) {
    $membershipIds = NULL;

    $sqlQuery = "SELECT id FROM civicrm_membership WHERE contact_id = %1";
    $memberships = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contactId, 'Integer']]);
    while ($memberships->fetch()) {
      $membershipIds[] = $memberships->id;
    }

    return $membershipIds;
  }

  private function getMembershipById($id) {
    $sqlQuery = "SELECT * FROM civicrm_membership WHERE id = %1";
    $membership = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $membership->fetch();

    return $membership->toArray();
  }

  private function getMembershipTypeId($typeName) {
    $sqlQuery = "SELECT id FROM civicrm_membership_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$typeName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getMembershipStatusId($statusName) {
    $sqlQuery = "SELECT id FROM civicrm_membership_status WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();
    return $result->id;
  }

}
