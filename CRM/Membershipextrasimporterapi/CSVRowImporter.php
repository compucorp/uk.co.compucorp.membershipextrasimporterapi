<?php

class CRM_Membershipextrasimporterapi_CSVRowImporter {

  private $rowData;

  private $contactId;

  public function __construct($rowData) {
    $this->rowData = $rowData;
    $this->contactId = $this->getContactId();
  }

  public function import() {
    $recurContributionImporter = new CRM_Membershipextrasimporterapi_EntityImporter_RecurContribution($this->rowData, $this->contactId);
    $recurContributionId = $recurContributionImporter->import();

    $membershipImporter = new CRM_Membershipextrasimporterapi_EntityImporter_Membership($this->rowData, $this->contactId, $recurContributionId);
    $membershipId = $membershipImporter->import();

    $contributionImporter = new CRM_Membershipextrasimporterapi_EntityImporter_Contribution($this->rowData, $this->contactId, $recurContributionId);
    $contributionId = $contributionImporter->import();
  }

  private function getContactId() {
    $contactId = NULL;

    if (!empty($this->rowData['contact_id'])) {
      $sqlQuery = "SELECT id FROM civicrm_contact WHERE id = %1";
      $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['contact_id'], 'Integer']]);
      if (!$result->fetch()) {
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException("Cannot find contact with Id = $this->rowData['contact_id']", 100);
      }

      return $result->id;
    }

    if (!empty($this->rowData['contact_external_id'])) {
      $sqlQuery = "SELECT id FROM civicrm_contact WHERE external_identifier = %1";
      $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['contact_external_id'], 'String']]);
      if (!$result->fetch()) {
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException("Cannot find contact with External Id = $this->rowData['contact_external_id']", 200);
      }

      return $result->id;
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidContactException('Either Contact Id or Contact External Id is required.', 300);
  }

}
