<?php

use CRM_MembershipExtras_Service_MoneyUtilities as MoneyUtilities;

class CRM_Membershipextrasimporterapi_EntityImporter_LineItem {

  private $rowData;

  private $contributionId;

  private $membershipId;

  private $cachedValues;

  private $entityTable;

  private $entityId;

  private $contribution;

  private $membership;

  private $priceFieldValue;

  public function __construct($rowData, $contributionId, $membershipId) {
    $this->rowData = $rowData;
    $this->contributionId = $contributionId;
    $this->membershipId = $membershipId;
    $this->setContribution($contributionId);
    $this->setMembership($membershipId);
    $this->setEntityTable();
    $this->setEntityId();
  }

  private function setContribution($contributionId) {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_contribution WHERE id = {$contributionId}");
    $dao->fetch();
    $this->contribution = $dao->toArray();
  }

  private function setMembership($membershipId) {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_membership WHERE id = {$membershipId}");
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
    $lineItemId = $this->getLineItemIfExist();
    if ($lineItemId) {
      return $lineItemId;
    }

    $sqlParams = $this->prepareSqlParams();
    $sql = "INSERT INTO `civicrm_line_item` (`entity_table` , `entity_id` , `contribution_id` , `price_field_id` , `price_field_value_id`,
            `label` , `qty` , `unit_price` , `line_total` , `financial_type_id`) 
             VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10)";
    CRM_Core_DAO::executeQuery($sql, $sqlParams);

    $dao = CRM_Core_DAO::executeQuery('SELECT LAST_INSERT_ID() as line_item_id');
    $dao->fetch();
    $lineItemId = $dao->line_item_id;

    $mappedLineItemParams = $this->mapLineItemSQLParamsToNames($sqlParams);
    $financialItemId = $this->createFinancialItemRecord($lineItemId, $mappedLineItemParams);
    $this->createEntityFinancialTransactionRecord($financialItemId, $mappedLineItemParams['line_total']);

    return $lineItemId;
  }

  private function getLineItemIfExist() {
    // todo : What if we have more than one "donation" line item (we probably need to match using price fields)
    $query = "SELECT id FROM civicrm_line_item WHERE entity_table = %1 AND entity_id = %2 AND contribution_id = %3";
    $sqlParams = [
      1 => [$this->entityTable, 'String'],
      2 => [$this->entityId, 'Integer'],
      3 => [$this->contributionId, 'Integer'],
    ];
    $lineItemId = CRM_Core_DAO::executeQuery($query, $sqlParams);
    $lineItemId->fetch();

    if (!empty($lineItemId->id)) {
      return $lineItemId->id;
    }

    return NULL;
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
    ];
  }

  private function mapLineItemSQLParamsToNames($contributionSqlParams) {
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
    ];
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
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_price_field_value WHERE id = {$priceFieldValueId}");
    $dao->fetch();
    return $dao->toArray();
  }

  private function getMembershipPriceFieldValueDetails() {
    $membershipTypeId = $this->membership['membership_type_id'];
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_price_field_value WHERE membership_type_id = {$membershipTypeId} 
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
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['financial_types'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['financial_types'][$this->rowData['line_item_financial_type']])) {
      return $this->cachedValues['financial_types'][$this->rowData['line_item_financial_type']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidLineItemException('Invalid line item "Financial Type"', 200);
  }

  private function createFinancialItemRecord($lineItemId, $mappedLineItemParams) {
    $sqlParams = [
      1 => [$this->contribution['contact_id'], 'Integer'],
      2 => [$mappedLineItemParams['line_item_label'], 'String'],
      3 => [$mappedLineItemParams['line_total'], 'Money'],
      4 => [$this->contribution['currency'], 'String'],
      5 => [$this->getToFinancialAccountId($mappedLineItemParams['financial_type_id']), 'Integer'],
      6 => [$this->getFinancialItemStatusId(), 'Integer'],
      7 => ['civicrm_line_item', 'String'],
      8 => [$lineItemId, 'Integer'],
      9 => [$this->contribution['receive_date'], 'String'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_financial_item` (`contact_id` , `description` , `amount` , `currency` ,
                 `financial_account_id` , `status_id` , `entity_table` , `entity_id`, `transaction_date`) 
                 VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)";
    CRM_Core_DAO::executeQuery($sqlQuery, $sqlParams);

    $dao = CRM_Core_DAO::executeQuery('SELECT LAST_INSERT_ID() as id');
    $dao->fetch();
    return $dao->id;
  }

  private function getToFinancialAccountId($financialTypeId) {
    // this is reserved value and will always equal such value on any CiviCRM site
    $incomeAccountRelationshipId = 1;

    $sqlQuery = "SELECT financial_account_id FROM civicrm_entity_financial_account 
                   WHERE entity_table = 'civicrm_financial_type' AND entity_id = {$financialTypeId} AND account_relationship = {$incomeAccountRelationshipId}";
    $result = CRM_Core_DAO::executeQuery($sqlQuery);
    $result->fetch();
    return $result->financial_account_id;
  }

  private function getFinancialItemStatusId() {
    $contributionStatusId = $this->contribution['contribution_status_id'];

    // hardcoded Ids are used for efficiency reasons
    // and because they are also reserved and their ids
    // are always the same on any CiviCRM site.
    $paidContributionStatues = [1, 9];
    $unpaidContributionStatues = [2, 5];
    $partiallyPaidContributionStatues = [8];

    if (in_array($contributionStatusId, $paidContributionStatues)) {
      $lineStatusId = 1;
    }
    elseif (in_array($contributionStatusId, $unpaidContributionStatues)) {
      $lineStatusId = 3;
    }
    elseif (in_array($contributionStatusId, $partiallyPaidContributionStatues)) {
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
    CRM_Core_DAO::executeQuery($sqlQuery, $sqlParams);
  }

  private function getContributionFinancialTrxnId() {
    $dao = CRM_Core_DAO::executeQuery("SELECT financial_trxn_id FROM civicrm_entity_financial_trxn WHERE entity_table = 'civicrm_contribution' AND entity_id = {$this->contributionId} LIMIT 1");
    $dao->fetch();
    return $dao->financial_trxn_id;
  }

}
