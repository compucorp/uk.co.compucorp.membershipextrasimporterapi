<?php

use CRM_Membershipextrasimporterapi_EntityImporter_RecurContribution as RecurContributionImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;

/**
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_RecurContributionTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'payment_plan_external_id' => 'test1',
    'payment_plan_payment_processor' => 'Offline Recurring Contribution',
    'payment_plan_frequency' => 'month',
    'payment_plan_next_contribution_date' => '20210101000000',
    'payment_plan_start_date' => '20200101000000',
    'payment_plan_create_date' => '20190101000000',
    'payment_plan_cycle_day' => 15,
    'payment_plan_auto_renew' => 0,
    'payment_plan_financial_type' => 'Member Dues',
    'payment_plan_payment_method' => 'EFT',
    'payment_plan_status' => 'Pending',
    'payment_plan_currency' => 'USD',
  ];

  private $contactId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];
  }

  public function testImportNewRecurContribution() {
    $beforeImportIds = $this->getRecurContributionsByContactId($this->contactId);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $afterImportIds = $this->getRecurContributionsByContactId($this->contactId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newRecurContributionId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportExistingRecurContributionWillNotCreateNewOne() {
    $this->sampleRowData['payment_plan_external_id'] = 'test2';

    $firstImport = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $firstRecurContributionId = $firstImport->import();

    $secondImport = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $secondRecurContributionId = $secondImport->import();

    $this->assertEquals($firstRecurContributionId, $secondRecurContributionId);
  }

  public function testImportWillCreateExternalIdCustomFieldRecord() {
    $this->sampleRowData['payment_plan_external_id'] = 'test3';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_recur_ext_id WHERE external_id = %1 AND entity_id = {$newRecurContributionId}";
    $recurContributionId = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->sampleRowData['payment_plan_external_id'], 'String']]);
    $recurContributionId->fetch();

    $this->assertEquals(1, $recurContributionId->N);
  }

  public function testImportMonthlyRecurContributionWillSetCorrectFrequencyInformation() {
    $this->sampleRowData['payment_plan_external_id'] = 'test5';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $correctFrequencyInformation = ($newRecurContribution['frequency_unit'] == 'month') && ($newRecurContribution['frequency_interval'] == 1) && ($newRecurContribution['installments'] == 12);
    $this->assertTrue($correctFrequencyInformation);
  }

  public function testImportYearlyRecurContributionWillSetCorrectFrequencyInformation() {
    $this->sampleRowData['payment_plan_external_id'] = 'test6';
    $this->sampleRowData['payment_plan_frequency'] = 'year';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $correctFrequencyInformation = ($newRecurContribution['frequency_unit'] == 'year') && ($newRecurContribution['frequency_interval'] == 1) && ($newRecurContribution['installments'] == 1);
    $this->assertTrue($correctFrequencyInformation);
  }

  public function testImportWithIncorrectFrequencyWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test7';
    $this->sampleRowData['payment_plan_frequency'] = 'week';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(100);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWithNoStatusWillDefaultToCompleted() {
    $this->sampleRowData['payment_plan_external_id'] = 'test8';
    unset($this->sampleRowData['payment_plan_status']);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $completedRecurContributionStatusId = $this->getRecurContributionStatusId('Completed');
    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($completedRecurContributionStatusId, $newRecurContribution['contribution_status_id']);
  }

  public function testImportWillSetCorrectStatusValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test9';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $recurContributionStatusId = $this->getRecurContributionStatusId($this->sampleRowData['payment_plan_status']);
    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($recurContributionStatusId, $newRecurContribution['contribution_status_id']);
  }

  public function testImportWithIncorrectStatusWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test10';
    $this->sampleRowData['payment_plan_status'] = 'invalid-status';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(200);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWillSetCorrectPaymentProcessorValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test11';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $recurContributionPPId = $this->getRecurContributionPaymentProcessorId($this->sampleRowData['payment_plan_payment_processor']);
    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($recurContributionPPId, $newRecurContribution['payment_processor_id']);
  }

  public function testImportWithIncorrectPaymentProcessorWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test12';
    $this->sampleRowData['payment_plan_payment_processor'] = 'invalid-pp';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(300);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWillSetCorrectFinancialTypeValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test13';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $recurContributionFTId = $this->getRecurContributionFinancialTypeId($this->sampleRowData['payment_plan_financial_type']);
    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($recurContributionFTId, $newRecurContribution['financial_type_id']);
  }

  public function testImportWithIncorrectFinancialTypeWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test14';
    $this->sampleRowData['payment_plan_financial_type'] = 'invalid-ft';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(400);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWillSetCorrectPaymentMethodValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test15';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $recurContributionPMId = $this->getRecurContributionPaymentMethodId($this->sampleRowData['payment_plan_payment_method']);
    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($recurContributionPMId, $newRecurContribution['payment_instrument_id']);
  }

  public function testImportWithIncorrectPaymentMethodWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test16';
    $this->sampleRowData['payment_plan_payment_method'] = 'invalid-pm';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(500);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWillDefaultToNonAutoRenewalRecurContribution() {
    $this->sampleRowData['payment_plan_external_id'] = 'test17';
    unset($this->sampleRowData['payment_plan_auto_renew']);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals(0, $newRecurContribution['auto_renew']);
  }

  public function testImportWithAutoRenewalOnWillSetCorrectAutoRenewValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test18';
    $this->sampleRowData['payment_plan_auto_renew'] = 1;

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals(1, $newRecurContribution['auto_renew']);
  }

  public function testImportYearlyRecurContributionWillNotSetCycleDay() {
    $this->sampleRowData['payment_plan_external_id'] = 'test19';
    $this->sampleRowData['payment_plan_frequency'] = 'year';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEmpty($newRecurContribution['cycle_day']);
  }

  public function testImportMonthlyRecurContributionWithNoCycleDayWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test20';
    $this->sampleRowData['payment_plan_frequency'] = 'month';
    unset($this->sampleRowData['payment_plan_cycle_day']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(600);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportMonthlyRecurContributionWithCycleDayLessThanOneWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test21';
    $this->sampleRowData['payment_plan_frequency'] = 'month';
    $this->sampleRowData['payment_plan_cycle_day'] = -1;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(700);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportMonthlyRecurContributionWithCycleDayEqual28WillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test22';
    $this->sampleRowData['payment_plan_frequency'] = 'month';
    $this->sampleRowData['payment_plan_cycle_day'] = 28;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(700);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportMonthlyRecurContributionWithCycleDayLargerThan28WillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test23';
    $this->sampleRowData['payment_plan_frequency'] = 'month';
    $this->sampleRowData['payment_plan_cycle_day'] = 29;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(700);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportMonthlyRecurContributionWillSetCorrectCycleDayValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test24';
    $this->sampleRowData['payment_plan_frequency'] = 'month';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($this->sampleRowData['payment_plan_cycle_day'], $newRecurContribution['cycle_day']);
  }

  public function testImportWillSetCorrectStartDateValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test25';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['payment_plan_start_date']);
    $expectedDate = $expectedDate->format('Y-m-d H:i:s');

    $this->assertEquals($expectedDate, $newRecurContribution['start_date']);
  }

  public function testImportWithNoStartDateWillDefaultToTodayDate() {
    $this->sampleRowData['payment_plan_external_id'] = 'test26';
    unset($this->sampleRowData['payment_plan_start_date']);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $expectedDate = new DateTime();
    $expectedDate = $expectedDate->format('Y-m-d');

    $storedDate = DateTime::createFromFormat('Y-m-d H:i:s', $newRecurContribution['start_date']);
    $storedDate = $storedDate->format('Y-m-d');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportWillSetCorrectCreateDateValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test27';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['payment_plan_create_date']);
    $expectedDate = $expectedDate->format('Y-m-d H:i:s');

    $this->assertEquals($expectedDate, $newRecurContribution['create_date']);
  }

  public function testImportWithNoCreateDateWillDefaultToTodayDate() {
    $this->sampleRowData['payment_plan_external_id'] = 'test28';
    unset($this->sampleRowData['payment_plan_create_date']);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $expectedDate = new DateTime();
    $expectedDate = $expectedDate->format('Y-m-d');

    $storedDate = DateTime::createFromFormat('Y-m-d H:i:s', $newRecurContribution['create_date']);
    $storedDate = $storedDate->format('Y-m-d');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportWillSetCorrectNextContributionDateValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test29';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['payment_plan_next_contribution_date']);
    $expectedDate = $expectedDate->format('Y-m-d H:i:s');

    $this->assertEquals($expectedDate, $newRecurContribution['next_sched_contribution_date']);
  }

  public function testImportWithNoNextContributionDateWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test30';
    unset($this->sampleRowData['payment_plan_next_contribution_date']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(800);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWithInvalidCurrencyThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test31';
    $this->sampleRowData['payment_plan_currency'] = 'FAKE';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(900);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWithInactiveCurrencyWillThrowAnException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test32';
    $this->sampleRowData['payment_plan_currency'] = 'JOD';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(900);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportSetsCorrectCurrencyValue() {
    $this->sampleRowData['payment_plan_external_id'] = 'test33';
    $this->sampleRowData['payment_plan_currency'] = 'USD';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $newRecurContribution = $this->getRecurContributionsById($newRecurContributionId);

    $this->assertEquals($this->sampleRowData['payment_plan_currency'], $newRecurContribution['currency']);
  }

  public function testImportDirectDebitPaymentPlanShouldHaveBothPaymentProcessorAndPaymentMethodAsDirectDebit() {
    $this->sampleRowData['payment_plan_external_id'] = 'test34';
    $this->sampleRowData['payment_plan_payment_processor'] = 'Direct Debit';
    $this->sampleRowData['payment_plan_payment_method'] = 'direct_debit';

    $beforeImportIds = $this->getRecurContributionsByContactId($this->contactId);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $afterImportIds = $this->getRecurContributionsByContactId($this->contactId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newRecurContributionId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportDirectDebitPaymentPlanWithPaymentMethodThatIsNotDirectDebitThrowException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test35';
    $this->sampleRowData['payment_plan_payment_processor'] = 'Direct Debit';
    $this->sampleRowData['payment_plan_payment_method'] = 'EFT';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(1000);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportDirectDebitPaymentPlanWithPaymentProcessorThatIsNotDirectDebitThrowException() {
    $this->sampleRowData['payment_plan_external_id'] = 'test35';
    $this->sampleRowData['payment_plan_payment_processor'] = 'Offline Recurring Contribution';
    $this->sampleRowData['payment_plan_payment_method'] = 'direct_debit';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(1100);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWithNonManualPaymentProcessorThrowException() {
    civicrm_api3('PaymentProcessor', 'create', [
      'payment_processor_type_id' => 'Dummy',
      'financial_account_id' => 'Payment Processor Account',
      'name' => 'Test Processor',
      'is_active' => 1,
      'is_test' => 0,
      'class_name' => 'Payment_Dummy',
    ]);

    $this->sampleRowData['payment_plan_external_id'] = 'test36';
    $this->sampleRowData['payment_plan_payment_processor'] = 'Test Processor';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException::class);
    $this->expectExceptionCode(1200);

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionImporter->import();
  }

  public function testImportWillSetDefaultActiveStatusToFalse() {
    $this->sampleRowData['payment_plan_external_id'] = 'test37';

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $sqlQuery = "SELECT is_active FROM civicrm_value_payment_plan_extra_attributes WHERE entity_id = {$newRecurContributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();

    $this->assertEquals(0, $result->is_active);
  }

  public function testImportWillSetActiveStatus() {
    $this->sampleRowData['payment_plan_external_id'] = 'test37';
    $this->sampleRowData['payment_plan_is_active'] = 1;

    $recurContributionImporter = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $newRecurContributionId = $recurContributionImporter->import();

    $sqlQuery = "SELECT is_active FROM civicrm_value_payment_plan_extra_attributes WHERE entity_id = {$newRecurContributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();

    $this->assertEquals(1, $result->is_active);
  }

  public function testImportExistingRecurContributionWillUpdateItCorrectly() {
    $this->sampleRowData['payment_plan_external_id'] = 'test38';

    $firstImport = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $firstRecurContributionId = $firstImport->import();

    $updatedSampleRowData = [
      'payment_plan_external_id' => 'test38',
      'payment_plan_payment_processor' => 'Offline Recurring Contribution',
      'payment_plan_frequency' => 'year',
      'payment_plan_next_contribution_date' => '20220101000000',
      'payment_plan_start_date' => '20210101000000',
      'payment_plan_create_date' => '20200101000000',
      'payment_plan_cycle_day' => 30,
      'payment_plan_auto_renew' => 1,
      'payment_plan_financial_type' => 'Donation',
      'payment_plan_payment_method' => 'Cash',
      'payment_plan_status' => 'Completed',
      'payment_plan_currency' => 'USD',
    ];
    $secondImport = new RecurContributionImporter($updatedSampleRowData, $this->contactId);
    $secondImport->import();

    $updatedRecurContribution = $this->getRecurContributionsById($firstRecurContributionId);

    $expectedResult = [
      'frequency_unit' => 'year',
      'frequency_interval' => 1,
      'installments' => 1,
      'next_contribution_date' => '2022-01-01 00:00:00',
      'start_date' => '2021-01-01 00:00:00',
      // cycle day should not be set for Yearly payment plan
      'cycle_day' => 0,
      'auto_renew' => 1,
      'financial_type' => $this->getRecurContributionFinancialTypeId('Donation'),
      'payment_method' => $this->getRecurContributionPaymentMethodId('Cash'),
      'status' => $this->getRecurContributionStatusId('Completed'),
    ];

    $actualResult = [
      'frequency_unit' => $updatedRecurContribution['frequency_unit'],
      'frequency_interval' => (int) $updatedRecurContribution['frequency_interval'],
      'installments' => (int) $updatedRecurContribution['installments'],
      'next_contribution_date' => $updatedRecurContribution['next_sched_contribution_date'],
      'start_date' => $updatedRecurContribution['start_date'],
      'cycle_day' => (int) $updatedRecurContribution['cycle_day'],
      'auto_renew' => (int) $updatedRecurContribution['auto_renew'],
      'financial_type' => $updatedRecurContribution['financial_type_id'],
      'payment_method' => $updatedRecurContribution['payment_instrument_id'],
      'status' => $updatedRecurContribution['contribution_status_id'],
    ];

    $this->assertEquals($expectedResult, $actualResult);
  }

  private function getRecurContributionsByContactId($contactId) {
    $recurContributionIds = NULL;

    $sqlQuery = "SELECT id FROM civicrm_contribution_recur WHERE contact_id = %1";
    $recurContributions = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contactId, 'Integer']]);
    while ($recurContributions->fetch()) {
      $recurContributionIds[] = $recurContributions->id;
    }

    return $recurContributionIds;
  }

  private function getRecurContributionsById($id) {
    $recurContributionIds = NULL;

    $sqlQuery = "SELECT * FROM civicrm_contribution_recur WHERE id = %1";
    $recurContribution = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $recurContribution->fetch();

    return $recurContribution->toArray();
  }

  private function getRecurContributionStatusId($statusName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'contribution_recur_status' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getRecurContributionPaymentProcessorId($ppName) {
    $sqlQuery = "SELECT id FROM civicrm_payment_processor WHERE name = %1 and is_test = 0";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$ppName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getRecurContributionFinancialTypeId($ftName) {
    $sqlQuery = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$ftName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getRecurContributionPaymentMethodId($pmName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'payment_instrument' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$pmName, 'String']]);
    $result->fetch();
    return $result->id;
  }

}
