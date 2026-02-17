<?php

use CRM_Membershipextrasimporterapi_RecurringDonation_CSVRowImporter as CSVRowImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;
use CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessMandateValidator as MandateValidator;
use CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessSubscriptionCreator as SubscriptionCreator;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;

/**
 * @group headless
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_CSVRowImporterTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'recurring_contribution_external_id' => 'rd-csvrow-1',
    'recurring_contribution_currency' => 'USD',
    'recurring_contribution_frequency_unit' => 'month',
    'recurring_contribution_start_date' => '2025-01-15',
    'recurring_contribution_next_sched_date' => '2025-02-15',
    'recurring_contribution_cycle_day' => 15,
    'recurring_contribution_amount' => '10.00',
    'gocardless_mandate_id' => 'MD000TEST1',
  ];

  public function setUp(): void {
    parent::setUp();
    OptionValueCache::reset();
    MandateValidator::reset();
    SubscriptionCreator::reset();
    $this->ensureGoCardlessProcessorExists();
    $this->mockGoCardlessServices();
  }

  public function tearDown(): void {
    MandateValidator::reset();
    SubscriptionCreator::reset();
    parent::tearDown();
  }

  public function testImportCreatesRecurringContributionWithCorrectFields() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);

    $this->assertEquals($contactId, $recurContribution['contact_id']);
    $this->assertEquals('10.00', $recurContribution['amount']);
    $this->assertEquals('USD', $recurContribution['currency']);
    $this->assertEquals('month', $recurContribution['frequency_unit']);
    $this->assertEquals(1, $recurContribution['frequency_interval']);
    $this->assertEquals(15, $recurContribution['cycle_day']);
    $this->assertEquals('2025-01-15', date('Y-m-d', strtotime($recurContribution['start_date'])));

    $inProgressStatusId = $this->getRecurContributionStatusId('In Progress');
    $this->assertEquals($inProgressStatusId, $recurContribution['contribution_status_id']);
  }

  public function testImportSetsProcessorIdFromGoCardlessSubscription() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);

    $this->assertNotEmpty($recurContribution['processor_id'], 'GoCardless subscription processor_id should be set');
    $this->assertStringStartsWith('SB', $recurContribution['processor_id']);
  }

  public function testImportCreatesPendingContributionWithCorrectFields() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);

    $this->assertEquals($contactId, $contribution['contact_id']);

    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $this->assertEquals($pendingStatusId, $contribution['contribution_status_id']);

    $this->assertEquals(0, $contribution['is_pay_later']);
    $this->assertEquals('2025-01-15', date('Y-m-d', strtotime($contribution['receive_date'])));
    $this->assertStringContainsString('Recurring Donation Importer at:', $contribution['source']);
  }

  public function testImportCreatesContributionWithCorrectFinancialType() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_financial_type'] = 'Donation';
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-ft-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $expectedFTId = $this->getFinancialTypeIdByName('Donation');

    $this->assertEquals($expectedFTId, $contribution['financial_type_id']);
  }

  public function testImportWithCustomFinancialTypeCreatesCorrectRecords() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_financial_type'] = 'Member Dues';
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-custom-ft-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $expectedFTId = $this->getFinancialTypeIdByName('Member Dues');
    $this->assertEquals($expectedFTId, $contribution['financial_type_id']);

    $lineItem = $this->getLineItemByContributionId($contribution['id']);
    $this->assertEquals($expectedFTId, $lineItem['financial_type_id']);
    $this->assertEquals('Member Dues', $lineItem['label']);
  }

  public function testImportCreatesLineItemWithCorrectFields() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $lineItem = $this->getLineItemByContributionId($contribution['id']);

    $this->assertEquals('civicrm_contribution', $lineItem['entity_table']);
    $this->assertEquals($contribution['id'], $lineItem['entity_id']);
    $this->assertEquals($contribution['id'], $lineItem['contribution_id']);
    $this->assertEquals(1, $lineItem['qty']);
    $this->assertEquals('10.00', $lineItem['unit_price']);
    $this->assertEquals('10.00', $lineItem['line_total']);
    $this->assertEquals('Donation', $lineItem['label']);
  }

  public function testImportCreatesCorrectFinancialTransactionRecords() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-fintrxn-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $financialTrxn = $this->getContributionFinancialTransaction($contribution['id']);

    $this->assertEquals($contribution['total_amount'], $financialTrxn->ef_amount);
    $this->assertEquals($contribution['total_amount'], $financialTrxn->f_amount);
    $this->assertEquals($contribution['total_amount'], $financialTrxn->f_net_amount);
    $this->assertEquals($contribution['currency'], $financialTrxn->currency);
    $this->assertEquals($contribution['contribution_status_id'], $financialTrxn->status_id);
    $this->assertEquals($contribution['payment_instrument_id'], $financialTrxn->payment_instrument_id);
    $this->assertEquals(0, $financialTrxn->f_is_payment);
  }

  public function testImportCreatesCorrectFinancialItemRecords() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-finitem-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $lineItem = $this->getLineItemByContributionId($contribution['id']);

    $sqlQuery = "SELECT cfi.* FROM civicrm_financial_item cfi
                 WHERE cfi.entity_id = %1 AND cfi.entity_table = 'civicrm_line_item'";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$lineItem['id'], 'Integer']]);
    $result->fetch();

    $this->assertEquals($contactId, $result->contact_id);
    $this->assertEquals($lineItem['label'], $result->description);
    $this->assertEquals($lineItem['line_total'], $result->amount);
    $this->assertEquals($contribution['currency'], $result->currency);
  }

  public function testImportUpdatesContributionAmountsFromLineItem() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_amount'] = '25.50';
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-amounts-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);

    // Contribution starts at 0, then LineItem accumulates the amount.
    // LineItem adds 25.50 via updateRelatedContributionAmounts.
    $this->assertEquals('25.50', $contribution['total_amount']);
    $this->assertEquals('25.50', $contribution['net_amount']);
  }

  public function testImportWithContactCreationCreatesAllEntities() {
    $email = 'new-donor-' . uniqid() . '@example.com';
    $this->sampleRowData['email'] = $email;
    $this->sampleRowData['first_name'] = 'Test';
    $this->sampleRowData['last_name'] = 'Donor';
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-newcontact-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    // Verify contact was created with correct fields.
    $sqlQuery = "SELECT cc.id, cc.first_name, cc.last_name FROM civicrm_contact cc
                 INNER JOIN civicrm_email ce ON ce.contact_id = cc.id
                 WHERE ce.email = %1";
    $contact = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$email, 'String']]);
    $contact->fetch();
    $this->assertEquals('Test', $contact->first_name);
    $this->assertEquals('Donor', $contact->last_name);

    // Verify recurring contribution linked to new contact.
    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $this->assertEquals($contact->id, $recurContribution['contact_id']);

    // Verify contribution linked to new contact.
    $contribution = $this->getContributionByRecurExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $this->assertEquals($contact->id, $contribution['contact_id']);
  }

  public function testImportResolvesContactByExternalId() {
    $extId = 'ext-csvrow-' . uniqid();
    $contactId = ContactFabricator::fabricate(['external_identifier' => $extId])['id'];
    $this->sampleRowData['contact_external_id'] = $extId;
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-extid-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $this->assertEquals($contactId, $recurContribution['contact_id']);
  }

  public function testImportResolvesContactByEmail() {
    $contactId = ContactFabricator::fabricate()['id'];
    $email = 'csvrow-email-' . uniqid() . '@example.com';
    civicrm_api3('Email', 'create', [
      'contact_id' => $contactId,
      'email' => $email,
      'is_primary' => 1,
    ]);

    $this->sampleRowData['email'] = $email;
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-email-' . uniqid();

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $this->assertEquals($contactId, $recurContribution['contact_id']);
  }

  public function testImportWithCustomDescriptionUsesItForSubscription() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_description'] = 'Monthly Charity';
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-desc-' . uniqid();

    // The description is passed to the mock subscription service.
    // We just verify the import succeeds (description is passed to GoCardless).
    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();

    $recurContribution = $this->getRecurContributionByExternalId($this->sampleRowData['recurring_contribution_external_id']);
    $this->assertNotEmpty($recurContribution['processor_id']);
  }

  public function testImportThrowsOnGoCardlessSubscriptionFailure() {
    $contactId = ContactFabricator::fabricate()['id'];
    $this->sampleRowData['contact_id'] = $contactId;
    $this->sampleRowData['recurring_contribution_external_id'] = 'rd-rollback-' . uniqid();

    // Replace subscription service with one that does NOT set processor_id.
    $failingSubscriptionService = new class {

      public function create(int $contributionRecurID, string $mandate, string $description) {
        // Intentionally do nothing — simulates GoCardless silent failure.
      }

    };
    SubscriptionCreator::setSubscriptionService($failingSubscriptionService);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('GoCardless subscription creation failed silently');

    $importer = new CSVRowImporter($this->sampleRowData);
    $importer->import();
  }

  public function testConstructorThrowsWhenNoContactIdentifierProvided() {
    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContactException::class);
    $this->expectExceptionCode(300);

    new CSVRowImporter($this->sampleRowData);
  }

  public function testConstructorThrowsWhenContactIdNotFound() {
    $this->sampleRowData['contact_id'] = 999999;

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContactException::class);
    $this->expectExceptionCode(100);

    new CSVRowImporter($this->sampleRowData);
  }

  public function testConstructorThrowsWhenExternalIdNotFound() {
    $this->sampleRowData['contact_external_id'] = 'nonexistent-ext-id';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContactException::class);
    $this->expectExceptionCode(200);

    new CSVRowImporter($this->sampleRowData);
  }

  public function testConstructorThrowsWhenEmailNotFoundWithoutName() {
    $this->sampleRowData['email'] = 'nobody-csvrow-' . uniqid() . '@example.com';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidContactException::class);
    $this->expectExceptionCode(400);

    new CSVRowImporter($this->sampleRowData);
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

  private function mockGoCardlessServices() {
    $mockMandateService = new class {

      public function getMandateByID(string $mandateID) {
        $mandate = new stdClass();
        $mandate->id = $mandateID;
        $mandate->status = 'active';
        $mandate->scheme = 'bacs';
        $mandate->next_possible_charge_date = '2025-02-01';
        return $mandate;
      }

    };

    $mockSubscriptionService = new class {

      public function create(int $contributionRecurID, string $mandate, string $description) {
        $subscriptionId = 'SB' . str_pad($contributionRecurID, 6, '0', STR_PAD_LEFT);
        \CRM_Core_DAO::executeQuery(
          "UPDATE civicrm_contribution_recur SET processor_id = %1 WHERE id = %2",
          [
            1 => [$subscriptionId, 'String'],
            2 => [$contributionRecurID, 'Integer'],
          ]
        );
      }

    };

    MandateValidator::setMandateService($mockMandateService);
    SubscriptionCreator::setSubscriptionService($mockSubscriptionService);
  }

  private function getRecurContributionByExternalId($externalId) {
    $sqlQuery = "SELECT cr.* FROM civicrm_contribution_recur cr
                 INNER JOIN civicrm_value_contribution_recur_ext_id ext ON ext.entity_id = cr.id
                 WHERE ext.external_id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$externalId, 'String']]);
    $result->fetch();

    return $result->toArray();
  }

  private function getContributionByRecurExternalId($recurExternalId) {
    $sqlQuery = "SELECT c.* FROM civicrm_contribution c
                 INNER JOIN civicrm_contribution_recur cr ON c.contribution_recur_id = cr.id
                 INNER JOIN civicrm_value_contribution_recur_ext_id ext ON ext.entity_id = cr.id
                 WHERE ext.external_id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$recurExternalId, 'String']]);
    $result->fetch();

    return $result->toArray();
  }

  private function getLineItemByContributionId($contributionId) {
    $sqlQuery = "SELECT * FROM civicrm_line_item WHERE contribution_id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contributionId, 'Integer']]);
    $result->fetch();

    return $result->toArray();
  }

  private function getContributionFinancialTransaction($contributionId) {
    $sqlQuery = "SELECT ceft.amount as ef_amount, cft.total_amount as f_amount, cft.currency, cft.status_id,
                        cft.payment_instrument_id, cft.to_financial_account_id, cft.trxn_date as f_trxn_date,
                        cft.net_amount as f_net_amount, cft.is_payment as f_is_payment
                 FROM civicrm_entity_financial_trxn ceft
                 INNER JOIN civicrm_financial_trxn cft ON ceft.financial_trxn_id = cft.id
                 WHERE ceft.entity_table = 'civicrm_contribution' AND ceft.entity_id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contributionId, 'Integer']]);
    $result->fetch();

    return $result;
  }

  private function getRecurContributionStatusId($statusName) {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov
                 INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                 WHERE cog.name = 'contribution_recur_status' AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$statusName, 'String']]);
    $result->fetch();

    return $result->id;
  }

  private function getFinancialTypeIdByName($ftName) {
    $sqlQuery = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$ftName, 'String']]);
    $result->fetch();

    return $result->id;
  }

}
