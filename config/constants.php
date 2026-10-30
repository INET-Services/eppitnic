<?php

// The checkout's root, so that nothing else has to count '../..' hops from
// wherever it happens to sit. Every entry point reaches this file through
// composer's `files` autoload, and this file is always one directory down.
if ( ! defined('EPPITNIC_ROOT')) define('EPPITNIC_ROOT', dirname(__DIR__));

// current DB schema version this codebase expects -- compared against the
// `settings` table's 'schema_version' row by Config's auto-migration step
// (src/Config.php). Zero-padded MMmmrr (2-digit major/minor/release, e.g. 7.0.0
// -> '070000', 7.1.2 -> '070102') rather than a dotted string -- fixed-width so
// plain string/int comparison sorts correctly (a dotted "7.10" would otherwise
// sort before "7.2"). Bump this and drop a matching
// config/mariadb-schema-upgrade-{old}-to-{new}.sql file when adding a
// migration; each file bridges exactly one version to the next one in the chain
// -- Config applies them iteratively, it never jumps versions in one file.
if ( ! defined('SCHEMA_VERSION')) define('SCHEMA_VERSION', '070100');

// release version of the application, bumped per release; independent of
// SCHEMA_VERSION
if ( ! defined('APP_VERSION')) define('APP_VERSION', '7.1.0');

// generic exit codes (1-9), returned by bin/eppitnic subcommands
if ( ! defined('SYNTAX_ERROR'))      define('SYNTAX_ERROR', 1);       // wrong/missing CLI arguments
if ( ! defined('FILE_NOT_READABLE')) define('FILE_NOT_READABLE', 2);  // input file/CSV unreadable
if ( ! defined('INVALID_INPUT'))     define('INVALID_INPUT', 3);      // eg. no valid .it domain given
if ( ! defined('CONFIG_ERROR'))      define('CONFIG_ERROR', 4);       // config/config.php missing/incomplete, or DB unreachable
if ( ! defined('OUTPUT_ERROR'))      define('OUTPUT_ERROR', 5);       // unable to write an output file
if ( ! defined('DATA_INCONSISTENT')) define('DATA_INCONSISTENT', 6);  // a check script ran fine but found bad data

// session exit codes (10-19)
if ( ! defined('HELLO_FAILED'))           define('HELLO_FAILED', 10);
if ( ! defined('LOGIN_FAILED'))           define('LOGIN_FAILED', 11);
if ( ! defined('POLL_FAILED'))            define('POLL_FAILED', 13);
if ( ! defined('CHANGE_PASSWORD_FAILED')) define('CHANGE_PASSWORD_FAILED', 14);

// domain exit codes (20-29)
if ( ! defined('DOMAIN_CREATE_FAILED'))   define('DOMAIN_CREATE_FAILED', 20);
if ( ! defined('DOMAIN_FETCH_FAILED'))    define('DOMAIN_FETCH_FAILED', 21);
if ( ! defined('DOMAIN_UPDATE_FAILED'))   define('DOMAIN_UPDATE_FAILED', 22);
if ( ! defined('DOMAIN_DELETE_FAILED'))   define('DOMAIN_DELETE_FAILED', 23);
if ( ! defined('DOMAIN_CHECK_FAILED'))    define('DOMAIN_CHECK_FAILED', 25);
if ( ! defined('DOMAIN_RESTORE_FAILED'))  define('DOMAIN_RESTORE_FAILED', 26);
if ( ! defined('DOMAIN_TRANSFER_FAILED')) define('DOMAIN_TRANSFER_FAILED', 27);
if ( ! defined('DOMAIN_IMPORT_FAILED'))   define('DOMAIN_IMPORT_FAILED', 29);

// contact exit codes (30-39)
if ( ! defined('CONTACT_CREATE_FAILED')) define('CONTACT_CREATE_FAILED', 30);
if ( ! defined('CONTACT_FETCH_FAILED'))  define('CONTACT_FETCH_FAILED', 31);
if ( ! defined('CONTACT_UPDATE_FAILED')) define('CONTACT_UPDATE_FAILED', 32);
if ( ! defined('CONTACT_DELETE_FAILED')) define('CONTACT_DELETE_FAILED', 33);
if ( ! defined('CONTACT_CHECK_FAILED'))  define('CONTACT_CHECK_FAILED', 35);

// dns exit codes (40-49)
if ( ! defined('DNS_SYNC_FAILED'))       define('DNS_SYNC_FAILED', 40);

// self-test exit codes (50-59). A refusal and a failure are separate answers:
// the first means the run never started, the second that it started and found
// something wrong -- a script around it has to tell those apart.
if ( ! defined('SELFTEST_REFUSED'))      define('SELFTEST_REFUSED', 50);
if ( ! defined('SELFTEST_FAILED'))       define('SELFTEST_FAILED', 51);

// setup exit codes (60-69)
if ( ! defined('SETUP_ALREADY_DONE')) define('SETUP_ALREADY_DONE', 60); // config/config.php already exists
if ( ! defined('SETUP_FAILED'))       define('SETUP_FAILED', 61);       // credential verification or installation failed

// scheduler exit codes (70-79)
if ( ! defined('CRON_RUN_FAILED')) define('CRON_RUN_FAILED', 70); // at least one due job's own run() returned non-zero
