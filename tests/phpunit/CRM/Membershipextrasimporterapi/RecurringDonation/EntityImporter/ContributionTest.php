<?php

use CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_Contribution as ContributionImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;

/**
 * @group headless
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_ContributionTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'recurring_contribution_financial_type' => 'Donation',
    'recurring_contribution_payment_instrument' => 'EFT',
    'recurring_contribution_start_date' => '2025-01-15',
    'recurring_contribution_amount' => '10.00',
    'recurring_contribution_currency' => 'USD',
  ];

  private $contactId;

  private $recurContributionId;

  private $testPaymentProcessorId = 1;

  public function setUp(): void {
    $this->contactId = ContactFabricator::fabricate()['id'];
    OptionValueCache::reset();

    $recurContributionParams = [
      'contact_id' => $this->contactId,
      'amount' => 10,
      'frequency_interval' => 1,
      'payment_processor_id' => $this->testPaymentProcessorId,
    ];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];
  }

  public function testImportCreatesNewContribution() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $this->assertNotEmpty($contributionId);

    $contribution = $this->getContributionById($contributionId);
    $this->assertEquals($this->contactId, $contribution['contact_id']);
    $this->assertEquals($this->recurContributionId, $contribution['contribution_recur_id']);
  }

  public function testImportSetsContributionStatusToPending() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    $expectedStatusId = $this->getContributionStatusId('Pending');
    $this->assertEquals($expectedStatusId, $contribution['contribution_status_id']);
  }

  public function testImportSetsIsPayLaterToFalse() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    $this->assertEquals(0, $contribution['is_pay_later']);
  }

  public function testImportSetsInitialTotalAmountToZero() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    // Total amount starts at 0, accumulated by LineItem importer.
    $this->assertEquals('0.00', $contribution['total_amount']);
  }

  public function testImportSetsReceiveDateFromStartDate() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    $this->assertStringStartsWith('2025-01-15', $contribution['receive_date']);
  }

  public function testImportSetsCorrectSource() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    $this->assertStringStartsWith('Recurring Donation Importer at:', $contribution['source']);
  }

  public function testImportCreatesFinancialTransactionRecords() {
    $contributionImporter = new ContributionImporter($this->sampleRowData, $this->contactId, $this->recurContributionId);
    $contributionId = $contributionImporter->import();

    $contribution = $this->getContributionById($contributionId);
    $financialTrxn = $this->getContributionFinancialTransaction($contributionId);

    $this->assertEquals($contribution['total_amount'], $financialTrxn->ef_amount);
    $this->assertEquals($contribution['total_amount'], $financialTrxn->f_amount);
    $this->assertEquals(0, $financialTrxn->f_is_payment);
  }

  private function getContributionById($id) {
    $sqlQuery = "SELECT * FROM civicrm_contribution WHERE id = %1";
    $contribution = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $contribution->fetch();
    return $contribution->toArray();
  }

  private function getContributionStatusId($statusName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'contribution_status' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getContributionFinancialTransaction($contributionId) {
    $sqlQuery = "SELECT ceft.amount as ef_amount, cft.total_amount as f_amount, cft.currency, cft.status_id,
                 cft.payment_instrument_id, cft.to_financial_account_id,
                 cft.trxn_date as f_trxn_date, cft.net_amount as f_net_amount, cft.is_payment as f_is_payment
                 FROM civicrm_entity_financial_trxn ceft
                 INNER JOIN civicrm_financial_trxn cft ON ceft.financial_trxn_id = cft.id
                 WHERE ceft.entity_table = 'civicrm_contribution' AND ceft.entity_id = {$contributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();
    return $result;
  }

}
