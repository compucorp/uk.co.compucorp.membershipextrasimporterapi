<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;
use CRM_MembershipExtras_Service_MoneyUtilities as MoneyUtilities;

class CRM_Membershipextrasimporterapi_EntityImporter_LineItem {

  private $rowData;

  private $contributionId;

  private $membershipId;

  private $recurContributionId;

  private $cachedValues;

  private $entityTable;

  private $entityId;

  private $contribution;

  private $membership;

  private $priceFieldValue;

  public function __construct($rowData, $contributionId, $membershipId, $recurContributionId) {
    $this->rowData = $rowData;
    $this->contributionId = $contributionId;
    $this->membershipId = $membershipId;
    $this->recurContributionId = $recurContributionId;
    $this->setContribution($contributionId);

    if ($membershipId != NULL) {
      $this->setMembership($membershipId);
    }

    $this->setEntityTable();
    $this->setEntityId();
  }

  private function setContribution($contributionId) {
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_contribution WHERE id = {$contributionId}");
    $dao->fetch();
    $this->contribution = $dao->toArray();
  }

  private function setMembership($membershipId) {
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_membership WHERE id = {$membershipId}");
    $dao->fetch();
    $this->membership = $dao->toArray();
  }

  private function setEntityTable() {
    switch ($this->rowData['line_item_entity_table']) {
      case 'civicrm_membership':
      case 'civicrm_contribution':
        $this->entityTable = $this->rowData['line_item_entity_table'];
        break;

      default:
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidLineItemException('Invalid order line item "Entity Type"', 100);
    }
  }

  private function setEntityId() {
    if ($this->entityTable == 'civicrm_membership') {
      if (!empty($this->rowData['line_item_entity_id'])) {
        $entityId = $this->rowData['line_item_entity_id'];
      }
      else {
        $entityId = $this->membershipId;
      }
    }
    else {
      $entityId = $this->contributionId;
    }

    $this->entityId = $entityId;
  }

  public function import() {
    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = $this->prepareSqlQuery($sqlParams);
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as line_item_id');
    $dao->fetch();
    $lineItemId = $dao->line_item_id;

    $mappedLineItemParams = $this->mapLineItemSQLParamsToNames($sqlParams);

    $this->createSubscriptionLineItems($mappedLineItemParams);

    $financialItemId = $this->createFinancialItemRecord($lineItemId, $mappedLineItemParams);
    $this->createEntityFinancialTransactionRecord($financialItemId, $mappedLineItemParams['line_total']);

    if (!empty($mappedLineItemParams['tax_amount'])) {
      $taxFinancialItemId = $this->createTaxFinancialItemRecord($lineItemId, $mappedLineItemParams);
      $this->createTaxEntityFinancialTransactionRecord($taxFinancialItemId, $mappedLineItemParams['tax_amount']);
    }

    $this->updateRelatedContributionAmounts($mappedLineItemParams['line_total'], $mappedLineItemParams['tax_amount']);
    $this->updateRelatedRecurContributionAmount($mappedLineItemParams['line_total'], $mappedLineItemParams['tax_amount']);

    return $lineItemId;
  }

  private function prepareSqlParams() {
    $this->setPriceFieldValueDetails();
    $priceFieldId = $this->priceFieldValue['price_field_id'];
    $priceFieldValueId = $this->priceFieldValue['id'];
    $lineItemLabel = $this->getLineItemLabel();
    $quantity = $this->getQuantity();
    $unitPrice = $this->getUnitPrice();
    $lineTotal = $this->getLineTotal($unitPrice, $quantity);
    $financialTypeId = $this->getFinancialTypeId();
    $taxAmount = $this->getTaxAmount();

    return [
      1 => [$this->entityTable, 'String'],
      2 => [$this->entityId, 'Integer'],
      3 => [$this->contributionId, 'Integer'],
      4 => [$priceFieldId, 'Integer'],
      5 => [$priceFieldValueId, 'Integer'],
      6 => [$lineItemLabel, 'String'],
      7 => [$quantity, 'Integer'],
      8 => [$unitPrice, 'Money'],
      9 => [$lineTotal, 'Money'],
      10 => [$financialTypeId, 'Integer'],
      11 => [$taxAmount, 'Money'],
    ];
  }

  private function mapLineItemSQLParamsToNames($contributionSqlParams) {
    $taxAmount = empty($contributionSqlParams[11][0]) ? 0 : $contributionSqlParams[11][0];

    return [
      'entity_table' => $contributionSqlParams[1][0],
      'entity_id' => $contributionSqlParams[2][0],
      'contribution_id' => $contributionSqlParams[3][0],
      'price_field_id' => $contributionSqlParams[4][0],
      'price_field_value_id' => $contributionSqlParams[5][0],
      'line_item_label' => $contributionSqlParams[6][0],
      'quantity' => $contributionSqlParams[7][0],
      'unit_price' => $contributionSqlParams[8][0],
      'line_total' => $contributionSqlParams[9][0],
      'financial_type_id' => $contributionSqlParams[10][0],
      'tax_amount' => $taxAmount,
    ];
  }

  private function prepareSqlQuery($sqlParams) {
    $columnsToInsert = '`entity_table` , `entity_id` , `contribution_id` , `price_field_id` , `price_field_value_id`,
            `label` , `qty` , `unit_price` , `line_total` , `financial_type_id`';

    $columnsValuesIndexes = '%1, %2, %3, %4, %5, %6, %7, %8, %9, %10';

    $isThereTax = !empty($sqlParams[11][0]);
    if ($isThereTax) {
      $columnsToInsert .= ', `tax_amount`';
      $columnsValuesIndexes .= ', %11';
    }

    return "INSERT INTO `civicrm_line_item` ({$columnsToInsert})
             VALUES ({$columnsValuesIndexes})";
  }

  /**
   * Creates the subscription line items which
   * is done by duplicating line items but with
   * contribution_id empty, and then creating
   * membershipextras_subscription_line record
   * that is linked to the duplicate line item.
   *
   * hence that we only create subscription line items
   * for line items with "auto renew" flag set, which
   * should be the case only for line items that
   * belong to the last installment in an active
   * payment plan (recurring contribution).
   *
   * regarding the start date of each line item,
   * normally we set it to the minimum membership start
   * date among all payment plan memberships (in case
   * it has more than one), and since we lack such information usually
   * in the imported file, here we just set it to be the
   * start date of membership if it is a membership line item
   * , or to the contribution receive date if it is a
   * contribution line item, those after first auto-renewal,
   * a new subscription line items will be created that follow
   * the normal Membershipextras logic (which uses minimum membership
   * start date).
   *
   * @param array $mappedLineItemParams
   */
  private function createSubscriptionLineItems($mappedLineItemParams) {
    if (empty($this->rowData['line_item_auto_renew'])) {
      return;
    }

    $sqlParams = $this->prepareDuplicateLineItemSqlParams($mappedLineItemParams);
    $sqlQuery = $this->prepareDuplicateLineItemSqlQuery($sqlParams);
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as line_item_id');
    $dao->fetch();
    $duplicateLineItemId = $dao->line_item_id;

    if (!empty($this->membership)) {
      $subscriptionLineItemStartDate = $this->membership['start_date'];
    }
    else {
      $subscriptionLineItemStartDate = DateTime::createFromFormat('Y-m-d', $this->contribution['receive_date']);
      $subscriptionLineItemStartDate = $subscriptionLineItemStartDate->format('Y-m-d');
    }

    $sqlParams = [
      1 => [$this->recurContributionId, 'Integer'],
      2 => [$duplicateLineItemId, 'Integer'],
      3 => [$subscriptionLineItemStartDate, 'String'],
      4 => [1, 'Integer'],
    ];
    $sqlQuery = "INSERT INTO `membershipextras_subscription_line` (`contribution_recur_id` , `line_item_id`, `start_date`, `auto_renew`) 
                 VALUES (%1, %2, %3, %4)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function prepareDuplicateLineItemSqlParams($mappedLineItemParams) {
    return [
      1 => [$this->entityTable, 'String'],
      2 => [$this->entityId, 'Integer'],
      3 => [$mappedLineItemParams['price_field_id'], 'Integer'],
      4 => [$mappedLineItemParams['price_field_value_id'], 'Integer'],
      5 => [$mappedLineItemParams['line_item_label'], 'String'],
      6 => [$mappedLineItemParams['quantity'], 'Integer'],
      7 => [$mappedLineItemParams['unit_price'], 'Money'],
      8 => [$mappedLineItemParams['line_total'], 'Money'],
      9 => [$mappedLineItemParams['financial_type_id'], 'Integer'],
      10 => [$mappedLineItemParams['tax_amount'], 'Money'],
    ];
  }

  /**
   * Prepares the duplicate line item Insert SQL query, hence
   * that we dont' set the contribution_id (same as in Membershipextras)
   * in the duplicate line item record to avoid
   * any financial impact for it, since it is merely
   * used as a template for renewing payment plans.
   *
   * @param array $sqlParams
   *
   * @return string
   */
  private function prepareDuplicateLineItemSqlQuery($sqlParams) {
    $columnsToInsert = '`entity_table` , `entity_id` , `price_field_id` , `price_field_value_id`,
            `label` , `qty` , `unit_price` , `line_total` , `financial_type_id`';

    $columnsValuesIndexes = '%1, %2, %3, %4, %5, %6, %7, %8, %9';

    $isThereTax = !empty($sqlParams[10][0]);
    if ($isThereTax) {
      $columnsToInsert .= ', `tax_amount`';
      $columnsValuesIndexes .= ', %10';
    }

    return "INSERT INTO `civicrm_line_item` ({$columnsToInsert})
             VALUES ({$columnsValuesIndexes})";
  }

  private function setPriceFieldValueDetails() {
    if (!empty($this->rowData['line_item_price_field_id']) && !empty($this->rowData['line_item_price_field_value_id'])) {
      $this->priceFieldValue = $this->getPriceFieldValueDetailsById($this->rowData['line_item_price_field_value_id']);
      return;
    }

    if ($this->entityTable == 'civicrm_membership') {
      $this->priceFieldValue = $this->getMembershipPriceFieldValueDetails();
    }
    else {
      $defaultContributionAmountPriceFieldValueId = 1;
      $this->priceFieldValue = $this->getPriceFieldValueDetailsById($defaultContributionAmountPriceFieldValueId);
    }
  }

  private function getPriceFieldValueDetailsById($priceFieldValueId) {
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_price_field_value WHERE id = {$priceFieldValueId}");
    $dao->fetch();

    return $dao->toArray();
  }

  private function getMembershipPriceFieldValueDetails() {
    $membershipTypeId = $this->membership['membership_type_id'];
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_price_field_value WHERE membership_type_id = {$membershipTypeId} 
                                       ORDER BY id ASC LIMIT 1");
    $dao->fetch();

    return $dao->toArray();
  }

  private function getLineItemLabel() {
    if (!empty($this->rowData['line_item_label'])) {
      return $this->rowData['line_item_label'];
    }

    if ($this->entityTable == 'civicrm_membership') {
      return $this->priceFieldValue['label'];
    }
    else {
      return 'Donation';
    }
  }

  private function getQuantity() {
    if (!empty($this->rowData['line_item_quantity'])) {
      return $this->rowData['line_item_quantity'];
    }

    return 1;
  }

  private function getUnitPrice() {
    return $this->rowData['line_item_unit_price'];
  }

  private function getLineTotal($unitPrice, $quantity) {
    return MoneyUtilities::roundToCurrencyPrecision($unitPrice * $quantity);
  }

  private function getFinancialTypeId() {
    if (!isset($this->cachedValues['financial_types'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_financial_type";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['financial_types'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['financial_types'][$this->rowData['line_item_financial_type']])) {
      return $this->cachedValues['financial_types'][$this->rowData['line_item_financial_type']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidLineItemException('Invalid line item "Financial Type"', 200);
  }

  private function getTaxAmount() {
    if (!empty($this->rowData['line_item_tax_amount'])) {
      return $this->rowData['line_item_tax_amount'];
    }

    return 0;
  }

  private function createFinancialItemRecord($lineItemId, $mappedLineItemParams) {
    // This is reserved value for income account relationship and will always equal such value on any CiviCRM site
    $incomeAccountRelationshipId = 1;
    $toFinancialAccountId = $this->getFinancialAccountIdByRelationship($mappedLineItemParams['financial_type_id'], $incomeAccountRelationshipId);

    $sqlParams = [
      1 => [$this->contribution['contact_id'], 'Integer'],
      2 => [$mappedLineItemParams['line_item_label'], 'String'],
      3 => [$mappedLineItemParams['line_total'], 'Money'],
      4 => [$this->contribution['currency'], 'String'],
      5 => [$toFinancialAccountId, 'Integer'],
      6 => [$this->getFinancialItemStatusId(), 'Integer'],
      7 => ['civicrm_line_item', 'String'],
      8 => [$lineItemId, 'Integer'],
      9 => [$this->contribution['receive_date'], 'String'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_financial_item` (`contact_id` , `description` , `amount` , `currency` ,
                 `financial_account_id` , `status_id` , `entity_table` , `entity_id`, `transaction_date`) 
                 VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as id');
    $dao->fetch();

    return $dao->id;
  }

  private function createTaxFinancialItemRecord($lineItemId, $mappedLineItemParams) {
    // This is reserved value for sales account relationship and will always equal such value on any CiviCRM site
    $salesTaxAccountRelationshipId = 10;
    $toFinancialAccountId = $this->getFinancialAccountIdByRelationship($mappedLineItemParams['financial_type_id'], $salesTaxAccountRelationshipId);

    $sqlParams = [
      1 => [$this->contribution['contact_id'], 'Integer'],
      2 => ['Sales Tax', 'String'],
      3 => [$mappedLineItemParams['tax_amount'], 'Money'],
      4 => [$this->contribution['currency'], 'String'],
      5 => [$toFinancialAccountId, 'Integer'],
      6 => [$this->getFinancialItemStatusId(), 'Integer'],
      7 => ['civicrm_line_item', 'String'],
      8 => [$lineItemId, 'Integer'],
      9 => [$this->contribution['receive_date'], 'String'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_financial_item` (`contact_id` , `description` , `amount` , `currency` ,
                 `financial_account_id` , `status_id` , `entity_table` , `entity_id`, `transaction_date`) 
                 VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as id');
    $dao->fetch();

    return $dao->id;
  }

  private function getFinancialAccountIdByRelationship($financialTypeId, $accountRelationship) {
    $sqlQuery = "SELECT financial_account_id FROM civicrm_entity_financial_account 
                   WHERE entity_table = 'civicrm_financial_type' AND entity_id = {$financialTypeId} AND account_relationship = {$accountRelationship}";
    $result = SQLQueryRunner::executeQuery($sqlQuery);
    $result->fetch();

    return $result->financial_account_id;
  }

  private function getFinancialItemStatusId() {
    $contributionStatusId = $this->contribution['contribution_status_id'];

    // Hardcoded Ids are used for efficiency reasons
    // and because they are also reserved and their ids
    // are always the same on any CiviCRM site.

    // 1 = Completed, 9 = Pending Refund
    $paidContributionStatues = [1, 9];
    // 2 = Pending, 5 = In Progress
    $unpaidContributionStatues = [2, 5];
    // 8 = Partially paid
    $partiallyPaidContributionStatues = [8];

    $lineStatusId = NULL;
    if (in_array($contributionStatusId, $paidContributionStatues)) {
      // 1 = Paid
      $lineStatusId = 1;
    }
    elseif (in_array($contributionStatusId, $unpaidContributionStatues)) {
      // 3 = Unpaid
      $lineStatusId = 3;
    }
    elseif (in_array($contributionStatusId, $partiallyPaidContributionStatues)) {
      // 2 = Partially paid
      $lineStatusId = 2;
    }

    return $lineStatusId;
  }

  private function createEntityFinancialTransactionRecord($financialItemId, $amount) {
    $sqlParams = [
      1 => ['civicrm_financial_item', 'String'],
      2 => [$financialItemId, 'Integer'],
      3 => [$this->getContributionFinancialTrxnId(), 'Integer'],
      4 => [$amount, 'Money'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_entity_financial_trxn` (`entity_table` , `entity_id` , `financial_trxn_id` , `amount`) 
                 VALUES (%1, %2, %3, %4)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function createTaxEntityFinancialTransactionRecord($financialItemId, $taxAmount) {
    $sqlParams = [
      1 => ['civicrm_financial_item', 'String'],
      2 => [$financialItemId, 'Integer'],
      3 => [$this->getContributionFinancialTrxnId(), 'Integer'],
      4 => [$taxAmount, 'Money'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_entity_financial_trxn` (`entity_table` , `entity_id` , `financial_trxn_id` , `amount`) 
                 VALUES (%1, %2, %3, %4)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function getContributionFinancialTrxnId() {
    if (!empty($this->cachedValues['contribution_financial_trxn_Id'])) {
      return $this->cachedValues['contribution_financial_trxn_Id'];
    }

    $dao = SQLQueryRunner::executeQuery("SELECT financial_trxn_id FROM civicrm_entity_financial_trxn WHERE entity_table = 'civicrm_contribution' AND entity_id = {$this->contributionId} LIMIT 1");
    $dao->fetch();

    $this->cachedValues['contribution_financial_trxn_Id'] = $dao->financial_trxn_id;

    return $dao->financial_trxn_id;
  }

  /**
   * Updates the contribution amounts.
   *
   * Here we update both the contribution
   * total amount and tax amount, the mechanism
   * to do so is by keeping adding the line
   * item amounts we currently processing
   * to the related contribution amounts ,
   * since line items are processed row by row and
   * there is no way to know the total amount in advance.
   *
   * Hence that the total amount is both the
   * the amount + the tax amount same as in
   * CiviCRM core.
   *
   * @param float $lineItemTotalAmount
   * @param float $taxAmount
   */
  private function updateRelatedContributionAmounts($lineItemTotalAmount, $taxAmount) {
    $totalAmount = $lineItemTotalAmount + $taxAmount;

    $amountFieldOperation = '`total_amount` = `total_amount` + %1, `net_amount` = `net_amount` + %1';
    $sqlParams[1] = [$totalAmount, 'Money'];

    if (!empty($taxAmount)) {
      $amountFieldOperation .= ', `tax_amount` = IFNULL(`tax_amount`, 0) + %2';
      $sqlParams[2] = [$taxAmount, 'Money'];
    }

    $sqlQuery = "UPDATE `civicrm_contribution` SET {$amountFieldOperation} WHERE id = {$this->contributionId}";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $trxSqlParams[1] = [$totalAmount, 'Money'];
    $sqlQuery = "UPDATE `civicrm_entity_financial_trxn` ceft 
                 INNER JOIN civicrm_financial_trxn cft ON ceft.financial_trxn_id = cft.id 
                 SET ceft.amount = ceft.amount + %1, cft.total_amount = cft.total_amount + %1, cft.net_amount = cft.net_amount + %1 
                 WHERE ceft.entity_table = 'civicrm_contribution' AND ceft.entity_id = {$this->contributionId}";
    SQLQueryRunner::executeQuery($sqlQuery, $trxSqlParams);
  }

  /**
   * Updates the recurring contribution amount.
   *
   * Which is achieved by keep adding the line
   * item amount (plus tax) we currently processing
   * to the related recurring contribution amount,
   * since line items are processed row by row and
   * there is no way to know the total amount in advance.
   *
   * Hence that we only add amounts for line
   * items with "auto renew" flag set to True,
   * since it should only be set for last installment
   * in active payment plans (recurring contributions),
   * given that the recurring contribution amount usually
   * represents a single installment amount.
   *
   * @param float $lineItemTotalAmount
   * @param float $taxAmount
   */
  private function updateRelatedRecurContributionAmount($lineItemTotalAmount, $taxAmount) {
    if (empty($this->rowData['line_item_auto_renew'])) {
      return;
    }

    $totalAmount = $lineItemTotalAmount + $taxAmount;
    $sqlParams[1] = [$totalAmount, 'Money'];

    $sqlQuery = "UPDATE `civicrm_contribution_recur` SET `amount` = `amount` + %1 WHERE id = {$this->recurContributionId}";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

}
