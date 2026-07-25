<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;
use CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException as InvalidFieldException;

/**
 * Creates the first pending contribution linked to a recurring contribution.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_Contribution {

  private $rowData;

  private $contactId;

  private $recurContributionId;

  public function __construct($rowData, $contactId, $recurContributionId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
    $this->recurContributionId = $recurContributionId;
  }

  /**
   * Creates a pending contribution and associated financial records.
   *
   * @return int
   *   The contribution ID.
   */
  public function import() {
    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_contribution` (`contact_id` , `financial_type_id` , `payment_instrument_id` ,
                 `receive_date` , `total_amount` , `net_amount` , `currency`, `contribution_recur_id` , `is_pay_later`,
                  `contribution_status_id`, `invoice_number`, `source`)
                 VALUES (%1, %2, %3, %4, %5, %5, %6, %7, %8, %9, %10, %11)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as contribution_id');
    $dao->fetch();
    $contributionId = $dao->contribution_id;

    $mappedContributionParams = $this->mapContributionSQLParamsToNames($sqlParams);
    $financialTransactionId = $this->createFinancialTransactionRecord($mappedContributionParams);
    $this->createEntityFinancialTransactionRecord($contributionId, $financialTransactionId, $mappedContributionParams);

    return $contributionId;
  }

  private function prepareSqlParams() {
    $financialTypeId = $this->getFinancialTypeId();
    $paymentMethodId = $this->getPaymentMethodId();
    $receiveDate = $this->getReceiveDate();
    // Total amount starts at 0 — it will be accumulated by LineItem importer.
    $totalAmount = 0;
    $currency = $this->getCurrency();
    $contributionStatusId = $this->getPendingStatusId();
    $invoiceNumber = $this->calculateInvoiceNumber();

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$financialTypeId, 'Integer'],
      3 => [$paymentMethodId, 'Integer'],
      4 => [$receiveDate, 'String'],
      5 => [$totalAmount, 'Money'],
      6 => [$currency, 'String'],
      7 => [$this->recurContributionId, 'Integer'],
      8 => [0, 'Integer'],
      9 => [$contributionStatusId, 'Integer'],
      10 => [$invoiceNumber, 'String'],
      11 => ['Recurring Donation Importer at: ' . date('Y-m-d H:i'), 'String'],
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
    $financialTypeName = !empty($this->rowData['recurring_contribution_financial_type'])
      ? $this->rowData['recurring_contribution_financial_type']
      : 'Donation';

    $financialTypes = OptionValueCache::getFinancialTypes();

    if (!empty($financialTypes[$financialTypeName])) {
      return $financialTypes[$financialTypeName];
    }

    throw new InvalidFieldException('Invalid contribution "Financial Type"', 100);
  }

  private function getPaymentMethodId() {
    $paymentMethodName = !empty($this->rowData['recurring_contribution_payment_instrument'])
      ? $this->rowData['recurring_contribution_payment_instrument']
      : 'direct_debit_gocardless';

    $paymentMethods = OptionValueCache::getPaymentMethods();

    if (!empty($paymentMethods[$paymentMethodName])) {
      return $paymentMethods[$paymentMethodName];
    }

    throw new InvalidFieldException('Invalid contribution "Payment Instrument"', 200);
  }

  private function getReceiveDate() {
    if (!empty($this->rowData['recurring_contribution_start_date'])) {
      $date = DateTime::createFromFormat('Y-m-d', $this->rowData['recurring_contribution_start_date']);
      if ($date) {
        return $date->format('Y-m-d');
      }
    }

    $date = new DateTime();
    return $date->format('Y-m-d');
  }

  private function getCurrency() {
    $currencies = OptionValueCache::getCurrencies();

    if (!empty($currencies[strtolower($this->rowData['recurring_contribution_currency'])])) {
      return $currencies[strtolower($this->rowData['recurring_contribution_currency'])];
    }

    throw new InvalidFieldException('Invalid or disabled contribution "currency"', 400);
  }

  private function getPendingStatusId() {
    $statuses = OptionValueCache::getContributionStatuses();

    if (!empty($statuses['pending'])) {
      return $statuses['pending'];
    }

    throw new InvalidFieldException('Cannot find Pending contribution status', 300);
  }

  private function calculateInvoiceNumber() {
    if (CRM_Invoicing_Utils::isInvoicingEnabled()) {
      $nextContributionID = CRM_Core_DAO::singleValueQuery('SELECT COALESCE(MAX(id) + 1, 1) FROM civicrm_contribution');
      return CRM_Contribute_BAO_Contribution::getInvoiceNumber($nextContributionID);
    }

    return '';
  }

  private function createFinancialTransactionRecord($mappedContributionParams) {
    $sqlParams = [
      1 => [$this->getToFinancialAccountId(), 'Integer'],
      2 => [$mappedContributionParams['total_amount'], 'Money'],
      3 => [$mappedContributionParams['currency'], 'String'],
      4 => [$mappedContributionParams['contribution_status_id'], 'Integer'],
      5 => [$mappedContributionParams['payment_method_id'], 'Integer'],
      6 => [$mappedContributionParams['receive_date'], 'String'],
      7 => [$mappedContributionParams['total_amount'], 'Money'],
      8 => [0, 'Integer'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_financial_trxn` (`to_financial_account_id`, `total_amount` , `currency`, `status_id` , `payment_instrument_id`,
                `trxn_date`, `net_amount`, `is_payment`)
            VALUES (%1 , %2, %3, %4, %5, %6, %7, %8)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as id');
    $dao->fetch();
    return $dao->id;
  }

  private function getToFinancialAccountId() {
    $paymentProcessorId = $this->getPaymentProcessorIdFromRecurContribution();
    $sqlQuery = "SELECT financial_account_id FROM civicrm_entity_financial_account
                   WHERE entity_table = 'civicrm_payment_processor' AND entity_id = %1";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$paymentProcessorId, 'Integer']]);
    $result->fetch();
    return $result->financial_account_id;
  }

  private function getPaymentProcessorIdFromRecurContribution() {
    $sqlQuery = "SELECT payment_processor_id from civicrm_contribution_recur WHERE id = %1";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->recurContributionId, 'Integer']]);
    $result->fetch();
    return $result->payment_processor_id;
  }

  private function createEntityFinancialTransactionRecord($contributionId, $financialTransactionId, $mappedContributionParams) {
    $sqlParams = [
      1 => [$contributionId, 'Integer'],
      2 => [$financialTransactionId, 'Integer'],
      3 => [$mappedContributionParams['total_amount'], 'Money'],
    ];
    $sqlQuery = "INSERT INTO `civicrm_entity_financial_trxn` (`entity_table`, `entity_id` , `financial_trxn_id`, `amount`)
                VALUES ('civicrm_contribution', %1, %2, %3)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

}
