<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

class CRM_Membershipextrasimporterapi_EntityImporter_DirectDebitMandate {

  private $rowData;

  private $contactId;

  private $recurContributionId;

  private $contributionId;

  private $cachedValues;

  public function __construct($rowData, $contactId, $recurContributionId, $contributionId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
    $this->recurContributionId = $recurContributionId;
    $this->contributionId = $contributionId;
  }

  public function import() {
    if (!$this->isDirectDebitPaymentProcessor()) {
      return NULL;
    }

    $this->validateMandateReference();

    $mandateId = $this->getMandateIdIfExist();
    if ($mandateId) {
      $this->updateMandate($mandateId);
    }
    else {
      $mandateId = $this->createMandate();
    }

    if ($this->isRecurContributionAttachedToAnyMandate()) {
      $sql = "UPDATE `dd_contribution_recurr_mandate_ref` SET `mandate_id` = {$mandateId} WHERE `recurr_id` = {$this->recurContributionId}";
      SQLQueryRunner::executeQuery($sql);
    }
    else {
      $sql = "INSERT INTO `dd_contribution_recurr_mandate_ref` (`recurr_id` , `mandate_id`) 
           VALUES ({$this->recurContributionId} , {$mandateId})";
      SQLQueryRunner::executeQuery($sql);
    }

    if ($this->isDirectDebitContribution() && !$this->isMandateContributionRefExist($mandateId)) {
      $sql = "INSERT INTO `civicrm_value_dd_information` (`mandate_id` , `entity_id`) 
           VALUES ({$mandateId} , {$this->contributionId})";
      SQLQueryRunner::executeQuery($sql);
    }

    return $mandateId;
  }

  private function isDirectDebitPaymentProcessor() {
    if ($this->rowData['payment_plan_payment_processor'] == 'Direct Debit') {
      return TRUE;
    }

    return FALSE;
  }

  private function validateMandateReference() {
    if (empty($this->rowData['direct_debit_mandate_reference'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate reference is required for Direct Debit payment plans', 100);
    }
  }

  private function getMandateIdIfExist() {
    $sql = "SELECT id FROM civicrm_value_dd_mandate WHERE dd_ref = %1";
    $dao = SQLQueryRunner::executeQuery($sql, [
      1 => [$this->rowData['direct_debit_mandate_reference'], 'String'],
    ]);

    $dao->fetch();
    if (!empty($dao->id)) {
      return $dao->id;
    }

    return NULL;
  }

  private function updateMandate($mandateId) {
    $sqlParams = $this->prepareSqlParams();
    $sqlParams[10] = [$mandateId, 'Integer'];
    $sqlQuery = "UPDATE `civicrm_value_dd_mandate` SET  
                `entity_id` = %1, `bank_name` = %2, `account_holder_name` = %3, `ac_number` = %4, `sort_code` = %5, 
                `dd_code` = %6, `dd_ref` = %7, `start_date` = %8, `originator_number` = %9  
                WHERE id = %10";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function createMandate() {
    $sqlParams = $this->prepareSqlParams();
    $sql = "INSERT INTO civicrm_value_dd_mandate 
            (entity_id, bank_name, account_holder_name, ac_number, sort_code, dd_code, dd_ref, start_date, originator_number) 
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9)";
    SQLQueryRunner::executeQuery($sql, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as mandate_id');
    $dao->fetch();
    return $dao->mandate_id;
  }

  private function isRecurContributionAttachedToAnyMandate() {
    $sql = "SELECT mandate_id FROM dd_contribution_recurr_mandate_ref  
            WHERE recurr_id = {$this->recurContributionId}";
    $dao = SQLQueryRunner::executeQuery($sql);

    $dao->fetch();
    if (!empty($dao->mandate_id)) {
      return TRUE;
    }

    return FALSE;
  }

  private function isDirectDebitContribution() {
    if ($this->rowData['contribution_payment_method'] == 'direct_debit') {
      return TRUE;
    }

    return FALSE;
  }

  private function isMandateContributionRefExist($mandateId) {
    $sql = "SELECT mandate_id FROM civicrm_value_dd_information  
            WHERE mandate_id = %1 AND entity_id = %2";
    $dao = SQLQueryRunner::executeQuery($sql, [
      1 => [$mandateId, 'Integer'],
      2 => [$this->contributionId, 'Integer'],
    ]);

    $dao->fetch();
    if (!empty($dao->mandate_id)) {
      return TRUE;
    }

    return FALSE;
  }

  private function prepareSqlParams() {
    $bankName = $this->getBankName();
    $accountHolderName = $this->getAccountHolderName();
    $accountNumber = $this->getAccountNumber();
    $sortCode = $this->getSortCode();
    $ddCode = $this->getDDCode();
    $ddStartDate = $this->formatRowDate('direct_debit_mandate_start_date', 'Start Date', TRUE);
    $originatorNumber = $this->getOriginatorNumber();

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$bankName, 'String'],
      3 => [$accountHolderName, 'String'],
      4 => [$accountNumber, 'String'],
      5 => [$sortCode, 'String'],
      6 => [$ddCode, 'String'],
      7 => [$this->rowData['direct_debit_mandate_reference'], 'String'],
      8 => [$ddStartDate, 'Date'],
      9 => [$originatorNumber, 'String'],
    ];
  }

  private function getBankName() {
    if (empty($this->rowData['direct_debit_mandate_bank_name'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate bank name is required for Direct Debit payment plans', 200);
    }

    return $this->rowData['direct_debit_mandate_bank_name'];
  }

  private function getAccountHolderName() {
    if (empty($this->rowData['direct_debit_mandate_account_holder'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate account holder is required for Direct Debit payment plans', 300);
    }

    return $this->rowData['direct_debit_mandate_account_holder'];
  }

  private function getAccountNumber() {
    if (empty($this->rowData['direct_debit_mandate_account_number'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate account number is required for Direct Debit payment plans', 400);
    }

    $accountNumber = $this->rowData['direct_debit_mandate_account_number'];

    if (strlen($accountNumber) !== 8) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate account number should have 8 characters', 500);
    }

    return $accountNumber;
  }

  private function getSortCode() {
    if (empty($this->rowData['direct_debit_mandate_sort_code'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate sort code is required for Direct Debit payment plans', 600);
    }

    $sortCode = $this->rowData['direct_debit_mandate_sort_code'];

    if (strlen($sortCode) !== 6) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate sort code should have 6 characters', 700);
    }

    return $this->rowData['direct_debit_mandate_sort_code'];
  }

  private function getDDCode() {
    if (empty($this->rowData['direct_debit_mandate_code'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate code is required for Direct Debit payment plans', 800);
    }

    $codesMapping = [
      '0N' => 1,
      '01' => 2,
      '17' => 3,
      '0C' => 4,
    ];

    $ddCode = $this->rowData['direct_debit_mandate_code'];
    if (!array_key_exists($ddCode, $codesMapping)) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate code should be one of the following (0N, 01, 17, 0C)', 900);
    }

    return $codesMapping[$ddCode];
  }

  private function getOriginatorNumber() {
    if (empty($this->rowData['direct_debit_mandate_originator_number'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Direct Debit Mandate originator number is required for Direct Debit payment plans', 1000);
    }

    if (!isset($this->cachedValues['originator_numbers'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'direct_debit_originator_number'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['originator_numbers'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['originator_numbers'][$this->rowData['direct_debit_mandate_originator_number']])) {
      return $this->cachedValues['originator_numbers'][$this->rowData['direct_debit_mandate_originator_number']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException('Invalid direct debit mandate originator number', 1100);
  }

  private function formatRowDate($dateColumnName, $columnLabel, $isRequired = FALSE) {
    if ($isRequired && empty($this->rowData[$dateColumnName])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidDirectDebitMandateException("Direct Debit Mandate '{$columnLabel}' is required field.", 1200);
    }

    if (!empty($this->rowData[$dateColumnName])) {
      $date = DateTime::createFromFormat('YmdHis', $this->rowData[$dateColumnName]);
      $date = $date->format('Ymd');
    }
    else {
      $date = new DateTime();
      $date = $date->format('Ymd');
    }

    return $date;
  }

}
