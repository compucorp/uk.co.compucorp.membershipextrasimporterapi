<?php

use CRM_Membershipextrasimporterapi_EntityImporter_LineItem as LineItemImporter;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_Contribution as ContributionFabricator;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;
use CRM_MembershipExtras_Test_Fabricator_Membership as MembershipFabricator;
use CRM_MembershipExtras_Test_Fabricator_RecurringContribution as RecurContributionFabricator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityImporter_LineItemTest extends BaseHeadlessTest {

  private $sampleRowData = [
    'line_item_entity_table' => 'civicrm_membership',
    'line_item_unit_price' => 50,
    'line_item_financial_type' => 'Member Dues',
  ];

  private $contactId;

  private $contributionId;

  private $membershipId;

  private $recurContributionId;

  private $studentMembershipTypeId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    $recurContributionParams = ['contact_id' => $this->contactId, 'amount' => 0, 'frequency_interval' => 1, 'payment_processor_id' => 1];
    $this->recurContributionId = RecurContributionFabricator::fabricate($recurContributionParams)['id'];

    $contributionParams = [
      'contact_id' => $this->contactId,
      'financial_type_id' => 'Member Dues',
      'receive_date' => date('Y-m-d'),
      'total_amount' => 0,
      'skipLineItem' => 1,
      'recur_contribution_id' => $this->recurContributionId,
    ];
    $this->contributionId = ContributionFabricator::fabricate($contributionParams)['id'];

    $this->studentMembershipTypeId = MembershipTypeFabricator::fabricate(['name' => 'Student', 'minimum_fee' => 50])['id'];

    $membershipParams = ['contact_id' => $this->contactId, 'membership_type_id' => 'Student'];
    $this->membershipId = MembershipFabricator::fabricate($membershipParams)['id'];

    $this->createTestFinancialTypeWithTax();
  }

  private function createTestFinancialTypeWithTax() {
    $financialTypeId = civicrm_api3('FinancialType', 'create', [
      'sequential' => 1,
      'name' => 'FT Taxed',
      'is_active' => 1,
    ])['id'];

    civicrm_api3('FinancialAccount', 'create', [
      'name' => 'VAT',
      'financial_account_type_id' => 'Liability',
      'is_tax' => 1,
      'tax_rate' => 10,
      'is_active' => 1,
    ]);

    civicrm_api3('EntityFinancialAccount', 'create', [
      'account_relationship' => 'Sales Tax Account is',
      'entity_table' => 'civicrm_financial_type',
      'entity_id' => $financialTypeId,
      'financial_account_id' => 'VAT',
    ]);
  }

  public function testImportNewDonationLineItem() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_contribution';
    $beforeImportIds = $this->getLineItemsByContributionId($this->contributionId);

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $afterImportIds = $this->getLineItemsByContributionId($this->contributionId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newLineItemId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportNewMembershipLineItem() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_membership';
    $beforeImportIds = $this->getLineItemsByContributionId($this->contributionId);

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $afterImportIds = $this->getLineItemsByContributionId($this->contributionId);

    $importSucceed = FALSE;
    if (empty($beforeImportIds) && $afterImportIds[0] == $newLineItemId) {
      $importSucceed = TRUE;
    }

    $this->assertTrue($importSucceed);
  }

  public function testImportNonDonationOrMembershipLineItemThrowException() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_xyz';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidLineItemException::class);
    $this->expectExceptionCode(100);

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();
  }

  public function testImportMembershipLineItemWithEntityIdNotSetWillDefaultItToMembershipId() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_membership';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->membershipId, $newLineItem['entity_id']);
  }

  public function testImportMembershipLineItemWithEntityIdSetWillStoreItCorrectly() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_membership';

    $newMembershipParams = ['contact_id' => $this->contactId, 'membership_type_id' => 'Student'];
    $newMembershipId = MembershipFabricator::fabricate($newMembershipParams)['id'];
    $this->sampleRowData['line_item_entity_id'] = $newMembershipId;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($newMembershipId, $newLineItem['entity_id']);
  }

  public function testImportDonationLineItemWillDefaultEntityIdToContributionId() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_contribution';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->contributionId, $newLineItem['entity_id']);
  }

  public function testImportWillSetContributionIdFieldCorrectly() {
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->contributionId, $newLineItem['contribution_id']);
  }

  public function testImportWithLabelSetWillStoreItCorrectly() {
    $this->sampleRowData['line_item_label'] = 'Test Label';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->sampleRowData['line_item_label'], $newLineItem['label']);
  }

  public function testImportMembershipLineItemWithLabelNotSetWillDefaultItToTheMembershipTypeName() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_membership';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals('Student', $newLineItem['label']);
  }

  public function testImportContributionLineItemWithLabelNotSetWillDefaultItDonation() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_contribution';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals('Donation', $newLineItem['label']);
  }

  public function testImportWithQuantitySetWillStoreItCorrectly() {
    $this->sampleRowData['line_item_quantity'] = 3;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals(3, $newLineItem['qty']);
  }

  public function testImportWithQuantityNotSetWillDefaultItToOne() {
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals(1, $newLineItem['qty']);
  }

  public function testImportWillStoreUnitPriceCorrectly() {
    $this->sampleRowData['line_item_unit_price'] = 50;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->sampleRowData['line_item_unit_price'], $newLineItem['unit_price']);
  }

  public function testImportWillCalculateLineTotalCorrectlyByMultiplyingUnitPriceByQuantity() {
    $this->sampleRowData['line_item_unit_price'] = 27.5;
    $this->sampleRowData['line_item_quantity'] = 2;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals(55, $newLineItem['line_total']);
  }

  public function testImportWillStoreFinancialTypeCorrectly() {
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $this->assertEquals($this->getFinancialTypeIdByName('Member Dues'), $newLineItem['financial_type_id']);
  }

  public function testImportWithInvalidFinancialTypeThrowException() {
    $this->sampleRowData['line_item_financial_type'] = 'Invalid FT';

    $this->expectException(CRM_Membershipextrasimporterapi_Exception_InvalidLineItemException::class);
    $this->expectExceptionCode(200);

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();
  }

  public function testImportWillCreateCorrectFinancialItemRecords() {
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $sqlQuery = "SELECT cfi.contact_id, cfi.description, cfi.amount, cfi.financial_account_id, cfi.entity_table, cfi.entity_id 
                 FROM civicrm_financial_item cfi
                 INNER JOIN civicrm_entity_financial_trxn ceft ON ceft.entity_id = cfi.id 
                 WHERE ceft.entity_table = 'civicrm_financial_item' AND cfi.entity_id = {$newLineItemId} AND cfi.entity_table = 'civicrm_line_item'";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();

    $this->assertEquals($this->contactId, $result->contact_id);
    $this->assertEquals($newLineItem['label'], $result->description);
    $this->assertEquals($newLineItem['line_total'], $result->amount);
  }

  public function testImportContributionLineItemWithNoPriceFieldDetailsWillDefaultItToContributionAmountPriceFieldValue() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_contribution';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $defaultContributionAmountPriceFieldId = 1;
    $defaultContributionAmountPriceFieldValueId = 1;
    $this->assertEquals($defaultContributionAmountPriceFieldId, $newLineItem['price_field_id']);
    $this->assertEquals($defaultContributionAmountPriceFieldValueId, $newLineItem['price_field_value_id']);
  }

  public function testImportMembershipLineItemWithNoPriceFieldDetailsWillDefaultItToMembershipAmountPriceFieldValue() {
    $this->sampleRowData['line_item_entity_table'] = 'civicrm_membership';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $membershipTypePriceFieldDetails = $this->getMembershipTypePriceFieldValueDetails($this->studentMembershipTypeId);
    $this->assertEquals($membershipTypePriceFieldDetails['price_field_id'], $newLineItem['price_field_id']);
    $this->assertEquals($membershipTypePriceFieldDetails['id'], $newLineItem['price_field_value_id']);
  }

  public function testImportWillUpdateContributionAmountToTheSumOfLineItems() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_financial_type' => 'Member Dues',
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $contribution = $this->getContributionById($this->contributionId);

    $this->assertEquals(80.5, $contribution['total_amount']);
    $this->assertEquals(80.5, $contribution['net_amount']);
  }

  public function testImportWillUpdateContributionRelatedFinancialRecordsAmountsToBeSameAsTheContribution() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_financial_type' => 'Member Dues',
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $entityFinancialTrxn = $this->getEntityFinancialTrxnByContributionId($this->contributionId);
    $financialTrxn = $this->getFinancialTrxnById($entityFinancialTrxn['financial_trxn_id']);

    $this->assertEquals(80.5, $entityFinancialTrxn['amount']);
    $this->assertEquals(80.5, $financialTrxn['total_amount']);
    $this->assertEquals(80.5, $financialTrxn['net_amount']);
  }

  public function testImportWithTaxWillUpdateContributionAmountToTheSumOfLineItemsAmountsAndTaxes() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_tax_amount' => 5.33,
      'line_item_financial_type' => 'FT Taxed',
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $contribution = $this->getContributionById($this->contributionId);

    $this->assertEquals(95.83, $contribution['total_amount']);
    $this->assertEquals(95.83, $contribution['net_amount']);
  }

  public function testImportWithTaxWillUpdateContributionRelatedFinancialRecordsAmountsToBeSameAsTheContribution() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_tax_amount' => 5.33,
      'line_item_financial_type' => 'FT Taxed',
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $entityFinancialTrxn = $this->getEntityFinancialTrxnByContributionId($this->contributionId);
    $financialTrxn = $this->getFinancialTrxnById($entityFinancialTrxn['financial_trxn_id']);

    $this->assertEquals(95.83, $entityFinancialTrxn['amount']);
    $this->assertEquals(95.83, $financialTrxn['total_amount']);
    $this->assertEquals(95.83, $financialTrxn['net_amount']);
  }

  public function testImportWithTaxWillUpdateContributionTaxAmountToTheSumOfLineItemsTaxAmounts() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_tax_amount' => 5.33,
      'line_item_financial_type' => 'FT Taxed',
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $contribution = $this->getContributionById($this->contributionId);

    $this->assertEquals(15.33, $contribution['tax_amount']);
  }

  public function testImportWithTaxWillSetLineItemAmountAndTaxAmountCorrectly() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemId = $lineItemImporter->import();

    $lineItem = $this->getLineItemById($lineItemId);

    $this->assertEquals(50, $lineItem['line_total']);
    $this->assertEquals(10, $lineItem['tax_amount']);
  }

  public function testImportWillUpdateRecurContributionAmountToTheSumOfAutoRenewalLineItems() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_auto_renew'] = 1;
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_financial_type' => 'Member Dues',
      'line_item_auto_renew' => 1,
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $recurContribution = $this->getRecurContributionById($this->recurContributionId);

    $this->assertEquals(80.5, $recurContribution['amount']);
  }

  public function testImportWillNotIncludeNonAutoRenewalLineItemsInRecurContributionAmount() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_auto_renew'] = 0;
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_financial_type' => 'Member Dues',
      'line_item_auto_renew' => 1,
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $recurContribution = $this->getRecurContributionById($this->recurContributionId);

    $this->assertEquals(30.5, $recurContribution['amount']);
  }

  public function testImportWithTaxWillUpdateRecurContributionAmountToTheSumOfAutoRenewalLineItemsWithTaxes() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';
    $this->sampleRowData['line_item_auto_renew'] = 1;
    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $secondLineItemParams = [
      'line_item_entity_table' => 'civicrm_contribution',
      'line_item_unit_price' => 30.5,
      'line_item_tax_amount' => 5,
      'line_item_financial_type' => 'FT Taxed',
      'line_item_auto_renew' => 1,
    ];
    $lineItemImporter = new LineItemImporter($secondLineItemParams, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $recurContribution = $this->getRecurContributionById($this->recurContributionId);

    $this->assertEquals(95.5, $recurContribution['amount']);
  }

  public function testImportWithTaxWillCreateCorrectFinancialItemRecords() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $newLineItemId = $lineItemImporter->import();

    $newLineItem = $this->getLineItemById($newLineItemId);

    $sqlQuery = "SELECT cfi.contact_id, cfi.description, cfi.amount, cfi.financial_account_id, cfi.entity_table, cfi.entity_id  
                 FROM civicrm_financial_item cfi
                 INNER JOIN civicrm_entity_financial_trxn ceft ON ceft.entity_id = cfi.id 
                 WHERE ceft.entity_table = 'civicrm_financial_item' AND cfi.entity_id = {$newLineItemId} AND cfi.entity_table = 'civicrm_line_item'";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);

    $result->fetch();
    $this->assertEquals($newLineItem['line_total'], $result->amount);

    $result->fetch();
    $this->assertEquals($newLineItem['tax_amount'], $result->amount);
  }

  public function testImportWillCreateSubscriptionLineItems() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';
    $this->sampleRowData['line_item_auto_renew'] = 1;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $sqlQuery = "SELECT msl.id as id  
                 FROM membershipextras_subscription_line msl 
                 INNER JOIN civicrm_line_item cli ON msl.line_item_id = cli.id 
                 WHERE msl.contribution_recur_id = {$this->recurContributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);

    $this->assertEquals(1, $result->N);
  }

  public function testImportWithNoAutoRenewalLineItemWillNotCreateSubscriptionLineItems() {
    $this->sampleRowData['line_item_unit_price'] = 50;
    $this->sampleRowData['line_item_tax_amount'] = 10;
    $this->sampleRowData['line_item_financial_type'] = 'FT Taxed';
    $this->sampleRowData['line_item_auto_renew'] = 0;

    $lineItemImporter = new LineItemImporter($this->sampleRowData, $this->contributionId, $this->membershipId, $this->recurContributionId);
    $lineItemImporter->import();

    $sqlQuery = "SELECT msl.id as id  
                 FROM membershipextras_subscription_line msl 
                 INNER JOIN civicrm_line_item cli ON msl.line_item_id = cli.id 
                 WHERE msl.contribution_recur_id = {$this->recurContributionId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);

    $this->assertEquals(0, $result->N);
  }

  private function getLineItemsByContributionId($contributionId) {
    $lineItemsIds = NULL;

    $sqlQuery = "SELECT id FROM civicrm_line_item WHERE contribution_id = %1";
    $lineItems = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contributionId, 'Integer']]);
    while ($lineItems->fetch()) {
      $lineItemsIds[] = $lineItems->id;
    }

    return $lineItemsIds;
  }

  private function getLineItemById($id) {
    $sqlQuery = "SELECT * FROM civicrm_line_item WHERE id = %1";
    $lineItem = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $lineItem->fetch();

    return $lineItem->toArray();
  }

  private function getFinancialTypeIdByName($ftName) {
    $sqlQuery = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$ftName, 'String']]);
    $result->fetch();
    return $result->id;
  }

  private function getMembershipTypePriceFieldValueDetails($membershipTypeId) {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_price_field_value WHERE membership_type_id = {$membershipTypeId} 
                                       ORDER BY id ASC LIMIT 1");
    $dao->fetch();
    return $dao->toArray();
  }

  private function getContributionById($id) {
    $sqlQuery = "SELECT * FROM civicrm_contribution WHERE id = %1";
    $contribution = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $contribution->fetch();

    return $contribution->toArray();
  }

  private function getRecurContributionById($id) {
    $sqlQuery = "SELECT * FROM civicrm_contribution_recur WHERE id = %1";
    $contribution = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $contribution->fetch();

    return $contribution->toArray();
  }

  private function getEntityFinancialTrxnByContributionId($contributionId) {
    $sqlQuery = "SELECT * FROM civicrm_entity_financial_trxn WHERE entity_table = 'civicrm_contribution' AND entity_id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$contributionId, 'Integer']]);
    $result->fetch();

    return $result->toArray();
  }

  private function getFinancialTrxnById($id) {
    $sqlQuery = "SELECT * FROM civicrm_financial_trxn WHERE id = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$id, 'Integer']]);
    $result->fetch();

    return $result->toArray();
  }

}
