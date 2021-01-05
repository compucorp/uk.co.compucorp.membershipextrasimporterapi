<?php

class CRM_Membershipextrasimporterapi_EntityImporter_Membership {

  private $rowData;

  private $recurContributionId;

  private $contactId;

  private $cachedValues;

  public function __construct($rowData, $contactId, $recurContributionId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
    $this->recurContributionId = $recurContributionId;
  }

  public function import() {
    if (empty($this->rowData['membership_external_id'])) {
      // todo : when the line item importer is created,
      // we need to throw an exception if this field  is not set and line_item.entity_table = "civicrm_membership"
      return NULL;
    }

    $membershipId = $this->getMembershipIdIfExist();
    if ($membershipId) {
      return $membershipId;
    }

    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_membership` (`contact_id` , `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`,
                 `is_pay_later`, `contribution_recur_id`, `is_override`, `status_override_end_date`) 
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10)";
    CRM_Core_DAO::executeQuery($sqlQuery, $sqlParams);

    $dao = CRM_Core_DAO::executeQuery('SELECT LAST_INSERT_ID() as membership_id');
    $dao->fetch();
    $membershipId = $dao->membership_id;

    $sqlQuery = "INSERT INTO `civicrm_value_membership_ext_id` (`entity_id` , `external_id`) 
           VALUES ({$membershipId}, %1)";
    CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['membership_external_id'], 'String']]);

    return $membershipId;
  }

  private function getMembershipIdIfExist() {
    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_membership_ext_id WHERE external_id = %1";
    $membershipId = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['membership_external_id'], 'String']]);
    $membershipId->fetch();

    if (!empty($membershipId->id)) {
      return $membershipId->id;
    }

    return NULL;
  }

  private function prepareSqlParams() {
    $membershipTypeId = $this->getMembershipTypeId();
    $membershipStatusId = $this->getMembershipStatusId($membershipTypeId);
    $statusOverrideEndDate = $this->getStatusOverrideEndDate();
    $isOverriddenStatus = $this->isOverriddenStatus($statusOverrideEndDate);
    $joinDate = $this->formatRowDate('membership_join_date', 'Join Date', TRUE);
    $startDate = $this->formatRowDate('membership_start_date', 'Start Date', TRUE);
    $endDate = $this->formatRowDate('membership_end_date', 'End Date', TRUE);

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$membershipTypeId, 'Integer'],
      3 => [$joinDate, 'Date'],
      4 => [$startDate, 'Date'],
      5 => [$endDate, 'Date'],
      6 => [$membershipStatusId, 'Integer'],
      7 => [1, 'Integer'],
      8 => [$this->recurContributionId, 'Integer'],
      9 => [$isOverriddenStatus, 'Integer'],
      10 => [$statusOverrideEndDate, 'Date'],
    ];
  }

  private function getMembershipTypeId() {
    if (empty($this->rowData['membership_type'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException('Membership type is required field', 100);
    }

    if (!isset($this->cachedValues['membership_types'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_membership_type";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['membership_types'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['membership_types'][$this->rowData['membership_type']])) {
      return $this->cachedValues['membership_types'][$this->rowData['membership_type']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException('Invalid membership type', 200);
  }

  private function getMembershipStatusId($membershipTypeId) {
    if (empty($this->rowData['membership_status'])) {
      $statusId = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate(
        $this->rowData['membership_start_date'],
        $this->rowData['membership_end_date'],
        $this->rowData['membership_join_date'],
        'today',
        TRUE,
        $membershipTypeId
      );

      return $statusId['id'];
    }

    if (!isset($this->cachedValues['membership_statuses'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_membership_status";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['membership_statuses'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['membership_statuses'][$this->rowData['membership_status']])) {
      return $this->cachedValues['membership_statuses'][$this->rowData['membership_status']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException('Invalid membership status', 300);
  }

  private function getStatusOverrideEndDate() {
    if (!empty($this->rowData['membership_status_override_end_date'])) {
      return $this->formatRowDate('membership_status_override_end_date', 'Membership status override end date');
    }

    return NULL;
  }

  private function isOverriddenStatus($statusOverrideEndDate) {
    if (!empty($statusOverrideEndDate)) {
      return 2;
    }

    if (empty($this->rowData['membership_is_status_overridden'])) {
      return 0;
    }

    return 1;
  }

  private function formatRowDate($dateColumnName, $columnLabel, $isRequired = FALSE) {
    if ($isRequired && empty($this->rowData[$dateColumnName])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidMembershipFieldException("Membership '{$columnLabel}' is required field.", 400);
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
