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
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function membershipextrasimporterapi_civicrm_xmlMenu(&$files) {
  _membershipextrasimporterapi_civix_civicrm_xmlMenu($files);
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
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function membershipextrasimporterapi_civicrm_postInstall() {
  _membershipextrasimporterapi_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function membershipextrasimporterapi_civicrm_uninstall() {
  _membershipextrasimporterapi_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function membershipextrasimporterapi_civicrm_enable() {
  _membershipextrasimporterapi_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function membershipextrasimporterapi_civicrm_disable() {
  _membershipextrasimporterapi_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function membershipextrasimporterapi_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _membershipextrasimporterapi_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function membershipextrasimporterapi_civicrm_managed(&$entities) {
  _membershipextrasimporterapi_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function membershipextrasimporterapi_civicrm_caseTypes(&$caseTypes) {
  _membershipextrasimporterapi_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function membershipextrasimporterapi_civicrm_angularModules(&$angularModules) {
  _membershipextrasimporterapi_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function membershipextrasimporterapi_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _membershipextrasimporterapi_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function membershipextrasimporterapi_civicrm_entityTypes(&$entityTypes) {
  _membershipextrasimporterapi_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function membershipextrasimporterapi_civicrm_themes(&$themes) {
  _membershipextrasimporterapi_civix_civicrm_themes($themes);
}
