<?php

use CRM_Membershipextrasimporterapi_EntityImporter_DirectDebitMandate as DirectDebitMandateImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;
use CRM_MembershipExtras_Test_Fabricator_Contribution as ContributionFabricator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_DirectDebitMandateTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'payment_plan_payment_processor' => 'Direct Debit',
    'contribution_payment_method' => 'direct_debit',
    'direct_debit_mandate_reference' => 'Civi00001',
    'direct_debit_mandate_bank_name' => 'Test Bank',
    'direct_debit_mandate_account_holder' => 'Test Account Holder',
    'direct_debit_mandate_account_number' => '12345678',
    'direct_debit_mandate_sort_code' => '123456',
    'direct_debit_mandate_code' => '0C',
    'direct_debit_mandate_start_date' => '20200101000000',
    'direct_debit_mandate_originator_number' => 'Test Originator',
  ];

  private $contactId;

  private $recurContributionId;

  private $contributionId;

  private $testOriginatorNumberId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    $recurContributionParams = ['contact_id' => $this->contactId, 'amount' => 50, 'frequency_interval' => 1, 'payment_processor_id' => 1];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];

    $contributionParams = [
      'contact_id' => $this->contactId,
      'financial_type_id' => 'Member Dues',
      'receive_date' => date('Y-m-d'),
      'total_amount' => 50,
      'skipLineItem' => 1,
      'recur_contribution_id' => $this->recurContributionId,
    ];
    $this->contributionId = ContributionFabricator::fabricate($contributionParams)['id'];

    $this->setTestOriginatorNumber();
  }

  private function setTestOriginatorNumber() {
    $result = civicrm_api3('OptionValue', 'create', [
      'sequential' => 1,
      'option_group_id' => 'direct_debit_originator_number',
      'label' => 'Test Originator',
      'name' => 'Test Originator',
      'value' => 1,
    ]);

    $this->testOriginatorNumberId = $result['values'][0]['value'];
  }

  public function testImportWithNoDirectDebitPaymentProcessorWillNotCreateMandate() {
    $this->sampleRowData['payment_plan_payment_processor'] = 'Paypal';

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $this->assertEmpty($newMandateId);
  }

  public function testImportWithNoDirectDebitReferenceThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_reference']);
    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(100);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportNewDirectDebitMandate() {
    $beforeImportIds = $this->getMandateIdByReference($this->sampleRowData['direct_debit_mandate_reference']);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $afterImportIds = $this->getMandateIdByReference($this->sampleRowData['direct_debit_mandate_reference']);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newMandateId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportWithNoBankNameThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_bank_name']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(200);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectBankNameValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->sampleRowData['direct_debit_mandate_bank_name'], $mandate['bank_name']);
  }

  public function testImportWithNoAccountHolderThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_account_holder']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(300);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectAccountHolderValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->sampleRowData['direct_debit_mandate_account_holder'], $mandate['account_holder_name']);
  }

  public function testImportWithNoAccountNumberThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_account_number']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(400);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithAccountNumberMoreThan8CharsThrowException() {
    $this->sampleRowData['direct_debit_mandate_account_number'] = '123456789';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(500);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithAccountNumberLessThan8CharsThrowException() {
    $this->sampleRowData['direct_debit_mandate_account_number'] = '1234567';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(500);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectAccountNumberValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->sampleRowData['direct_debit_mandate_account_number'], $mandate['ac_number']);
  }

  public function testImportWithNoSortCodeThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_sort_code']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(600);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithSortCodeMoreThan6CharsThrowException() {
    $this->sampleRowData['direct_debit_mandate_sort_code'] = '1234567';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(700);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithSortCodeLessThan6CharsThrowException() {
    $this->sampleRowData['direct_debit_mandate_sort_code'] = '12345';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(700);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectSortCodeValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->sampleRowData['direct_debit_mandate_sort_code'], $mandate['sort_code']);
  }

  public function testImportWithNoDDCodeThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_code']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(800);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithInvalidDDCodeThrowException() {
    $this->sampleRowData['direct_debit_mandate_code'] = 'XYZ';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(900);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectDDCodeValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $code0CId = 4;
    $this->assertEquals($code0CId, $mandate['dd_code']);
  }

  public function testImportWithNoStartDateThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_start_date']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(1200);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectStartDateValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $expectedStartDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['direct_debit_mandate_start_date']);
    $this->assertEquals($expectedStartDate->format('Y-m-d 00:00:00'), $mandate['start_date']);
  }

  public function testImportWithNoOriginatorNumberThrowException() {
    unset($this->sampleRowData['direct_debit_mandate_originator_number']);

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(1000);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportWithInvalidOriginatorNumberThrowException() {
    $this->sampleRowData['direct_debit_mandate_originator_number'] = 'Invalid ON';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException::class);
    $this->expectExceptionCode(1100);

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();
  }

  public function testImportSetsCorrectOriginatorNumberValue() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->testOriginatorNumberId, $mandate['originator_number']);
  }

  public function testImportSetsCorrectContactId() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandate = $this->getMandateById($newMandateId);

    $this->assertEquals($this->contactId, $mandate['entity_id']);
  }

  public function testImportNewCreatesRecurContributionReferenceRecord() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandates = $this->getMandateRecurContributionReferences();

    $this->assertCount(1, $mandates);
    $this->assertEquals($newMandateId, $mandates[0]);
  }

  public function testImportNewCreatesContributionReferenceRecord() {
    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $newMandateId = $mandateImporter->import();

    $mandates = $this->getMandateContributionReferences();

    $this->assertCount(1, $mandates);
    $this->assertEquals($newMandateId, $mandates[0]);
  }

  public function testImportWillNotCreateContributionReferenceRecordIfContributionPaymentMethodIsNotDirectDebit() {
    $this->sampleRowData['contribution_payment_method'] = 'EFT';

    $mandateImporter = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $mandateImporter->import();

    $mandates = $this->getMandateContributionReferences();

    $this->assertNull($mandates);
  }

  public function testImportExistingMandateWillUpdateItCorrectly() {
    $firstImport = new DirectDebitMandateImporter($this->sampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $firstMandateId = $firstImport->import();

    $updatedSampleRowData = [
      'payment_plan_payment_processor' => 'Direct Debit',
      'contribution_payment_method' => 'direct_debit',
      'direct_debit_mandate_reference' => 'Civi00001',
      'direct_debit_mandate_bank_name' => 'Test Bank - Updated',
      'direct_debit_mandate_account_holder' => 'Test Account Holder - Updated',
      'direct_debit_mandate_account_number' => '87654321',
      'direct_debit_mandate_sort_code' => '654321',
      'direct_debit_mandate_code' => '0N',
      'direct_debit_mandate_start_date' => '20220101000000',
      'direct_debit_mandate_originator_number' => 'Test Originator',
    ];
    $secondImport = new DirectDebitMandateImporter($updatedSampleRowData, $this->contactId, $this->recurContributionId, $this->contributionId);
    $secondImport->import();

    $expectedResult = [
      'mandate_reference' => 'Civi00001',
      'bank_name' => 'Test Bank - Updated',
      'account_holder' => 'Test Account Holder - Updated',
      'account_number' => '87654321',
      'sort_code' => '654321',
      'mandate_code' => 1,
      'start_date' => '2022-01-01 00:00:00',
    ];

    $updatedMandate = $this->getMandateById($firstMandateId);
    $actualResult = [
      'mandate_reference' => $updatedMandate['dd_ref'],
      'bank_name' => $updatedMandate['bank_name'],
      'account_holder' => $updatedMandate['account_holder_name'],
      'account_number' => $updatedMandate['ac_number'],
      'sort_code' => $updatedMandate['sort_code'],
      'mandate_code' => $updatedMandate['dd_code'],
      'start_date' => $updatedMandate['start_date'],
    ];

    $this->assertEquals($expectedResult, $actualResult);
  }

  private function getMandateIdByReference($mandateReference) {
    $sql = "SELECT id FROM civicrm_value_dd_mandate WHERE dd_ref = %1";
    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [$mandateReference, 'String'],
    ]);

    $dao->fetch();
    if (!empty($dao->id)) {
      return $dao->id;
    }

    return NULL;
  }

  private function getMandateById($mandateId) {
    $sql = "SELECT * FROM civicrm_value_dd_mandate WHERE id = {$mandateId}";
    $dao = CRM_Core_DAO::executeQuery($sql);

    $dao->fetch();
    if (!empty($dao->id)) {
      return $dao->toArray();
    }

    return NULL;
  }

  private function getMandateRecurContributionReferences() {
    $mandateIds = NULL;

    $sqlQuery = "SELECT mandate_id FROM dd_contribution_recurr_mandate_ref WHERE recurr_id = %1";
    $mandates = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->recurContributionId, 'Integer']]);
    while ($mandates->fetch()) {
      $mandateIds[] = $mandates->mandate_id;
    }

    return $mandateIds;
  }

  private function getMandateContributionReferences() {
    $mandateIds = NULL;

    $sqlQuery = "SELECT mandate_id FROM civicrm_value_dd_information WHERE entity_id = %1";
    $mandates = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->contributionId, 'Integer']]);
    while ($mandates->fetch()) {
      $mandateIds[] = $mandates->mandate_id;
    }

    return $mandateIds;
  }

}
