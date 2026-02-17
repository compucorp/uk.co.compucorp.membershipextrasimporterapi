<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;

/**
 * Creates a line item for a recurring donation contribution.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_LineItem {

  private $rowData;

  private $contributionId;

  private $contribution;

  public function __construct($rowData, $contributionId) {
    $this->rowData = $rowData;
    $this->contributionId = $contributionId;
    $this->setContribution($contributionId);
  }

  private function setContribution($contributionId) {
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_contribution WHERE id = %1", [1 => [$contributionId, 'Integer']]);
    $dao->fetch();
    $this->contribution = $dao->toArray();
  }

  /**
   * Creates a line item with associated financial records.
   *
   * @return int
   *   The line item ID.
   */
  public function import() {
    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_line_item` (`entity_table` , `entity_id` , `contribution_id` , `price_field_id` , `price_field_value_id`,
            `label` , `qty` , `unit_price` , `line_total` , `financial_type_id`)
             VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as line_item_id');
    $dao->fetch();
    $lineItemId = $dao->line_item_id;

    $mappedLineItemParams = $this->mapLineItemSQLParamsToNames($sqlParams);

    $financialItemId = $this->createFinancialItemRecord($lineItemId, $mappedLineItemParams);
    $this->createEntityFinancialTransactionRecord($financialItemId, $mappedLineItemParams['line_total']);

    $this->updateRelatedContributionAmounts($mappedLineItemParams['line_total']);

    return $lineItemId;
  }

  private function prepareSqlParams() {
    $defaultContributionAmountPriceFieldValueId = 1;
    $priceFieldValue = $this->getPriceFieldValueDetailsById($defaultContributionAmountPriceFieldValueId);
    $priceFieldId = $priceFieldValue['price_field_id'];
    $priceFieldValueId = $priceFieldValue['id'];
    $lineItemLabel = $this->getLineItemLabel();
    $quantity = 1;
    $unitPrice = $this->rowData['recurring_contribution_amount'];
    $lineTotal = $unitPrice;
    $financialTypeId = $this->getFinancialTypeId();

    return [
      1 => ['civicrm_contribution', 'String'],
      2 => [$this->contributionId, 'Integer'],
      3 => [$this->contributionId, 'Integer'],
      4 => [$priceFieldId, 'Integer'],
      5 => [$priceFieldValueId, 'Integer'],
      6 => [$lineItemLabel, 'String'],
      7 => [$quantity, 'Integer'],
      8 => [$unitPrice, 'Money'],
      9 => [$lineTotal, 'Money'],
      10 => [$financialTypeId, 'Integer'],
    ];
  }

  private function mapLineItemSQLParamsToNames($sqlParams) {
    return [
      'entity_table' => $sqlParams[1][0],
      'entity_id' => $sqlParams[2][0],
      'contribution_id' => $sqlParams[3][0],
      'price_field_id' => $sqlParams[4][0],
      'price_field_value_id' => $sqlParams[5][0],
      'line_item_label' => $sqlParams[6][0],
      'quantity' => $sqlParams[7][0],
      'unit_price' => $sqlParams[8][0],
      'line_total' => $sqlParams[9][0],
      'financial_type_id' => $sqlParams[10][0],
    ];
  }

  private function getPriceFieldValueDetailsById($priceFieldValueId) {
    $dao = SQLQueryRunner::executeQuery("SELECT * FROM civicrm_price_field_value WHERE id = %1", [1 => [$priceFieldValueId, 'Integer']]);
    $dao->fetch();

    return $dao->toArray();
  }

  private function getLineItemLabel() {
    $financialTypeName = !empty($this->rowData['recurring_contribution_financial_type'])
      ? $this->rowData['recurring_contribution_financial_type']
      : 'Donation';

    return $financialTypeName;
  }

  private function getFinancialTypeId() {
    $financialTypeName = !empty($this->rowData['recurring_contribution_financial_type'])
      ? $this->rowData['recurring_contribution_financial_type']
      : 'Donation';

    $financialTypes = OptionValueCache::getFinancialTypes();

    if (!empty($financialTypes[$financialTypeName])) {
      return $financialTypes[$financialTypeName];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException('Invalid line item "Financial Type"', 200);
  }

  private function createFinancialItemRecord($lineItemId, $mappedLineItemParams) {
    $incomeAccountRelationshipId = 1;
    $toFinancialAccountId = $this->getFinancialAccountIdByRelationship($mappedLineItemParams['financial_type_id'], $incomeAccountRelationshipId);

    $sqlParams = [
      1 => [intval($this->contribution['contact_id']), 'Integer'],
      2 => [$mappedLineItemParams['line_item_label'], 'String'],
      3 => [$mappedLineItemParams['line_total'], 'Money'],
      4 => [$this->contribution['currency'], 'String'],
      5 => [intval($toFinancialAccountId), 'Integer'],
      6 => [intval($this->getFinancialItemStatusId()), 'Integer'],
      7 => ['civicrm_line_item', 'String'],
      8 => [intval($lineItemId), 'Integer'],
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
                   WHERE entity_table = 'civicrm_financial_type' AND entity_id = %1 AND account_relationship = %2";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$financialTypeId, 'Integer'], 2 => [$accountRelationship, 'Integer']]);
    $result->fetch();

    return $result->financial_account_id;
  }

  private function getFinancialItemStatusId() {
    $contributionStatusId = $this->contribution['contribution_status_id'];

    // 2 = Pending, 5 = In Progress
    $unpaidContributionStatues = [2, 5];

    if (in_array($contributionStatusId, $unpaidContributionStatues)) {
      // 3 = Unpaid
      return 3;
    }

    // 1 = Paid (fallback)
    return 1;
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

  private function getContributionFinancialTrxnId() {
    $sqlQuery = "SELECT financial_trxn_id FROM civicrm_entity_financial_trxn WHERE entity_table = 'civicrm_contribution' AND entity_id = %1 LIMIT 1";
    $dao = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->contributionId, 'Integer']]);
    $dao->fetch();

    return $dao->financial_trxn_id;
  }

  /**
   * Updates the contribution total_amount and net_amount
   * based on the line item total.
   *
   * @param float $lineItemTotalAmount
   *   The line item total amount.
   */
  private function updateRelatedContributionAmounts($lineItemTotalAmount) {
    $sqlParams = [
      1 => [$lineItemTotalAmount, 'Money'],
      2 => [$this->contributionId, 'Integer'],
    ];

    $sqlQuery = "UPDATE `civicrm_contribution` SET `total_amount` = `total_amount` + %1, `net_amount` = `net_amount` + %1 WHERE id = %2";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $trxSqlParams = [
      1 => [$lineItemTotalAmount, 'Money'],
      2 => [$this->contributionId, 'Integer'],
    ];
    $sqlQuery = "UPDATE `civicrm_entity_financial_trxn` ceft
                 INNER JOIN civicrm_financial_trxn cft ON ceft.financial_trxn_id = cft.id
                 SET ceft.amount = ceft.amount + %1, cft.total_amount = cft.total_amount + %1, cft.net_amount = cft.net_amount + %1
                 WHERE ceft.entity_table = 'civicrm_contribution' AND ceft.entity_id = %2";
    SQLQueryRunner::executeQuery($sqlQuery, $trxSqlParams);
  }

}
