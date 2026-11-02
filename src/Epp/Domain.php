<?php

namespace Eppitnic\Epp;

use Eppitnic\Persistence\History;

use Eppitnic\Persistence\ChangeTracking;
use Eppitnic\Persistence\LocalStorage;
use Eppitnic\Persistence\Scope;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Support\Idn;
use RedBeanPHP\R;

/**
 * This class handles domain objects.
 *
 * LICENSE:
 *
 * Copyright (c) Günther Mair <info@inet-services.it>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Eppitnic\Epp\Domain
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Domain extends AbstractObject
{
  use ChangeTracking;
  use LocalStorage;

  protected static function storageTable(): string { return 'domains'; }
  protected static function storageKeyColumn(): string { return 'domain'; }
  protected static function storageNoun(): string { return 'domain'; }

  /**
   * The domain's own fields, and whether each is stored serialized. One list
   * where the set was spelled out in the properties, set(), updateDB() and
   * storeDB() alike.
   */
  public const FIELDS = array(
    'ns'         => true,
    'registrant' => false,
    'admin'      => false,
    'tech'       => true,
    'authinfo'   => false,
    'dnssec'     => true,
  );

  /**
   * Fields set() must not mark dirty itself: a collection is changed through
   * addNS()/addTECH()/addDNSSEC(), which know whether anything moved.
   */
  private const FIELDS_WITH_ADDERS = array('ns', 'tech', 'dnssec');

  /** the states a registrar may set, for updateStatus(); clientLock is view-only */
  private const CLIENT_STATES = array(
    'clientDeleteProhibited', 'clientUpdateProhibited', 'clientTransferProhibited',
    'clientHold',
  );

  protected $reseller_id;       // use just in case of an updateRegistrant + change of agent
  protected $status;            // domain states (ok, clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock + server-side states)
  protected $domain;            // -

  protected $ns;

  // nameservers awaiting the registry's DNS validation, shaped like $ns;
  // never part of an update's diff
  protected $nsToValidate;
  protected $registrant;
  protected $admin;
  protected $tech;
  protected $authinfo;
  protected $dnssec;

  // domain lifecycle
  protected $crDate;
  protected $exDate;

  // these are for internal use only (ie. update)
  protected $ns_initial;
  protected $admin_initial;
  protected $tech_initial;
  protected $dnssec_initial;

  // domain transfer information
  protected $trStatus;
  protected $reID;
  protected $acID;

  // infContacts
  protected $infcontacts;

  // DNSSEC status (enabled or not)
  protected $dnssec_status;

  /**
   * Class constructor
   *
   * (initializes authinfo)
   *
   * @param Client $client client class
   */
  function __construct(Client $client) {
    parent::__construct($client);

    $this->initValues();
    $this->dnssec_status = @isset($this->client->EPPCfg->dnssec->active) ? (int)$this->client->EPPCfg->dnssec->active : 0;
  }

  /**
   * initialize values
   */
  protected function initValues(): void {
    $this->reseller_id       = 1;
    $this->status            = array();
    $this->domain            = "";
    $this->registrant        = "";
    $this->admin             = "";
    $this->admin_initial     = "";
    $this->tech              = array();
    $this->tech_initial      = array();
    $this->ns                = array();
    $this->ns_initial        = array();
    $this->nsToValidate      = array();
    $this->authinfo          = $this->authinfo();
    $this->dnssec            = array();
    $this->dnssec_initial    = array();
    $this->clearChanges();
    $this->crDate            = date("Y-m-d");
    $this->exDate            = date("Y-m-d", strtotime("+1 year"));
    $this->trStatus          = "";
    $this->reID              = "";
    $this->acID              = "";
    $this->infcontacts       = array();
  }

  /**
   * restrict access to variables, so we can keep track of changes to them
   *
   * @param string $var variable name
   * @param mixed $val value to set
   * @return mixed value set or FALSE if variable name does not exist
   */
  public function set(string $var, mixed $val): mixed {
    // convert to lower-case
    $var = strtolower($var);

    // 'ns' and 'tech' are collections: dispatch before the cast below, which
    // would TypeError on an array. Passing one is a caller error either way,
    // but it should return FALSE rather than fatal
    if ($var == "ns" || $var == "tech") {
      if (is_array($val)) {
        $this->setError("set('{$var}', ...) takes a single value; use add" . strtoupper($var) . "() per entry.");
        return FALSE;
      }
      $val = (string)$val;
      return ($var == "ns") ? $this->addNS($val) : $this->addTECH($val);
    }

    // stored as given -- see the note in Contact::set()
    $val = (string)$val;

    if (isset($this->$var)) {
      if ($this->$var == $val) {
        return FALSE; // value didn't change!
      } else {
        $this->$var = $val;
      }
    } else {
      return FALSE; // value doesn't exist or cannot be set using set($var, $val)!
    }

    if (isset(self::FIELDS[$var]) && ! in_array($var, self::FIELDS_WITH_ADDERS, true)) {
      $this->markChanged($var);
    }
    return $this->$var;
  }

  /**
   * Take the current values as the new baseline, wherever this object has just
   * agreed with the registry or the database. One method because it was four,
   * and update()'s had drifted -- missing dnssec_initial, so it re-sent DNSSEC.
   */
  private function resetChangeTracking(): void {
    $this->clearChanges();
    $this->ns_initial     = $this->ns;
    $this->admin_initial  = $this->admin;
    $this->tech_initial   = $this->tech;
    $this->dnssec_initial = $this->dnssec;
  }

  /**
   * One <extdom:infContactsData> entry -- registrant or admin/tech contact --
   * flattened into the shape infcontacts holds. As two copies, a field added
   * to one was simply absent from the other half of the answer.
   *
   * @param \SimpleXMLElement $entry the <registrant> or <contact> element
   * @param string $type 'registrant', or the <contact type="..."> attribute
   * @param array<string, string> $ns the response's namespace map
   * @return array<string, string>
   */
  private static function linkedContact(\SimpleXMLElement $entry, string $type, array $ns): array {
    $infContact = $entry->infContact->children($ns['contact']);
    $extInfo    = $entry->extInfo->children($ns['extcon']);

    return array(
      'type'                 => $type,
      'id'                   => (string)$infContact->id,
      'name'                 => (string)$infContact->postalInfo->name,
      'org'                  => (string)$infContact->postalInfo->org,
      'street'               => (string)$infContact->postalInfo->addr->street[0],
      'street2'              => (string)$infContact->postalInfo->addr->street[1],
      'street3'              => (string)$infContact->postalInfo->addr->street[2],
      'city'                 => (string)$infContact->postalInfo->addr->city,
      'province'             => (string)$infContact->postalInfo->addr->sp,
      'postalcode'           => (string)$infContact->postalInfo->addr->pc,
      'countrycode'          => (string)$infContact->postalInfo->addr->cc,
      'voice'                => (string)$infContact->voice,
      'fax'                  => (string)$infContact->fax,
      'email'                => (string)$infContact->email,
      'consentforpublishing' => (string)$extInfo->consentForPublishing,
      'nationalitycode'      => (string)$extInfo->registrant->nationalityCode,
      'entitytype'           => (string)$extInfo->registrant->entityType,
      'regcode'              => (string)$extInfo->registrant->regCode,
    );
  }

  /**
   * remove a DNSSEC data set
   *
   * @param string $digest the digest value identifying which DNSSEC record to
   *               remove
   * @return string|false the digest on success, false on failure
   */
  public function remDNSSEC(string $digest): string|false {
    if (isset($this->dnssec_initial[$digest])) {
      $this->markChanged('dnssec');
      unset($this->dnssec[$digest]);
      return $digest;
    } else {
      $this->setError("The digest you provided is not currently associated to this domain object.");
      return FALSE;
    }
  }

  /**
   * add a DNSSEC data set
   *
   * @param string $keytag keytag
   * @param string $algorithm algorithm
   * @param string $digesttype digesttype
   * @param string $digest digest
   * @return string|false the digest on success, false on failure
   */
  public function addDNSSEC(string $keytag, string $algorithm, string $digesttype, string $digest): string|false {
    // don't allow empty values
    if (empty($keytag) || empty($algorithm) || empty($digesttype) || empty($digest)) {
      $this->setError("All values (keytag, algorithm, digesttype, digest) must be given and must NOT be empty.");
      return FALSE;
    }

    // what to do if the keytag exists...
    if (isset($this->dnssec[$digest])) {
      // ... and all values are the same? Then stop here!
      if ($this->dnssec[$digest]['algorithm']  == $algorithm &&
          $this->dnssec[$digest]['digesttype'] == $digesttype &&
          $this->dnssec[$digest]['keytag']     == $keytag) {
        $this->setError("The provided DNSSEC information already exists.");
        return FALSE;
      }
    } else if (count($this->dnssec) >= 2) {
      $this->setError("Only two DNSSEC sets may be provided. Please remove one of those first.");
      return FALSE;
    }

    $this->markChanged('dnssec');
    $this->dnssec[$digest] = array(
      'algorithm'  => $algorithm,
      'digesttype' => $digesttype,
      'keytag'     => $keytag,
    );

    return $digest;
  }

  /**
   * get a single variable/setting from class
   *
   * 'tech' always comes back as an array (handle => handle), even for one: as a
   * bare string, array_keys((array) ...) gave [0] and reported a tech of "0".
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    return $this->$var;
  }

  /**
   * remove a technical contact
   *
   * @param string $name tech contact name
   * @return string|false value removed or FALSE if variable name does not exist
   */
  public function remTECH(string $name): string|false {
    if (isset($this->tech[$name])) {
      unset($this->tech[$name]);
      $this->markChanged('tech');
      return $name;
    } else {
      return FALSE;
    }
  }

  /**
   * add a technical contact
   *
   * @param string $name tech contact name
   * @return string|false value set or FALSE if there was an error
   */
  public function addTECH(string $name): string|false {
    if (empty($name)) {
      return FALSE;
    }

    // assign technical contact
    if ( ! isset($this->tech[$name])) {
      $this->tech[$name] = $name;
      $this->markChanged('tech');
    }
    return $name;
  }

  /**
   * remove a nameserver
   *
   * @param string $name NS name
   * @return string|false value set or FALSE if variable name does not exist
   */
  public function remNS(string $name): string|false {
    // DNS names must be in punycode format (if below an IDN domain)
    $name = Idn::ascii($name);
    if (isset($this->ns[$name])) {
      unset($this->ns[$name]);
      $this->markChanged('ns');
      return $name;
    } else {
      return FALSE;
    }
  }

  /**
   * For a domain whose registry copy has no validated nameservers (dnsHold),
   * start from the set awaiting validation: the registry then needs the whole
   * new configuration in one <add>, so every later addNS()/remNS() works on
   * it. Not a change by itself; ns_initial stays empty, so update() adds all.
   */
  public function startFromPendingNs(): void {
    if ($this->ns !== array() || $this->nsToValidate === array()) {
      return;
    }
    $changed = $this->changed('ns');
    foreach ($this->nsToValidate as $name => $host) {
      $this->addNS($name, array_column($host['ip'] ?? array(), 'address'));
    }
    if ( ! $changed) {
      unset($this->changes['ns']);
    }
  }

  /**
   * add a nameserver
   *
   * @param string $name NS name
   * @param array|string|null $addr glue addresses: one, or an array of up to
   *                     two; null keeps a nameserver already set as it is,
   *                     an empty array sets it without glue
   * @return string|false value set or FALSE on error
   */
  public function addNS(string $name, array|string|null $addr = null): string|false {
    // don't allow empty values
    if (empty($name)) {
      return FALSE;
    }

    // DNS names must be in punycode format (if below an IDN domain)
    $name = Idn::ascii($name);

    // validated before $this->ns is touched, so a bad address leaves nothing
    // half-added
    $given = is_array($addr) ? array_values($addr) : ($addr === null || $addr === '' ? array() : array($addr));
    if (count($given) > 2) {
      $this->setError("The address must be an array of one or two elements.");
      return FALSE;
    }
    $ips = array();
    foreach ($given as $ip) {
      $ip = strtolower(trim((string)$ip));
      if ($ip === '') {
        continue;
      }
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
        $type = 'v4';
      } else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
        $type = 'v6';
      } else {
        $this->setError("Address '".$ip."' is not a valid IPv4 or IPv6 address.");
        return FALSE;
      }
      $ips[] = array('type' => $type, 'address' => $ip);
    }

    // a nameserver by this name already set is left alone unless addresses
    // were given and they differ; then it is replaced. 'ip' is absent for a
    // glueless one -- the ordinary case
    if (isset($this->ns[$name])) {
      if ($addr === null) {
        return $name;
      }
      $held = array_column($this->ns[$name]['ip'] ?? array(), 'address');
      $wanted = array_column($ips, 'address');
      sort($held);
      sort($wanted);
      if ($held === $wanted) {
        return $name;
      }
      $this->remNS($name);
    }

    $this->ns[$name]['name'] = $name;
    foreach ($ips as $ip) {
      $this->ns[$name]['ip'][] = $ip;
    }

    // if we get to this point, something has changed
    $this->markChanged('ns');
    return $name;
  }

  /**
   * check whether domains are available for registration
   *
   * @param array|string|null $domain one name, several, or null for the one set
   * @return CheckResult the registry's answer, or a failure -- see CheckResult
   */
  public function check(array|string|null $domain = null): CheckResult {
    $result = $this->checkAvailability(
      $domain,
      $this->domain,
      "Operation not allowed, set a domain name first!",
      'domain',
      'name',
      fn(string $clTRID, array $names) => XmlBuilder::domainCheck($clTRID, $names)
    );

    // The one thing Contact's check does not do, so it stays here rather than
    // in the shared method: kept for callers reading svMsg after a single-name
    // check.
    $availability = $result->all();
    if (count($availability) === 1) {
      $only = array_values($availability)[0];
      if ( ! $only['available']) {
        $this->svMsg = $only['reason'];
      }
    }

    return $result;
  }

  /**
   * DS records are only sent while the dnssec setting is on.
   *
   * @return bool true when on; false with the error set when off
   */
  private function dnssecEnabled(): bool {
    if ($this->dnssec_status == 1) {
      return TRUE;
    }
    $this->setError("DNSSEC is switched off: enable it with 'eppitnic config dnssec on' or PATCH /v1/dnssec.");
    return FALSE;
  }

  /**
   * create domain
   *
   * @return bool status
   */
  public function create(): bool {
    if ($this->dnssec !== array() && ! $this->dnssecEnabled()) {
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainCreate(
      $this->client->set_clTRID(),
      $this->domain,
      $this->ns,
      $this->registrant,
      $this->authinfo,
      $this->dnssec
    );

    // query server and return answer (no handling of special return values)
    if ($this->ExecuteQuery("domain-create", $this->domain)) {
      $this->resetChangeTracking();
      $this->status = array('ok');
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch domain through EPP
   *
   * @param string $domain domain to load
   * @param string $authinfo authinfo string (domain sponsored by other
   *               registrar)
   * @param string $infContacts restrict linked-contact info to this type
   *               ('all', 'registrant', or blank for none)
   * @return bool status
   */
  public function fetch(?string $domain = null, ?string $authinfo = null, string $infContacts = ''): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }

    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    $infContacts = strtolower($infContacts);
    if ( ! in_array($infContacts, array("all", "registrant"))) {
      $infContacts = '';
    }

    // if authinfo was not given as an argument, but has been set
    if (($authinfo === null) && $this->changed('authinfo')) {
      $authinfo = $this->authinfo;
    }

    $this->xmlQuery = XmlBuilder::domainInfo(
      $this->client->set_clTRID(),
      $domain,
      empty($authinfo) ? '' : $authinfo,
      $infContacts
    );

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("domain-info", $domain)) {
      $tmp = $this->responseData('domain');
      if ($tmp === null || ! isset($tmp->infData)) {
        $this->setError("The registry accepted the query but returned no domain data.");
        return FALSE;
      }
      $ns = $this->xmlResult->getNamespaces(TRUE);

      $this->domain = $domain;
      $this->status = array();

      $this->registrant = (string)$tmp->infData->registrant;
      $this->authinfo = (string)$tmp->infData->authInfo->pw;
      $this->crDate = (string)$tmp->infData->crDate;
      $this->exDate = (string)$tmp->infData->exDate;
      foreach ($tmp->infData->status as $singleState) {
        $this->status[] = (string)$singleState->attributes()->s;
      }
      foreach ($tmp->infData->contact as $contact) {
        $type = $contact->attributes()->type;
        if ($type == "tech") {
          $this->addTECH((string)$contact);
        } else if ($type == "registrant" or $type == "admin") {
          $this->$type = (string)$contact;
        } else {
          // undefined
        }
      }

      // if the NS were not properly configured EPP will not report them yet!
      if (@is_object($tmp->infData->ns->hostAttr[0])) {
        foreach ($tmp->infData->ns->hostAttr as $hostAttr) {
          $addr = array();
          foreach ($hostAttr->hostAddr as $ip) {
            $addr[] = strtolower((string)$ip);
          }
          $this->addNS((string)$hostAttr->hostName, $addr);
        }
      }

      // if extsecDNS and secDNS are set
      $secDNS = $this->responseExtension('secDNS');
      if ($secDNS !== null) {
        foreach ($secDNS->infData->dsData as $dsData) {
          $this->addDNSSEC((int)$dsData->keyTag, (int)$dsData->alg, (int)$dsData->digestType, (string)$dsData->digest);
        }
      }

      // if infContactsData is set
      $tmp = $this->responseExtension('extdom');
      if ($tmp !== null) {

        // inactive/dnsHold domains report their nameservers only here
        foreach ($tmp->infNsToValidateData->nsToValidate ?? array() as $set) {
          $hosts = $set->children($ns['domain'] ?? 'urn:ietf:params:xml:ns:domain-1.0');
          foreach ($hosts->hostAttr as $hostAttr) {
            $name = Idn::ascii((string)$hostAttr->hostName);
            $this->nsToValidate[$name]['name'] = $name;
            foreach ($hostAttr->hostAddr as $ip) {
              $address = strtolower(trim((string)$ip));
              $this->nsToValidate[$name]['ip'][] = array(
                'type' => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE ? 'v6' : 'v4',
                'address' => $address,
              );
            }
          }
        }

        // verify extended states
        if (@is_object($tmp->infData->ownStatus)) {
          foreach ($tmp->infData->ownStatus as $singleState) {
            $this->status[] = (string)$singleState->attributes()->s;
          }
        }

        // fetch contact information
        $this->infcontacts = array();
        if (@is_object($tmp->infContactsData->registrant)) {
          $this->infcontacts[] = self::linkedContact($tmp->infContactsData->registrant, 'registrant', $ns);
        }
        if (@is_object($tmp->infContactsData->contact[0])) {
          foreach ($tmp->infContactsData->contact as $contact) {
            $this->infcontacts[] = self::linkedContact($contact, (string)$contact->attributes()->type, $ns);
          }
        }
      }

      // infData and ownStatus both carry states, often the same (ok)
      $this->status = array_values(array_unique($this->status));

      // reset changes at the bottom
      $this->resetChangeTracking();
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * print domain status - the states will be set after a call to fetch()
   *
   * @return array|false server side state (array of status strings, or FALSE)
   */
  public function state(): array|false {
    return ($this->status === null) ? FALSE : $this->status;
  }

  /**
   * delete domain
   *
   * @param string $domain domain name to delete
   * @return bool status
   */
  public function delete(?string $domain = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainDelete($this->client->set_clTRID(), $domain);

    // query server
    return $this->ExecuteQuery("domain-delete", $domain);
  }

  /**
   * update domain
   *
   * @return bool status
   */
  public function update(): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Domain did not change!");
      return FALSE;
    }
    if ($this->changed('registrant')) {
      $this->setError("Update the registrant through updateRegistrant()!");
      return FALSE;
    }
    if ($this->changed('dnssec') && ! $this->dnssecEnabled()) {
      return FALSE;
    }

    $add = array();
    $remove = array();

    if ($this->changed('ns')) {
      // the registry accepts at most six
      $this->ns = array_slice($this->ns, 0, 6);

      // Compare on name+addresses, not name alone: re-pointing a glued
      // nameserver at a new address is a removal and an addition, and
      // comparing names would see no change at all.
      $signature = function (array $set): array {
        $out = array();
        foreach ($set as $name => $values) {
          $key = $name;
          foreach ($values['ip'] ?? array() as $addr) {
            $key .= ";" . $addr['address'];
          }
          $out[$key] = $name;
        }
        return $out;
      };

      $now = $signature($this->ns);
      $before = $signature($this->ns_initial);

      foreach (array_diff_key($now, $before) as $name) {
        $add['ns'][$name] = $this->ns[$name];
      }
      foreach (array_diff_key($before, $now) as $name) {
        $remove['ns'][$name] = $this->ns_initial[$name];
      }
    }

    $dnssecAdd = array();
    $dnssecRemove = array();
    if ($this->changed('dnssec')) {
      // the registry accepts at most two DS records
      $this->dnssec = array_slice($this->dnssec, 0, 2, true);
      $dnssecAdd = array_diff_key($this->dnssec, $this->dnssec_initial);
      $dnssecRemove = array_diff_key($this->dnssec_initial, $this->dnssec);
    }

    $authinfo = $this->changed('authinfo') ? $this->authinfo : '';

    // admin/tech changes are never sent: with nothing else left, no update
    if ($add === array() && $remove === array() && $authinfo === ''
        && $dnssecAdd === array() && $dnssecRemove === array()) {
      $this->resetChangeTracking();
      return TRUE;
    }

    $this->xmlQuery = XmlBuilder::domainUpdate(
      $this->client->set_clTRID(),
      $this->domain,
      $add,
      $remove,
      array('authinfo' => $authinfo),
      $dnssecAdd,
      $dnssecRemove
    );

    // query server
    if ($this->ExecuteQuery("domain-update", $this->domain)) {
      $this->resetChangeTracking();
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * update domain registrant
   *
   * @return bool status
   */
  public function updateRegistrant(): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }
    if ( ! $this->changed('registrant') || ! $this->changed('authinfo')) {
      $this->setError("You MUST update the registrant and authinfo variables!");
      return FALSE;
    }

    // carries only the registrant and its authinfo; nameserver changes
    // belong to update()
    $this->xmlQuery = XmlBuilder::domainUpdate(
      $this->client->set_clTRID(),
      $this->domain,
      array(),
      array(),
      array('registrant' => $this->registrant, 'authinfo' => $this->authinfo)
    );

    // query server
    if ($this->ExecuteQuery("domain-update", $this->domain)) {
      $this->resetChangeTracking();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * update domain status
   *
   * @param string $state clientDeleteProhibited, clientUpdateProhibited,
   *               clientTransferProhibited, clientHold
   * @param string $adddel add, rem (optional, defaults to add)
   * @return bool status
   */
  public function updateStatus(string $state, string $adddel = "add"): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ( ! $this->applyStatusChange(self::CLIENT_STATES, $state, $adddel)) {
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainStatus($this->client->set_clTRID(), $this->domain, $adddel, $state);

    // query server
    return $this->ExecuteQuery("domain-status", $this->domain);
  }

  /**
   * restore domain
   *
   * @param string $domain domain name to restore
   * @return bool status
   */
  public function restore(?string $domain = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainRestore($this->client->set_clTRID(), $domain);

    // query server
    return $this->ExecuteQuery("domain-restore", $domain);
  }

  /**
   * @return int the reseller a domain with this registrant belongs to: the
   *             contact's own, or reseller 1 for one not stored locally
   */
  public static function resellerOf(?string $registrant): int {
    $resellerId = R::getCell("SELECT reseller_id FROM contacts WHERE handle = ?", [(string) $registrant]);
    return $resellerId === null || $resellerId === false ? 1 : (int) $resellerId;
  }

  /**
   * store domain to DB. It always belongs to its registrant contact's
   * reseller -- that contact is stored locally first, domains.registrant
   * being a foreign key onto it.
   *
   * @param int|null $actorId the acting user, for history (null: a job)
   * @param bool $notifyDNS fire the DNS-sync 'create' event (default yes)
   * @return bool status
   */
  public function storeDB(?int $actorId, bool $notifyDNS = true): bool {
    $data = [
      'status' => serialize($this->status),
      'domain' => $this->domain,
    ];
    foreach (self::FIELDS as $field => $serialized) {
      $data[$field] = $serialized ? serialize($this->$field) : $this->$field;
    }
    $data['cr_date'] = self::dateOnly($this->crDate);
    $data['ex_date'] = self::dateOnly($this->exDate);

    // re-transfer-in / re-register / re-import: an existing row is rewritten
    // in place, keeping its id (history, tasks) and last_invoice
    $data['reseller_id'] = self::resellerOf($this->registrant);
    if (R::getCell("SELECT id FROM domains WHERE domain = ?", [$this->domain])) {
      $data['active'] = 1;
      if ( ! $this->storageUpdate($this->domain, $data, Scope::operator((int) $actorId))) {
        return FALSE;
      }
    } elseif ( ! $this->storageInsert($data, $this->domain)) {
      return FALSE;
    }

    History::record('domains', $this->storageId($this->domain), 'create', ['domain' => $this->domain], $actorId);

    if ($notifyDNS) {
      // `eppitnic pdns sync` picks this up to (re)create the zone -- see
      // queueDnsSync() for the gate.
      $this->queueDnsSync($this->domain, 'domain created', 'create', $this->ns);
    }

    return TRUE;
  }

  /**
   * load domain from DB
   *
   * @param string $domain domain to load
   * @return bool status
   */
  public function loadDB(?string $domain, Scope $scope): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $row = $this->storageFind($domain, $scope);
    if ($row === null) {
      $this->setError("Domain '{$domain}' not found.");
      return FALSE;
    }

    // 'status' carries no change bit -- it is set by the registry rather than
    // by a caller -- but is stored serialized like the rest
    $serialized = array_keys(array_filter(self::FIELDS));
    $serialized[] = 'status';
    $this->storageHydrate($row, $serialized);

    // initialize data
    $this->resetChangeTracking();
    return TRUE;
  }

  /**
   * update domain stored in DB
   *
   * @param string $domain domain to update
   * @param array|null $changes the fields to persist (defaults to whatever is
   *                     currently changed). Pass it explicitly when update()
   *                     has already run: it clears the set once the registry
   *                     has accepted the change, before updateDB() can read it.
   * @return bool status
   */
  public function updateDB(?string $domain, Scope $scope, ?array $changes = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }

    if ($domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ($changes === null) {
      $changes = $this->changedFields();
    }

    if ($changes === array()) {
      $this->setError("Domain did not change!");
      return FALSE;
    }

    // read before storageUpdate() overwrites it -- queueDnsSync() needs the
    // old NS set too, to notice a domain moving away from it
    $oldNs = in_array('ns', $changes, true)
      ? SerializedColumn::toArray((string) R::getCell("SELECT ns FROM domains WHERE domain = ?", [$domain]))
      : [];

    $data = array(
      'status'  => serialize($this->status),
    );
    foreach (self::FIELDS as $field => $serialized) {
      if (in_array($field, $changes, true)) {
        $data[$field] = $serialized ? serialize($this->$field) : $this->$field;
      }
    }

    if (in_array('registrant', $changes, true)) {
      // a registrant change moves the domain to that contact's reseller. It
      // is the caller's job to have checked they may use it -- see
      // Api\Access::canUseAsRegistrant()
      $data['reseller_id'] = self::resellerOf($this->registrant);
    }

    $data['cr_date'] = self::dateOnly($this->crDate);
    $data['ex_date'] = self::dateOnly($this->exDate);

    if ( ! $this->storageUpdate($domain, $data, $scope)) {
      return FALSE;
    }

    History::record('domains', $this->storageId($domain), 'update', $data, $scope->userId);

    // only nameserver changes need a PowerDNS update. Try the new NS set
    // first; if nothing was queued for it (e.g. it doesn't touch a
    // configured nameserver), the domain may have just moved away from one
    // the old NS set did touch, so a delete is tried against that instead.
    if (in_array('ns', $changes, true)
      && ! $this->queueDnsSync($domain, 'nameservers changed', 'update', $this->ns)
    ) {
      $this->queueDnsSync($domain, 'nameservers changed (moved away)', 'delete', $oldNs);
    }

    return TRUE;
  }

  /**
   * transfer status
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferStatus(?string $domain, ?string $authinfo = ""): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }
    // if authinfo was not given as an argument, but has been set
    if (($authinfo === null) && $this->changed('authinfo')) {
      $authinfo = $this->authinfo;
    }

    $this->xmlQuery = XmlBuilder::domainTransferQuery(
      $this->client->set_clTRID(),
      $domain,
      empty($authinfo) ? '' : $authinfo
    );

    // query server
    if ($this->ExecuteQuery("domain-transfer-query", $domain)) {
      $tmp = $this->responseData('domain');
      if ($tmp === null || ! isset($tmp->trnData->trStatus)) {
        $this->setError("The registry accepted the query but returned no transfer data.");
        return FALSE;
      }

      $this->trStatus = (string)$tmp->trnData->trStatus;
      $this->reID = (string)($tmp->trnData->reID ?? '');
      $this->acID = (string)($tmp->trnData->acID ?? '');
      return TRUE;
    } else {
      if (isset($this->xmlResult->response->result->extValue->reason)) {
        $this->svMsg = (string)$this->xmlResult->response->result->extValue->reason;
      }
      return FALSE;
    }
  }

  /**
   * transfer domain / transfer-trade domain
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @param string $newregistrant new registrant (optional / trade)
   * @param string $newauthinfo new authinfo (optional)
   * @param string $operation transfer type (defaults to "request")
   * @return bool status
   */
  public function transfer(?string $domain, ?string $authinfo, string $newregistrant = "", string $newauthinfo = "", string $operation = "request"): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    if ($authinfo === null) {
      $authinfo = $this->authinfo;
    }
    if ($authinfo == "") {
      $this->setError("Operation not allowed, state the domain authinfo!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainTransfer(
      $this->client->set_clTRID(),
      $domain,
      $authinfo,
      $operation,
      $newregistrant,
      empty($newauthinfo) ? $this->authinfo() : $newauthinfo
    );

    // query server
    return $this->ExecuteQuery("domain-transfer-".$operation, $domain);
  }

  /**
   * approve domain transfer to another registrar
   *
   * @param string $domain domain to operate on
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferApprove(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "approve");
  }

  /**
   * reject domain transfer to another registrar
   *
   * @param string $domain domain to operate on
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferReject(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "reject");
  }

  /**
   * cancel domain transfer from another registrar
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferCancel(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "cancel");
  }

  /**
   * list domains stored in DB (includes pending transfer-in domains)
   *
   * @param string $registrant restrict search to this registrant (optional)
   * @param bool $activeOnly list only active domains (TRUE = yes / FALSE = no)
   * @param int $age restrict search to domains older then X months
   * @return array list of domains
   */
  public function listDomains(Scope $scope, ?string $registrant = null, bool $activeOnly = TRUE, int $age = 0): array {
    $where = ['1 = 1'];
    $params = [];
    if ( ! $scope->isAdmin()) {
      $where[] = 'reseller_id = :reseller_id';
      $params[':reseller_id'] = $scope->resellerId;
    }
    if ($registrant !== null) {
      $where[] = 'registrant = :registrant';
      $params[':registrant'] = $registrant;
    }

    // pending transfer-in domains (active/age restrictions do not apply) --
    // not a domain EPP has confirmed exists locally yet, so no status of its own
    $domains = array_map(static function (array $row): array {
      $row['status'] = [];
      return $row;
    }, R::getAll("
      SELECT concat(domain, CASE WHEN status = 'cancelled' THEN ' (transfer-in cancelled)' ELSE ' (transfer-in)' END) as domain, registrant, reseller_id, NULL AS ex_date,
        (SELECT r.name FROM resellers r WHERE r.id = transfers.reseller_id) AS reseller_name
      FROM transfers WHERE " . implode(' AND ', $where) . "
        AND NOT EXISTS (SELECT 1 FROM domains d WHERE d.domain = transfers.domain AND d.active = 1)
      ORDER BY domain ASC", $params));

    if ($activeOnly) {
      $where[] = 'active = :active';
      $params[':active'] = 1;
    }
    if ($age > 0) {
      $where[] = 'ex_date < DATE_SUB(CURDATE(), INTERVAL :age MONTH)';
      $params[':age'] = (int)$age;
    }

    $active = R::getAll("
      SELECT domain, registrant, reseller_id, ex_date, status,
        (SELECT r.name FROM resellers r WHERE r.id = domains.reseller_id) AS reseller_name
      FROM domains WHERE " . implode(' AND ', $where) . "
      ORDER BY domain ASC", $params);
    // status is a serialized column, in either of the two shapes the table holds
    $active = array_map(static function (array $row): array {
      $row['status'] = SerializedColumn::toArray($row['status'] ?? null);
      return $row;
    }, $active);

    return array_merge($domains, $active);
  }

  /**
   * deactivate a domain stored in DB (soft delete)
   *
   * @param string $domain domain name to delete
   * @return bool status
   */
  public function deleteDomainDB(string $domain, Scope $scope): bool {
    $ns = SerializedColumn::toArray((string) R::getCell("SELECT ns FROM domains WHERE domain = ?", [$domain]));

    if ( ! $this->storageSetActive($domain, 0, $scope, 'delete', ['domain' => $domain])) {
      return FALSE;
    }

    // `eppitnic pdns sync` tears the zone down (delay-gated) -- see
    // queueDnsSync()
    $this->queueDnsSync($domain, 'domain deleted', 'delete', $ns);

    return TRUE;
  }

  /**
   * reactivate a domain stored in DB (undo a soft delete)
   *
   * @param string $domain domain name to restore
   * @return bool status
   */
  public function restoreDomainDB(string $domain, Scope $scope): bool {
    // logged as 'update': the history action enum has no 'restore'
    if ( ! $this->storageSetActive($domain, 1, $scope, 'update', ['domain' => $domain, 'active' => 1])) {
      return FALSE;
    }

    // symmetric with deleteDomainDB() -- the zone needs to come back
    $ns = SerializedColumn::toArray((string) R::getCell("SELECT ns FROM domains WHERE domain = ?", [$domain]));
    $this->queueDnsSync($domain, 'domain restored', 'create', $ns);

    return TRUE;
  }

  /**
   * Queue a DNS-sync event for `pdns sync`, gated on `pdns.enabled`, a
   * non-empty `pdns.apis`, and $ns touching a configured `pdns.nameservers`
   * (an empty `nameservers` list means nothing ever syncs). `CASE` keeps
   * the JSON functions from ever seeing invalid JSON.
   *
   * @param array $ns the domain's ns column shape (hostname => details)
   * @return bool whether a row was actually queued
   */
  private function queueDnsSync(string $domain, string $notice, string $action, array $ns): bool {
    // one JSON_CONTAINS per nameserver rather than JSON_OVERLAPS, which
    // MariaDB only has from 10.9; no nameservers means nothing to match
    $names = self::nsNames($ns);
    $overlap = $names === []
      ? '0'
      : '(' . implode(' OR ', array_fill(0, count($names), "JSON_CONTAINS(JSON_EXTRACT(value, '$.nameservers'), ?)")) . ')';

    $queued = R::exec("
      INSERT INTO tasks (domain, date, notice, object, action)
      SELECT ?, CURRENT_DATE, ?, 'pdns', ? FROM settings
      WHERE `key` = 'pdns'
        AND CASE WHEN JSON_VALID(value)
                 THEN JSON_VALUE(value, '$.enabled') = 1
                  AND JSON_LENGTH(value, '$.apis') > 0
                  AND {$overlap}
                 ELSE 0 END
    ", array_merge(
      [$domain, $notice, $action],
      array_map(static fn(string $name) => json_encode($name, JSON_UNESCAPED_SLASHES), $names)
    )) > 0;

    // the latest intent wins: a delayed delete must not tear down a zone just
    // (re)created, nor a pending create/update rebuild one about to be deleted
    if ($queued) {
      R::exec("
        UPDATE tasks SET active = 0, exit_code = 0, exit_message = ?
        WHERE object = 'pdns' AND domain = ? AND active = 1
          AND " . ($action === 'delete' ? "action <> 'delete'" : "action = 'delete'") . "
      ", ["superseded by a later {$action}", $domain]);
    }

    return $queued;
  }

  /**
   * The date part of a registry timestamp ("2026-09-27T15:56:37.000+02:00"),
   * which a DATE column refuses in strict SQL mode.
   */
  private static function dateOnly(?string $value): ?string {
    return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $m) === 1 ? $m[0] : $value;
  }

  /** @return string[] lowercased, trailing-dot-stripped hostnames */
  private static function nsNames(array $ns): array {
    return array_map(
      static fn(int|string $name) => rtrim(strtolower((string) $name), '.'),
      array_keys($ns)
    );
  }
}
