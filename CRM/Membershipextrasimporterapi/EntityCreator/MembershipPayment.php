<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

class CRM_Membershipextrasimporterapi_EntityCreator_MembershipPayment {

  private $membershipId;

  private $contributionId;

  public function __construct($membershipId, $contributionId) {
    $this->membershipId = $membershipId;
    $this->contributionId = $contributionId;
  }

  /**
   * Creates the membership payment record.
   *
   * @return bool
   *   True if successfully created or False if not
   */
  public function create() {
    if ($this->isRecordAlreadyCreated()) {
      return FALSE;
    }

    $sqlQuery = "INSERT INTO civicrm_membership_payment (membership_id, contribution_id) VALUES ({$this->membershipId}, {$this->contributionId})";
    SQLQueryRunner::executeQuery($sqlQuery);

    return TRUE;
  }

  private function isRecordAlreadyCreated() {
    $sqlQuery = "SELECT id FROM civicrm_membership_payment WHERE membership_id = {$this->membershipId} AND contribution_id = {$this->contributionId}";
    $result = SQLQueryRunner::executeQuery($sqlQuery);
    if ($result->fetch()) {
      return TRUE;
    }

    return FALSE;
  }

}
