<?php

use CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_RecurContribution as RecurContributionImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;

/**
 * @group headless
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_RecurContributionTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'recurring_contribution_external_id' => 'rd-test-1',
    'recurring_contribution_currency' => 'USD',
    'recurring_contribution_frequency_unit' => 'month',
    'recurring_contribution_start_date' => '2025-01-15',
    'recurring_contribution_next_sched_date' => '2025-02-15',
    'recurring_contribution_cycle_day' => 15,
    'recurring_contribution_amount' => '10.00',
  ];

  private $contactId;

  public function setUp(): void {
    $this->contactId = ContactFabricator::fabricate()['id'];
    OptionValueCache::reset();
    $this->ensureGoCardlessProcessorExists();
  }

  public function testImportCreatesNewRecurContribution() {
    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $this->assertNotEmpty($recurContributionId);

    $recur = $this->getRecurContributionById($recurContributionId);
    $this->assertEquals($this->contactId, $recur['contact_id']);
    $this->assertEquals('10.00', $recur['amount']);
    $this->assertEquals('month', $recur['frequency_unit']);
    $this->assertEquals(1, $recur['frequency_interval']);
    $this->assertEquals(15, $recur['cycle_day']);
  }

  public function testImportExistingRecurContributionUpdatesIt() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-2';

    $firstImport = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $firstId = $firstImport->import();

    $this->sampleRowData['recurring_contribution_amount'] = '25.00';
    $secondImport = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $secondId = $secondImport->import();

    $this->assertEquals($firstId, $secondId);

    $recur = $this->getRecurContributionById($secondId);
    $this->assertEquals('25.00', $recur['amount']);
  }

  public function testImportCreatesExternalIdRecord() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-3';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_recur_ext_id WHERE external_id = %1 AND entity_id = {$recurContributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->sampleRowData['recurring_contribution_external_id'], 'String']]);
    $result->fetch();

    $this->assertEquals(1, $result->N);
  }

  public function testImportWithInvalidFrequencyThrowsException() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-4';
    $this->sampleRowData['recurring_contribution_frequency_unit'] = 'biweekly';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException::class);
    $this->expectExceptionCode(100);

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $importer->import();
  }

  public function testImportWithCycleDay28IsAccepted() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-5';
    $this->sampleRowData['recurring_contribution_cycle_day'] = 28;

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $this->assertEquals(28, $recur['cycle_day']);
  }

  public function testImportWithCycleDayAbove28ThrowsException() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-5b';
    $this->sampleRowData['recurring_contribution_cycle_day'] = 29;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException::class);
    $this->expectExceptionCode(700);

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $importer->import();
  }

  public function testImportWithCycleDayZeroThrowsException() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-6';
    $this->sampleRowData['recurring_contribution_cycle_day'] = 0;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException::class);
    $this->expectExceptionCode(600);

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $importer->import();
  }

  public function testImportDefaultsStatusToInProgress() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-7';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $expectedStatusId = $this->getRecurStatusId('In Progress');
    $this->assertEquals($expectedStatusId, $recur['contribution_status_id']);
  }

  public function testImportWithInvalidCurrencyThrowsException() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-8';
    $this->sampleRowData['recurring_contribution_currency'] = 'FAKE';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException::class);
    $this->expectExceptionCode(900);

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $importer->import();
  }

  public function testImportWithYearFrequency() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-9';
    $this->sampleRowData['recurring_contribution_frequency_unit'] = 'year';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $this->assertEquals('year', $recur['frequency_unit']);
    $this->assertEquals(1, $recur['frequency_interval']);
  }

  public function testImportWithWeekFrequency() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-13';
    $this->sampleRowData['recurring_contribution_frequency_unit'] = 'week';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $this->assertEquals('week', $recur['frequency_unit']);
    $this->assertEquals(1, $recur['frequency_interval']);
  }

  public function testImportDefaultsFinancialTypeToDonation() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-10';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $expectedFinancialTypeId = $this->getFinancialTypeId('Donation');
    $this->assertEquals($expectedFinancialTypeId, $recur['financial_type_id']);
  }

  public function testImportAutoDetectsGoCardlessPaymentProcessor() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-11';

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $recurContributionId = $importer->import();

    $recur = $this->getRecurContributionById($recurContributionId);
    $processorClassName = $this->getPaymentProcessorClassName($recur['payment_processor_id']);
    $this->assertEquals('Payment_GoCardless', $processorClassName);
  }

  public function testImportWithNegativeAmountThrowsException() {
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-test-12';
    $this->sampleRowData['recurring_contribution_amount'] = '-5.00';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException::class);
    $this->expectExceptionCode(1400);

    $importer = new RecurContributionImporter($this->sampleRowData, $this->contactId);
    $importer->import();
  }

  private function getRecurContributionById($id) {
    $sqlQuery = "SELECT * FROM civicrm_contribution_recur WHERE id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $result->fetch();
    return $result->toArray();
  }

  private function getRecurStatusId($statusName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov
                INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                WHERE cog.name = 'contribution_recur_status' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getFinancialTypeId($name) {
    $sqlQuery = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$name, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getPaymentProcessorClassName($processorId) {
    $sqlQuery = "SELECT class_name FROM civicrm_payment_processor WHERE id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$processorId, 'Integer']]);
    $result->fetch();
    return $result->class_name;
  }

  private function ensureGoCardlessProcessorExists() {
    $sqlQuery = "SELECT id FROM civicrm_payment_processor WHERE class_name = 'Payment_GoCardless' AND is_test = 0 AND is_active = 1 LIMIT 1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    if (!$result->fetch()) {
      civicrm_api3('PaymentProcessor', 'create', [
        'name' => 'GoCardless Test',
        'payment_processor_type_id' => 'GoCardless',
        'class_name' => 'Payment_GoCardless',
        'is_active' => 1,
        'is_test' => 0,
      ]);
    }
  }

}
