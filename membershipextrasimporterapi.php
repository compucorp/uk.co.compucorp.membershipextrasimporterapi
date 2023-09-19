<?php

require_once 'membershipextrasimporterapi.civix.php';
// phpcs:disable
use CRM_Membershipextrasimporterapi_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function membershipextrasimporterapi_civicrm_config(&$config) {
  _membershipextrasimporterapi_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function membershipextrasimporterapi_civicrm_install() {
  _membershipextrasimporterapi_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function membershipextrasimporterapi_civicrm_enable() {
  _membershipextrasimporterapi_civix_civicrm_enable();
}
