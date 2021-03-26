<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

class CRM_Membershipextrasimporterapi_EntityImporter_Contribution {

  private $rowData;

  private $contactId;

  private $recurContributionId;

  private $cachedValues;

  public function __construct($rowData, $contactId, $recurContributionId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
    $this->recurContributionId = $recurContributionId;
  }

  public function import() {
    $contributionId = $this->getContributionIdIfExist();
    if ($contributionId) {
      return $contributionId;
    }

    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_contribution` (`contact_id` , `financial_type_id` , `payment_instrument_id` , 
                 `receive_date` , `total_amount` , `currency`, `contribution_recur_id` , `is_pay_later`,
                  `contribution_status_id`, `invoice_number`, `source`) 
                 VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, %11)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as contribution_id');
    $dao->fetch();
    $contributionId = $dao->contribution_id;

    $sqlQuery = "INSERT INTO `civicrm_value_contribution_ext_id` (`entity_id` , `external_id`) 
           VALUES ({$contributionId}, %1)";
    SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contribution_external_id'], 'String']]);

    $mappedContributionParams = $this->mapContributionSQLParamsToNames($sqlParams);
    $financialTransactionId = $this->createFinancialTransactionRecord($mappedContributionParams);
    $this->createEntityFinancialTransactionRecord($contributionId, $financialTransactionId, $mappedContributionParams);

    return $contributionId;
  }

  private function getContributionIdIfExist() {
    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_ext_id WHERE external_id = %1";
    $contributionId = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contribution_external_id'], 'String']]);
    $contributionId->fetch();

    if (!empty($contributionId->id)) {
      return $contributionId->id;
    }

    return NULL;
  }

  private function prepareSqlParams() {
    $financialTypeId = $this->getFinancialTypeId();
    $paymentMethodId = $this->getPaymentMethodId();
    $receiveDate = $this->formatRowDate('contribution_received_date');
    $totalAmount = $this->getTotalAmount();
    $currency = $this->getCurrency();
    $isPayLater = $this->calculateIsPayLaterFlag();
    $contributionStatusId  = $this->getContributionStatusId();
    $invoiceNumber = $this->calculateInvoiceNumber();

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$financialTypeId, 'Integer'],
      3 => [$paymentMethodId, 'Integer'],
      4 => [$receiveDate, 'Date'],
      5 => [$totalAmount, 'Money'],
      6 => [$currency, 'String'],
      7 => [$this->recurContributionId, 'Integer'],
      8 => [$isPayLater, 'Integer'],
      9 => [$contributionStatusId, 'Integer'],
      10 => [$invoiceNumber, 'String'],
      11 => ['Membershipextras Importer at: ' . date('Y-m-d H:i'), 'String'],
    ];
  }

  private function mapContributionSQLParamsToNames($contributionSqlParams) {
    return [
      'contact_id' => $contributionSqlParams[1][0],
      'financial_type_id' => $contributionSqlParams[2][0],
      'payment_method_id' => $contributionSqlParams[3][0],
      'receive_date' => $contributionSqlParams[4][0],
      'total_amount' => $contributionSqlParams[5][0],
      'currency' => $contributionSqlParams[6][0],
      'recur_contribution_id' => $contributionSqlParams[7][0],
      'is_pay_later' => $contributionSqlParams[8][0],
      'contribution_status_id' => $contributionSqlParams[9][0],
      'invoice_number' => $contributionSqlParams[10][0],
    ];
  }

  private function getFinancialTypeId() {
    if (!isset($this->cachedValues['financial_types'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_financial_type";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['financial_types'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['financial_types'][$this->rowData['contribution_financial_type']])) {
      return $this->cachedValues['financial_types'][$this->rowData['contribution_financial_type']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException('Invalid contribution "Financial Type"', 100);
  }

  private function getPaymentMethodId() {
    if (!isset($this->cachedValues['payment_methods'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'payment_instrument'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['payment_methods'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['payment_methods'][$this->rowData['contribution_payment_method']])) {
      return $this->cachedValues['payment_methods'][$this->rowData['contribution_payment_method']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException('Invalid contribution "Payment Method"', 200);
  }

  private function formatRowDate($dateColumnName) {
    if (!empty($this->rowData[$dateColumnName])) {
      $date = DateTime::createFromFormat('YmdHis', $this->rowData[$dateColumnName]);
      $date = $date->format('YmdHis');
    }
    else {
      $date = new DateTime();
      $date = $date->format('YmdHis');
    }

    return $date;
  }

  private function getTotalAmount() {
    // The total amount will be set as part of line item creation and not here.
    return 0;
  }

  private function getCurrency() {
    if (!isset($this->cachedValues['currencies_enabled'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'currencies_enabled'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['currencies_enabled'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['currencies_enabled'][$this->rowData['contribution_currency']])) {
      return $this->cachedValues['currencies_enabled'][$this->rowData['contribution_currency']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException('Invalid or disabled contribution "currency"', 400);
  }

  private function calculateIsPayLaterFlag() {
    $statusName = 'Completed';
    if (!empty($this->rowData['contribution_status'])) {
      $statusName = $this->rowData['contribution_status'];
    }

    if (in_array($statusName, ['Pending', 'In Progress'])) {
      return 1;
    }

    return 0;
  }

  private function getContributionStatusId() {
    $statusName = 'Completed';
    if (!empty($this->rowData['contribution_status'])) {
      $statusName = $this->rowData['contribution_status'];
    }

    if (!isset($this->cachedValues['contribution_statuses'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'contribution_status'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['contribution_statuses'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['contribution_statuses'][$statusName])) {
      return $this->cachedValues['contribution_statuses'][$statusName];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContributionFieldException('Invalid contribution "Status"', 300);
  }

  /**
   * Calculates the invoice number
   * in similar fashion to how CiviCRM
   * does it.
   *
   * @return string
   */
  private function calculateInvoiceNumber() {
    if (!empty($this->rowData['contribution_invoice_number'])) {
      return $this->rowData['contribution_invoice_number'];
    }

    if (CRM_Invoicing_Utils::isInvoicingEnabled()) {
      $nextContributionID = CRM_Core_DAO::singleValueQuery('SELECT COALESCE(MAX(id) + 1, 1) FROM civicrm_contribution');
      return CRM_Contribute_BAO_Contribution::getInvoiceNumber($nextContributionID);
    }

    return '';
  }

  private function createFinancialTransactionRecord($mappedContributionParams) {
    $isPayment = 0;
    if (!$mappedContributionParams['is_pay_later']) {
      $isPayment = 1;
    }

    $sqlParams = [
      1 => [$this->getToFinancialAccountId(), 'Integer'],
      2 => [$mappedContributionParams['total_amount'], 'Money'],
      3 => [$mappedContributionParams['currency'], 'String'],
      4 => [$mappedContributionParams['contribution_status_id'], 'Integer'],
      5 => [$mappedContributionParams['payment_method_id'], 'Integer'],
      6 => [$mappedContributionParams['receive_date'], 'String'],
      7 => [$mappedContributionParams['total_amount'], 'Money'],
      8 => [$isPayment, 'Integer'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_financial_trxn` (`to_financial_account_id`, `total_amount` , `currency`, `status_id` , `payment_instrument_id`,
                `trxn_date`, `net_amount`, `is_payment`) 
            VALUES (%1 , %2, %3, %4, %5, %6, %7, %8)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as id');
    $dao->fetch();
    return $dao->id;
  }

  /**
   * Gets the "To Financial account id" for the contribution.
   *
   * Given that the payment processor is required field
   * for the import, we are using it to get to
   * get the financial account value.
   *
   * @return int
   */
  private function getToFinancialAccountId() {
    $paymentProcessorId = $this->getPaymentProcessorIdFromRecurContribution();
    $sqlQuery = "SELECT financial_account_id FROM civicrm_entity_financial_account 
                   WHERE entity_table = 'civicrm_payment_processor' AND entity_id = {$paymentProcessorId}";
    $result = SQLQueryRunner::executeQuery($sqlQuery);
    $result->fetch();
    return $result->financial_account_id;
  }

  private function getPaymentProcessorIdFromRecurContribution() {
    $sqlQuery = "SELECT payment_processor_id from civicrm_contribution_recur WHERE id = {$this->recurContributionId}";
    $result = SQLQueryRunner::executeQuery($sqlQuery);
    $result->fetch();
    return $result->payment_processor_id;
  }

  private function createEntityFinancialTransactionRecord($contributionId, $financialTransactionId, $mappedContributionParams) {
    $sqlParams = [
      1 => [$mappedContributionParams['total_amount'], 'String'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_entity_financial_trxn` (`entity_table`, `entity_id` , `financial_trxn_id`, `amount`) 
                VALUES ('civicrm_contribution', {$contributionId}, {$financialTransactionId}, %1)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

}
