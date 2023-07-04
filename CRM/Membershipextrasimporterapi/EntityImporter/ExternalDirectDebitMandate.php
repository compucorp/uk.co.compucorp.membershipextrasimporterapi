<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

class CRM_Membershipextrasimporterapi_EntityImporter_ExternalDirectDebitMandate {

  private $rowData;

  private $recurContributionId;

  public function __construct($rowData, $recurContributionId) {
    $this->rowData = $rowData;
    $this->recurContributionId = $recurContributionId;
  }

  public function import() {
    if (!$this->isExternalMandateInformationAvailable()) {
      return NULL;
    }

    $mandateRecordId = $this->getRecurContributionCurrentMandateRecordIdIfExist();
    if ($mandateRecordId) {
      $this->updateMandate($mandateRecordId);
    }
    else {
      $mandateRecordId = $this->createMandate();
    }

    return $mandateRecordId;
  }

  private function isExternalMandateInformationAvailable() {
    $externalMandateFields = ['external_direct_debit_mandate_id', 'external_direct_debit_next_available_payment_date'];
    foreach ($externalMandateFields as $externalMandateFieldName) {
      if (empty($this->rowData[$externalMandateFieldName])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  private function getRecurContributionCurrentMandateRecordIdIfExist() {
    $sql = "SELECT id FROM civicrm_value_external_dd_mandate_information WHERE entity_id = %1";
    $dao = SQLQueryRunner::executeQuery($sql, [
      1 => [$this->recurContributionId, 'Int'],
    ]);

    $dao->fetch();
    if (!empty($dao->id)) {
      return $dao->id;
    }

    return NULL;
  }

  private function updateMandate($mandateRecordId) {
    $sqlParams = $this->prepareSqlParams();
    $sqlParams[5] = [$mandateRecordId, 'Integer'];
    $sqlQuery = "UPDATE `civicrm_value_external_dd_mandate_information` SET
                 entity_id = %1, `mandate_id` = %2, `mandate_status` = %3, `next_available_payment_date` = %4
                 WHERE id = %5";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function createMandate() {
    $sqlParams = $this->prepareSqlParams();
    $sql = "INSERT INTO civicrm_value_external_dd_mandate_information
            (entity_id, mandate_id, mandate_status, next_available_payment_date)
            VALUES (%1, %2, %3, %4)";
    SQLQueryRunner::executeQuery($sql, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as mandate_id');
    $dao->fetch();
    return $dao->mandate_id;
  }

  private function prepareSqlParams() {
    $nextPaymentDate = DateTime::createFromFormat('YmdHis', $this->rowData['external_direct_debit_next_available_payment_date']);
    $mandateStatus = $this->rowData['external_direct_debit_mandate_status'] ?? 0;

    return [
      1 => [$this->recurContributionId, 'Integer'],
      2 => [$this->rowData['external_direct_debit_mandate_id'], 'String'],
      3 => [$mandateStatus, 'String'],
      4 => [$nextPaymentDate->format('Ymd'), 'Date'],
    ];
  }

}
