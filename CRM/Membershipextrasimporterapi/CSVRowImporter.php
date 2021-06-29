<?php

use CRM_Membershipextrasimporterapi_EntityImporter_RecurContribution as RecurContributionImporter;
use CRM_Membershipextrasimporterapi_EntityImporter_Membership as MembershipImporter;
use CRM_Membershipextrasimporterapi_EntityImporter_Contribution as ContributionImporter;
use CRM_Membershipextrasimporterapi_EntityCreator_MembershipPayment as MembershipPaymentCreator;
use CRM_Membershipextrasimporterapi_EntityImporter_LineItem as LineItemImporter;
use CRM_Membershipextrasimporterapi_EntityImporter_DirectDebitMandate as DirectDebitMandateImporter;
use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

class CRM_Membershipextrasimporterapi_CSVRowImporter {

  private $rowData;

  private $contactId;

  public function __construct($rowData) {
    $this->rowData = $rowData;
    $this->contactId = $this->getContactId();
  }

  public function import() {
    $transaction = new CRM_Core_Transaction();
    try {
      $recurContributionImporter = new RecurContributionImporter($this->rowData, $this->contactId);
      $recurContributionId = $recurContributionImporter->import();

      $membershipImporter = new MembershipImporter($this->rowData, $this->contactId, $recurContributionId);
      $membershipId = $membershipImporter->import();

      $contributionImporter = new ContributionImporter($this->rowData, $this->contactId, $recurContributionId);
      $contributionId = $contributionImporter->import();

      if ($membershipId != NULL) {
        $membershipPaymentCreator = new MembershipPaymentCreator($membershipId, $contributionId);
        $membershipPaymentCreator->create();
      }

      $lineItemImporter = new LineItemImporter($this->rowData, $contributionId, $membershipId, $recurContributionId);
      $lineItemImporter->import();

      $mandateImporter = new DirectDebitMandateImporter($this->rowData, $this->contactId, $recurContributionId, $contributionId);
      $mandateImporter->import();

      $transaction->commit();
    }
    catch (Exception $e) {
      $transaction->rollback();
      // we leave for the CSV importer extension to handle any thrown exception.
      throw $e;
    }
  }

  private function getContactId() {
    $contactId = NULL;

    if (!empty($this->rowData['contact_id'])) {
      $sqlQuery = "SELECT id FROM civicrm_contact WHERE id = %1";
      $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contact_id'], 'Integer']]);
      if (!$result->fetch()) {
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException("Cannot find contact with Id = $this->rowData['contact_id']", 100);
      }

      return $result->id;
    }

    if (!empty($this->rowData['contact_external_id'])) {
      $sqlQuery = "SELECT id FROM civicrm_contact WHERE external_identifier = %1";
      $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contact_external_id'], 'String']]);
      if (!$result->fetch()) {
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException("Cannot find contact with External Id = $this->rowData['contact_external_id']", 200);
      }

      return $result->id;
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException('Either Contact Id or Contact External Id is required.', 300);
  }

}
