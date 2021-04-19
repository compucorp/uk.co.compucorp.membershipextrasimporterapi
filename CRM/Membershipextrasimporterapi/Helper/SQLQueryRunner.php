<?php

class CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner {

  /**
   * Executes the given SQL query with the
   * given data parameters.
   *
   * This is just a wrapper for the core
   * CRM_Core_DAO::executeQuery() method but with
   * more detailed error message, since the
   * normal error message thrown by the Core
   * especially when validating the data parameters are
   * not usually helpful.
   *
   * @param string $sqlQuery
   * @param array $sqlParams
   *
   * @return CRM_Core_DAO
   *
   * @throws CRM_Core_Exception
   */
  public static function executeQuery($sqlQuery, $sqlParams = []) {
    try {
      return CRM_Core_DAO::executeQuery($sqlQuery, $sqlParams);
    }
    catch (Exception $exception) {
      $errorMessage = $exception->getMessage();
      $errorMessage .= " | Failed executing the following SQL Query: '$sqlQuery'.";
      $errorMessage .= ' - With the following data: ' . print_r($sqlParams, TRUE);
      $errorMessage = str_replace("\n", ' ', $errorMessage);
      throw new CRM_Core_Exception($errorMessage);
    }
  }

}
