<?php

use CRM_Membershipextrasimporterapi_EntityImporter_Contribution as ContributionImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_ContributionTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'contribution_external_id' => 'test1',
    'contribution_financial_type' => 'Member Dues',
    'contribution_payment_method' => 'EFT',
    'contribution_received_date' => '20190101013040',
    'contribution_status' => 'Pending',
    'contribution_currency' => 'USD',
  ];

  private $contactId;

  private $recurContributionId;

  private $testPaymentProcessorId = 1;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    $recurContributionParams = ['contact_id' => $this->contactId, 'amount' => 50, 'frequency_interval' => 1, 'payment_processor_id' => $this->testPaymentProcessorId];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];
  }

  public function testImportNewContribution() {
    $beforeImportIds = $this->getContributionsByContactId($this->contactId);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $afterImportIds = $this->getContributionsByContactId($this->contactId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newContributionId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportExistingContributionWillNotCreateNewOne() {
    $this->sampleRowData['contribution_external_id'] = 'test2';

    $firstImport = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $firstContributionId = $firstImport->import();

    $secondImport = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $secondContributionId = $secondImport->import();

    $this->assertEquals($firstContributionId, $secondContributionId);
  }

  public function testImportWillSetCorrectRecurContributionId() {
    $this->sampleRowData['membership_external_id'] = 'test3';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals($this->recurContributionId, $newContribution['contribution_recur_id']);
  }

  public function testImportWillCreateExternalIdCustomFieldRecord() {
    $this->sampleRowData['contribution_external_id'] = 'test4';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_ext_id WHERE external_id = %1 AND entity_id = {$newContributionId}";
    $contributionId = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->sampleRowData['contribution_external_id'], 'String']]);
    $contributionId->fetch();

    $this->assertEquals(1, $contributionId->N);
  }

  public function testImportWillSetCorrectFinancialTypeValue() {
    $this->sampleRowData['contribution_external_id'] = 'test5';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $expectedFTId = $this->getContributionFinancialTypeId($this->sampleRowData['contribution_financial_type']);

    $this->assertEquals($expectedFTId, $newContribution['financial_type_id']);
  }

  public function testImportWithInvalidFinancialTypeWillThrowAnException() {
    $this->sampleRowData['contribution_external_id'] = 'test6';
    $this->sampleRowData['contribution_financial_type'] = 'Invalid FT';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException::class);
    $this->expectExceptionCode(100);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionImporter->import();
  }

  public function testImportWillSetCorrectPaymentMethodValue() {
    $this->sampleRowData['contribution_external_id'] = 'test7';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $expectedPPId = $this->getContributionPaymentMethodId($this->sampleRowData['contribution_payment_method']);

    $this->assertEquals($expectedPPId, $newContribution['payment_instrument_id']);
  }

  public function testImportWithInvalidPaymentMethodWillThrowAnException() {
    $this->sampleRowData['contribution_external_id'] = 'test8';
    $this->sampleRowData['contribution_payment_method'] = 'Invalid PM';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException::class);
    $this->expectExceptionCode(200);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionImporter->import();
  }

  public function testImportWillSetCorrectReceiveDateValue() {
    $this->sampleRowData['contribution_external_id'] = 'test9';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $expectedDate = DateTime::createFromFormat('YmdHis', $this->sampleRowData['contribution_received_date']);
    $expectedDate = $expectedDate->format('Y-m-d H:i:s');

    $storedDate = DateTime::createFromFormat('Y-m-d H:i:s', $newContribution['receive_date']);
    $storedDate = $storedDate->format('Y-m-d H:i:s');

    $this->assertEquals($expectedDate, $storedDate);
  }

  public function testImportPendingContributionWillSetIsPayLaterFlagToTrue() {
    $this->sampleRowData['contribution_external_id'] = 'test10';
    $this->sampleRowData['contribution_status'] = 'Pending';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals(1, $newContribution['is_pay_later']);
  }

  public function testImportInProgressContributionWillSetIsPayLaterFlagToTrue() {
    $this->sampleRowData['contribution_external_id'] = 'test11';
    $this->sampleRowData['contribution_status'] = 'In Progress';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals(1, $newContribution['is_pay_later']);
  }

  public function testImportCompletedContributionWillSetIsPayLaterFlagToFalse() {
    $this->sampleRowData['contribution_external_id'] = 'test12';
    $this->sampleRowData['contribution_status'] = 'Completed';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals(0, $newContribution['is_pay_later']);
  }

  public function testImportWillSetCorrectStatusValue() {
    $this->sampleRowData['contribution_external_id'] = 'test13';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $expectedStatusId = $this->getContributionStatusId($this->sampleRowData['contribution_status']);

    $this->assertEquals($expectedStatusId, $newContribution['contribution_status_id']);
  }

  public function testImportWithoutStatusWillDefaultItToCompleted() {
    $this->sampleRowData['contribution_external_id'] = 'test14';
    unset($this->sampleRowData['contribution_status']);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $expectedStatusId = $this->getContributionStatusId('Completed');

    $this->assertEquals($expectedStatusId, $newContribution['contribution_status_id']);
  }

  public function testImportWithInvalidStatusWillThrowAnException() {
    $this->sampleRowData['contribution_external_id'] = 'test15';
    $this->sampleRowData['contribution_status'] = 'Invalid ST';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException::class);
    $this->expectExceptionCode(300);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionImporter->import();
  }

  public function testImportWillCreateCorrectFinancialTransactionRecords() {
    $this->sampleRowData['contribution_external_id'] = 'test16';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();
    $newContribution = $this->getContributionById($newContributionId);

    $contributionFinancialTrxn = $this->getContributionFinancialTransaction($newContributionId);

    $this->assertEquals($newContribution['total_amount'], $contributionFinancialTrxn->ef_amount);
    $this->assertEquals($newContribution['total_amount'], $contributionFinancialTrxn->f_amount);
    $this->assertEquals($newContribution['total_amount'], $contributionFinancialTrxn->f_net_amount);
    $this->assertEquals($newContribution['receive_date'], $contributionFinancialTrxn->f_trxn_date);
    $this->assertEquals($newContribution['currency'], $contributionFinancialTrxn->currency);
    $this->assertEquals($newContribution['contribution_status_id'], $contributionFinancialTrxn->status_id);
    $this->assertEquals($newContribution['payment_instrument_id'], $contributionFinancialTrxn->payment_instrument_id);
    $this->assertEquals($this->getPaymentProcessorFinancialAccountId($this->testPaymentProcessorId), $contributionFinancialTrxn->to_financial_account_id);
  }

  public function testImportPendingContributionWillCreateUnpaidTransaction() {
    $this->sampleRowData['contribution_external_id'] = 'test20';
    $this->sampleRowData['contribution_status'] = 'Pending';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $contributionFinancialTrxn = $this->getContributionFinancialTransaction($newContributionId);

    $this->assertEquals(0, $contributionFinancialTrxn->f_is_payment);
  }

  public function testImportCompletedContributionWillCreatePaidTransaction() {
    $this->sampleRowData['contribution_external_id'] = 'test21';
    $this->sampleRowData['contribution_status'] = 'Completed';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $contributionFinancialTrxn = $this->getContributionFinancialTransaction($newContributionId);

    $this->assertEquals(1, $contributionFinancialTrxn->f_is_payment);
  }

  public function testImportWithInvalidCurrencyThrowAnException() {
    $this->sampleRowData['contribution_external_id'] = 'test17';
    $this->sampleRowData['contribution_currency'] = 'FAKE';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException::class);
    $this->expectExceptionCode(400);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionImporter->import();
  }

  public function testImportWithInactiveCurrencyWillThrowAnException() {
    $this->sampleRowData['contribution_external_id'] = 'test18';
    $this->sampleRowData['contribution_currency'] = 'JOD';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException::class);
    $this->expectExceptionCode(400);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionImporter->import();
  }

  public function testImportSetsCorrectCurrencyValue() {
    $this->sampleRowData['contribution_external_id'] = 'test19';
    $this->sampleRowData['contribution_currency'] = 'USD';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals($this->sampleRowData['contribution_currency'], $newContribution['currency']);
  }

  public function testImportSetsCorrectInvoiceNumberIfItsProvided() {
    $this->sampleRowData['contribution_external_id'] = 'test19';
    $this->sampleRowData['contribution_invoice_number'] = 'RANDOM1234';

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals($this->sampleRowData['contribution_invoice_number'], $newContribution['invoice_number']);
  }

  public function testImportWillNotSetsInvoiceNumberIfInvoicingIsDisabledAndItsNotProvided() {
    $this->sampleRowData['contribution_external_id'] = 'test22';

    civicrm_api3('Setting', 'create', [
      'invoicing' => 0,
    ]);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals('', $newContribution['invoice_number']);
  }

  public function testImportSetsInvoiceNumberCorrectlyIfInvoicingIsEnabledAndItsNotProvided() {
    $this->sampleRowData['contribution_external_id'] = 'test23';

    civicrm_api3('Setting', 'create', [
      'invoicing' => 1,
    ]);

    $nextContributionID = CRM_Core_DAO::singleValueQuery('SELECT COALESCE(MAX(id) + 1, 1) FROM civicrm_contribution');
    $expectedInvoiceNumber = CRM_Contribute_BAO_Contribution::getInvoiceNumber($nextContributionID);

    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $newContributionId = $contributionImporter->import();

    $newContribution = $this->getContributionById($newContributionId);

    $this->assertEquals($expectedInvoiceNumber, $newContribution['invoice_number']);
  }

  private function getContributionsByContactId($contactId) {
    $contributionIds = NULL;

    $sqlQuery = "SELECT id FROM civicrm_contribution WHERE contact_id = %1";
    $contributions = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contactId, 'Integer']]);
    while ($contributions->fetch()) {
      $contributionIds[] = $contributions->id;
    }

    return $contributionIds;
  }

  private function getContributionById($id) {
    $sqlQuery = "SELECT * FROM civicrm_contribution WHERE id = %1";
    $contribution = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $contribution->fetch();

    return $contribution->toArray();
  }

  private function getContributionFinancialTypeId($ftName) {
    $sqlQuery = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$ftName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getContributionPaymentMethodId($pmName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'payment_instrument' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$pmName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getContributionStatusId($statusName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'contribution_status' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getPaymentProcessorFinancialAccountId($paymentProcessorId) {
    $sqlQuery = "SELECT financial_account_id FROM civicrm_entity_financial_account 
                   WHERE entity_table = 'civicrm_payment_processor' AND entity_id = {$paymentProcessorId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();
    return $result->financial_account_id;
  }

  private function getContributionFinancialTransaction($contributionId) {
    $sqlQuery = "SELECT ceft.amount as ef_amount, cft.total_amount as f_amount, cft.currency, cft.status_id, cft.payment_instrument_id, cft.to_financial_account_id,
                 cft.trxn_date as f_trxn_date, cft.net_amount as f_net_amount, cft.is_payment as f_is_payment  
                 FROM civicrm_entity_financial_trxn ceft
                 INNER JOIN civicrm_financial_trxn cft ON ceft.financial_trxn_id = cft.id 
                 WHERE ceft.entity_table = 'civicrm_contribution' AND ceft.entity_id = {$contributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();

    return $result;
  }

}
