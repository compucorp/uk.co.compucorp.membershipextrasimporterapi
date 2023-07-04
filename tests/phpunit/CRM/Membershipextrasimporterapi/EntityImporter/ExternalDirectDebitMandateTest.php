<?php

use CRM_Membershipextrasimporterapi_EntityImporter_ExternalDirectDebitMandate as ExternalDirectDebitMandateImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_ExternalDirectDebitMandateTest extends BaseHeadlessTest {

  private $contactId;

  private $recurContributionId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    $recurContributionParams = ['contact_id' => $this->contactId, 'amount' => 50, 'frequency_interval' => 1, 'payment_processor_id' => 1];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];
  }

  public function testImportNewExternalDirectDebitMandate() {
    $rowData = [
      'external_direct_debit_mandate_id' => 'TEST_X13',
      'external_direct_debit_mandate_status' => 1,
      'external_direct_debit_next_available_payment_date' => '20230101000000',
    ];
    $mandateImporter = new ExternalDirectDebitMandateImporter($rowData, $this->recurContributionId);
    $newMandateId = $mandateImporter->import();

    $newMandate = $this->getExternalMandateById($newMandateId);
    $this->assertEquals($rowData['external_direct_debit_mandate_id'], $newMandate['mandate_id']);
    $this->assertEquals($rowData['external_direct_debit_mandate_status'], $newMandate['mandate_status']);

    $expectedDate = DateTime::createFromFormat('YmdHis', $rowData['external_direct_debit_next_available_payment_date']);
    $this->assertEquals($expectedDate->format('Y-m-d H:i:s'), $newMandate['next_available_payment_date']);
  }

  public function testExternalMandateWillGetUpdatedIfRecurContributionAlreadyHasMandate() {
    $firstImporterRowData = [
      'external_direct_debit_mandate_id' => 'TEST_X13',
      'external_direct_debit_mandate_status' => 1,
      'external_direct_debit_next_available_payment_date' => '20230101000000',
    ];
    $mandateImporter = new ExternalDirectDebitMandateImporter($firstImporterRowData, $this->recurContributionId);
    $firstImportMandateId = $mandateImporter->import();

    $secondImporterRowData = [
      'external_direct_debit_mandate_id' => 'TEST_X13',
      'external_direct_debit_mandate_status' => 0,
      'external_direct_debit_next_available_payment_date' => '20230301000000',
    ];
    $mandateImporter = new ExternalDirectDebitMandateImporter($secondImporterRowData, $this->recurContributionId);
    $secondImportMandateId = $mandateImporter->import();

    $newMandate = $this->getExternalMandateById($secondImportMandateId);
    $this->assertEquals($firstImportMandateId, $secondImportMandateId);
    $this->assertEquals($secondImporterRowData['external_direct_debit_mandate_id'], $newMandate['mandate_id']);
    $this->assertEquals($secondImporterRowData['external_direct_debit_mandate_status'], $newMandate['mandate_status']);

    $expectedDate = DateTime::createFromFormat('YmdHis', $secondImporterRowData['external_direct_debit_next_available_payment_date']);
    $this->assertEquals($expectedDate->format('Y-m-d H:i:s'), $newMandate['next_available_payment_date']);
  }

  public function testNoMandateWillBeCreatedIfAnyMainMandateFieldIsMissing() {
    $rowData = [
      'external_direct_debit_mandate_id' => 'TEST_X13',
      'external_direct_debit_next_available_payment_date' => '20230101000000',
    ];

    foreach ($rowData as $fieldName => $rowValue) {
      $rowDataCopy = $rowData;
      unset($rowDataCopy[$fieldName]);
      $mandateImporter = new ExternalDirectDebitMandateImporter($rowDataCopy, $this->recurContributionId);
      $newMandateId = $mandateImporter->import();

      $this->assertNull($newMandateId);
    }
  }

  private function getExternalMandateById($mandateId) {
    $sql = "SELECT * FROM civicrm_value_external_dd_mandate_information WHERE id = {$mandateId}";
    $dao = CRM_Core_DAO::executeQuery($sql);

    $dao->fetch();
    if (!empty($dao->id)) {
      return $dao->toArray();
    }

    return NULL;
  }

}
