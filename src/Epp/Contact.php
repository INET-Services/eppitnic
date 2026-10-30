<?php

namespace Eppitnic\Epp;

use Eppitnic\Persistence\ChangeTracking;
use Eppitnic\Persistence\LocalStorage;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Support\PasswordGenerator;
use RedBeanPHP\R;

/**
 * This class handles contact objects.
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
 * @package     Eppitnic\Epp\Contact
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Contact extends AbstractObject
{
  use ChangeTracking;
  use LocalStorage;

  protected static function storageTable(): string { return 'contacts'; }
  protected static function storageKeyColumn(): string { return 'handle'; }
  protected static function storageNoun(): string { return 'contact'; }

  /**
   * The contact's own fields. One list, where there were four -- the property
   * declarations, set(), updateDB() and storeDB() -- and missing one of them
   * silently left a field settable but never persisted.
   */
  public const FIELDS = array(
    'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
    'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo',
    'consentforpublishing', 'nationalitycode', 'entitytype', 'regcode',
    'schoolcode',
  );

  /** the client-side states a contact accepts, for updateStatus() */
  private const CLIENT_STATES = array('clientDeleteProhibited', 'clientUpdateProhibited');

  /** the seven fields that together make up <contact:addr> */
  private const ADDRESS_FIELDS = array(
    'street', 'street2', 'street3', 'city', 'province', 'postalcode', 'countrycode',
  );

  /**
   * Fields whose "unset" value is not the empty string.
   */
  private const FIELD_DEFAULTS = array(
    'consentforpublishing' => 0,
    'entitytype'           => 0,
  );

  /**
   * Fields set() must not mark dirty itself: each has a setter that decides
   * whether anything actually changed (setConsent(), setEntityType()).
   */
  private const FIELDS_WITH_SETTERS = array('consentforpublishing', 'entitytype');

  protected $reseller_id;          // use just in case of an updateRegistrant + change of agent
  protected $status;               // contact states (ok, linked, clientDeleteProhibited, clientUpdateProhibited)
  protected $handle;               // -

  protected $name;
  protected $org;
  protected $street;
  protected $street2;
  protected $street3;
  protected $city;
  protected $province;
  protected $postalcode;
  protected $countrycode;
  protected $voice;
  protected $fax;
  protected $email;
  protected $authinfo;
  protected $consentforpublishing;
  protected $nationalitycode;
  protected $entitytype;
  protected $regcode;
  protected $schoolcode;

  /**
   * Class constructor
   *
   * @param Client $client client class
   */
  function __construct(Client $client) {
    parent::__construct($client);

    $this->initValues();
  }

  /**
   * initialize values
   */
  protected function initValues(): void {
    $this->reseller_id = 1;
    $this->status    = array();
    $this->handle    = "";
    $this->clearChanges();

    foreach (self::FIELDS as $field) {
      $this->$field = self::FIELD_DEFAULTS[$field] ?? "";
    }

    // After the loop: authinfo is in FIELDS, so generating it earlier would be
    // overwritten with "". Assigned directly, not via set(), so the default is
    // not a change -- update() sends it only when a new one was asked for
    $this->authinfo = $this->authinfo();
  }

  /**
   * check for possible values of TRUE
   */
  private function isTrue(mixed $val): bool {
    if ($val === TRUE) {
      return TRUE;
    } else if ((string)$val == "1") {
      return TRUE;
    } else if (strtoupper($val) === "TRUE") {
      return TRUE;
    } else {
      return FALSE;
    }
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

    // Stored as given: XmlBuilder escapes at serialization and PDO parameters
    // need none. Escaping here left HTML entities in the database, which every
    // consumer then had to undo.
    $val = (string)$val;

    if ($var == "entitytype") {
      return $this->setEntityType($val);
    } else if ($var == "consentforpublishing" && $this->isTrue($val)) {
      return $this->setConsent();
    } else if ($var == "consentforpublishing" && ! $this->isTrue($val)) {
      return $this->unsetConsent();
    } else if (isset($this->$var)) {
      if ($this->$var == $val) {
        return FALSE; // value didn't change!
      } else {
        $this->$var = $val;
      }
    } else {
      return FALSE; // value doesn't exist!
    }

    // consentforpublishing and entitytype are dispatched above to setters that
    // decide for themselves whether anything changed, so they never reach here
    if (in_array($var, self::FIELDS, true)) {
      $this->markChanged($var);
    }
    return $this->$var;
  }

  /**
   * get a single variable/setting from class
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    $var = strtolower($var);
    return $this->$var;
  }

  /**
   * set the entity type which may be one of the following; anything else
   * leaves it at 0, which create() refuses
   *
   * 1 - persone fisiche italiane e straniere
   * 2 - società
   * 3 - imprese individuali, liberi professionisti / ordini professionali
   * 4 - enti no-profit
   * 5 - enti pubblici
   * 6 - altri soggetti
   * 7 - soggetti stranieri equiparati ai precedenti escluso le persone fisiche
   *
   * nic.it's classification; ContactCreateCommand::ENTITY_TYPES repeats it in
   * English for --help, and the two have to stay in step.
   *
   * @param int $type entity type
   * @return bool status
   */
  protected function setEntityType(mixed $type): bool|int {
    $tmp = (int)$type;
    if (($tmp < 1) || ($tmp > 7)) {
      $tmp = 0; // failback to the default value
    }

    if ($this->entitytype == $tmp) {
      return FALSE;
    }

    $this->markChanged('entitytype');
    return $this->entitytype = $tmp;
  }

  /**
   * set consent for publishing
   *
   * @return int|false 1, or FALSE when consent was already set
   */
  public function setConsent(): bool|int {
    if ($this->consentforpublishing == 1) {
      return FALSE;
    }

    $this->markChanged('consentforpublishing');
    return $this->consentforpublishing = 1;
  }

  /**
   * unset consent for publishing
   *
   * @return int|false 0 (compare with === FALSE), or FALSE when consent was
   *                   already unset
   */
  public function unsetConsent(): bool|int {
    if ($this->consentforpublishing == 0) {
      return FALSE;
    }

    $this->markChanged('consentforpublishing');
    return $this->consentforpublishing = 0;
  }

  /**
   * check whether contact handles are free to be created
   *
   * @param array|string|null $contact one handle, several, or null for the one
   *                       set
   * @return CheckResult the registry's answer, or a failure -- see CheckResult
   */
  public function check(array|string|null $contact = null): CheckResult {
    // contacts come back under <cd><id>, domains under <cd><name> -- otherwise
    // this is AbstractObject::checkAvailability() unchanged
    return $this->checkAvailability(
      $contact,
      $this->handle,
      "Operation not allowed, set a handle!",
      'contact',
      'id',
      fn(string $clTRID, array $handles) => XmlBuilder::contactCheck($clTRID, $handles)
    );
  }

  /**
   * Generate a random, registry-unique contact handle. check() touches no other
   * instance state, so any contact object on a live session will do.
   *
   * @param int $maxAttempts max attempts before giving up
   * @return string a 16-character handle, confirmed available at the registry
   * @throws   \RuntimeException   if the registry could not be asked, or if no
   *           free handle turned up within $maxAttempts
   */
  public function generateHandle(int $maxAttempts = 5): string {
    for ($i = 0; $i < $maxAttempts; $i++) {
      // hex, not a password charset: a handle is an identifier, not a secret --
      // it lands in REST paths, CSV exports and a foreign key, and only has to
      // avoid colliding, which check() below confirms
      $handle = strtoupper(PasswordGenerator::token(8)); // 16 hex chars
      $answer = $this->check($handle);

      // A check that never happened is not a taken handle: retrying produces
      // the same failure, and "no unique handle" would send the reader hunting
      // a collision that is not there
      if ( ! $answer->answered()) {
        throw new \RuntimeException("Unable to check handle availability: " . $answer->error());
      }
      if ($answer->available() === TRUE) {
        return $handle;
      }
    }
    throw new \RuntimeException("Unable to generate a unique contact handle after {$maxAttempts} attempts");
  }

  public const NAME_ORG_RULE = 'name and org must be the same';
  public const NAME_ORG_FIXED = 'name and org cannot be changed once the contact exists';

  /**
   * The registry wants org equal to name: whichever is missing is copied from
   * the other.
   *
   * @return array{0: string, 1: string}|null name and org; null when both are
   *         set and differ
   */
  public static function equalizeNameOrg(string $name, string $org): ?array {
    if ($org === '') {
      return array($name, $name);
    }
    if ($name === '') {
      return array($org, $org);
    }
    return $name === $org ? array($name, $org) : null;
  }

  /**
   * create contact
   *
   * @return bool status
   */
  public function create(): bool {
    if ($this->entitytype < 1 || $this->entitytype > 7) {
      $this->setError("entitytype must be 1 to 7: every contact is a registrant.");
      return FALSE;
    }
    $pair = self::equalizeNameOrg((string)$this->name, (string)$this->org);
    if ($pair === null) {
      $this->setError(self::NAME_ORG_RULE);
      return FALSE;
    }
    [$this->name, $this->org] = $pair;

    $this->xmlQuery = XmlBuilder::contactCreate($this->client->set_clTRID(), [
      'id'                   => $this->handle,
      'name'                 => $this->name,
      'org'                  => $this->org,
      'street'               => [$this->street, $this->street2, $this->street3],
      'city'                 => $this->city,
      'sp'                   => $this->province,
      'pc'                   => $this->postalcode,
      'cc'                   => $this->countrycode,
      'voice'                => $this->voice,
      'email'                => $this->email,
      'authinfo'             => $this->authinfo,
      'consentForPublishing' => $this->consentforpublishing,
      'nationalityCode'      => $this->nationalitycode,
      'entityType'           => $this->entitytype,
      'regCode'              => $this->regcode,
      'schoolCode'           => $this->schoolcode,
    ]);

    // query server and return answer (no handling of special return values)
    $response = $this->ExecuteQuery("contact-create", $this->handle);
    if ($response) {
      $this->status = array('ok');
      return $response;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch contact through EPP
   *
   * @param string $contact contact to load
   * @return bool status
   */
  public function fetch(?string $contact = null): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::contactInfo($this->client->set_clTRID(), $contact);

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("contact-info", $contact)) {
      $this->clearChanges();
      $this->status = array();
      $this->handle = $contact;

      // the postalInfo, not just the infData: a contact without one is not a
      // contact, and reading through it would hand back a row of empty strings
      $tmp = $this->responseData('contact');
      if ($tmp === null || ! isset($tmp->infData->postalInfo->addr)) {
        $this->setError("The registry accepted the query but returned no contact data.");
        return FALSE;
      }

      $this->name =        (string)$tmp->infData->postalInfo->name;
      $this->org =         (string)$tmp->infData->postalInfo->org;
      $this->street =      (string)$tmp->infData->postalInfo->addr->street[0];
      $this->street2 =     (string)$tmp->infData->postalInfo->addr->street[1];
      $this->street3 =     (string)$tmp->infData->postalInfo->addr->street[2];
      $this->city =        (string)$tmp->infData->postalInfo->addr->city;
      $this->province =    (string)$tmp->infData->postalInfo->addr->sp;
      $this->postalcode =  (string)$tmp->infData->postalInfo->addr->pc;
      $this->countrycode = (string)$tmp->infData->postalInfo->addr->cc;
      $this->voice =       (string)$tmp->infData->voice;
      $this->fax =         (string)$tmp->infData->fax;
      $this->email =       (string)$tmp->infData->email;
      foreach ($tmp->infData->status as $singleState) {
        $this->status[] =  (string)$singleState->attributes()->s;
      }

      // the .it registrant details ride along as an extension; a contact that
      // is not a registrant has none
      $extcon = $this->responseExtension('extcon');
      if ($extcon !== null && isset($extcon->infData)) {
        $this->set('consentforpublishing', (string)$extcon->infData->consentForPublishing);
        $this->nationalitycode = (string)($extcon->infData->registrant->nationalityCode ?? '');
        $this->entitytype =         (int)($extcon->infData->registrant->entityType ?? 0);
        $this->regcode =         (string)($extcon->infData->registrant->regCode ?? '');
        $this->schoolcode =      (string)($extcon->infData->registrant->schoolCode ?? '');
      }

      // set() above marked consent as changed; a fetch leaves nothing pending
      $this->clearChanges();
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * delete contact
   *
   * @return bool status
   */
  public function delete(?string $contact = null): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::contactDelete($this->client->set_clTRID(), $contact);

    // query server
    return $this->ExecuteQuery("contact-delete", $contact);
  }

  /**
   * update contact
   *
   * @return bool status
   */
  public function update(): bool {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Handle did not change!");
      return FALSE;
    }

    if ($this->changed('name') || $this->changed('org')) {
      $this->setError(self::NAME_ORG_FIXED);
      return FALSE;
    }

    // address
    $addr = array();
    if ($this->changed(...self::ADDRESS_FIELDS)) {
      // the registry replaces <addr> wholesale, so one changed line means
      // sending all seven
      $addr[] = array('name' => 'street', 'value' => $this->street);
      $addr[] = array('name' => 'street', 'value' => $this->street2);
      $addr[] = array('name' => 'street', 'value' => $this->street3);
      $addr[] = array('name' => 'city', 'value' => $this->city);
      $addr[] = array('name' => 'sp', 'value' => $this->province);
      $addr[] = array('name' => 'pc', 'value' => $this->postalcode);
      $addr[] = array('name' => 'cc', 'value' => $this->countrycode);
    }

    // only the changed fields; fax is no longer sent
    $contact = array();
    if ($this->changed('voice')) $contact[] = array('name' => 'voice',  'value' => $this->voice);
    if ($this->changed('email')) $contact[] = array('name' => 'email',  'value' => $this->email);

    // registrant information
    $registrant = array();
    if ($this->changed('nationalitycode')) {
      $registrant['nationalityCode'] = $this->nationalitycode;
    }
    if ($this->changed('entitytype')) {
      $registrant['entityType'] = $this->entitytype;
    }
    if ($this->changed('regcode')) {
      $registrant['regCode'] = $this->regcode;
    }
    if ($this->changed('schoolcode')) {
      $registrant['schoolCode'] = $this->schoolcode;
    }

    // a fax-only change leaves nothing to send
    if ($addr === array() && $contact === array() && $registrant === array()
        && ! $this->changed('authinfo') && ! $this->changed('consentforpublishing')) {
      return TRUE;
    }

    $this->xmlQuery = XmlBuilder::contactUpdate(
      $this->client->set_clTRID(),
      $this->handle,
      $addr,
      $contact,
      $this->changed('authinfo') ? $this->authinfo : '',
      $this->changed('consentforpublishing') ? (int)$this->consentforpublishing : '',
      $registrant
    );

    // query server
    return $this->ExecuteQuery("contact-update", $this->handle);
  }

  /**
   * update contact status
   *
   * @param string $state clientDeleteProhibited, clientUpdateProhibited
   * @param string $adddel add, rem (optional, defaults to add)
   * @return bool status
   */
  public function updateStatus(string $state, string $adddel = "add"): bool {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }

    if ( ! $this->applyStatusChange(self::CLIENT_STATES, $state, $adddel)) {
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::contactStatus($this->client->set_clTRID(), $this->handle, $adddel, $state);

    // query server
    $result = $this->ExecuteQuery("contact-status", $this->handle);
    if ($result) {
      $this->clearChanges();
    }
    return $result;
  }

  /**
   * Store contact to DB, upserting: no delete-then-insert like
   * Domain::storeDB(), domains.registrant being a foreign key onto
   * contacts.handle. On an existing row two columns are left alone: -
   * `reseller_id`, so re-importing does not reassign another reseller's
   * contact - `active`, so an import does not resurrect a deactivation
   *
   * @param int $resellerId who owns a newly created row (existing ones keep theirs)
   * @param int $actorId the acting user, for history
   * @return bool status
   */
  public function storeDB(int $resellerId, int $actorId): bool {
    $data = ['status' => serialize($this->status)];
    foreach (self::FIELDS as $field) {
      $data[$field] = $this->$field;
    }

    $existing = R::getRow("SELECT id FROM contacts WHERE handle = ?", [$this->handle]);

    if (empty($existing)) {
      $data['handle'] = $this->handle;
      $data['reseller_id'] = $resellerId;

      if ( ! $this->storageInsert($data, $this->handle)) {
        return FALSE;
      }
    } else {
      // unscoped: an upsert is not a scoped write. $resellerId says who owns
      // a *new* row, not who may touch an existing one -- the two fields left
      // out of $data above are exactly the ones that would move.
      if ( ! $this->storageUpdate($this->handle, $data, Scope::operator($actorId))) {
        return FALSE;
      }
    }

    History::record(
      'contacts', $this->storageId($this->handle),
      empty($existing) ? 'create' : 'update', ['handle' => $this->handle], $actorId
    );
    return TRUE;
  }

  /**
   * load contact from DB
   *
   * @param string $contact contact to load
   * @return bool status
   */
  public function loadDB(?string $contact, Scope $scope): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $row = $this->storageFind($contact, $scope);
    if ($row === null) {
      $this->setError("Contact '{$contact}' not found.");
      return FALSE;
    }

    // 'status' is the only serialized column here; it carries no change bit,
    // being set by the registry rather than by a caller
    $this->storageHydrate($row, ['status']);
    $this->clearChanges();
    return TRUE;
  }

  /**
   * update contact stored in DB
   *
   * @param string $contact contact to update; never changes who owns it
   * @return bool status
   */
  public function updateDB(?string $contact, Scope $scope): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Handle did not change!");
      return FALSE;
    }

    $data = array(
      'status'  => serialize($this->status),
    );
    foreach (self::FIELDS as $field) {
      if ($this->changed($field)) {
        $data[$field] = $this->$field;
      }
    }

    if ( ! $this->storageUpdate($contact, $data, $scope)) {
      return FALSE;
    }

    History::record('contacts', $this->storageId($contact), 'update', $data, $scope->userId);
    return TRUE;
  }

  /**
   * list contacts stored in DB
   *
   * @param bool $activeOnly list only active contacts (TRUE = yes / FALSE = no)
   * @return array list of contacts
   */
  public function listContacts(Scope $scope, bool $activeOnly = TRUE): array {
    $where = ['1 = 1'];
    $params = [];
    if ( ! $scope->isAdmin()) {
      $where[] = 'reseller_id = :reseller_id';
      $params[':reseller_id'] = $scope->resellerId;
    }
    if ($activeOnly) {
      $where[] = 'active = 1';
    }
    $rows = R::getAll("SELECT handle, org, name, entitytype, status, reseller_id,
      (SELECT r.name FROM resellers r WHERE r.id = contacts.reseller_id) AS reseller_name
      FROM contacts WHERE " . implode(' AND ', $where) . " ORDER BY org, name ASC", $params);

    // status is a serialized column, in either of the two shapes the table holds
    return array_map(static function (array $row): array {
      $row['status'] = SerializedColumn::toArray($row['status'] ?? null);
      return $row;
    }, $rows);
  }

  /**
   * deactivate a contact stored in DB (soft delete)
   *
   * @param string $contact contact name / handle
   * @return bool status
   */
  public function deleteContactDB(string $contact, Scope $scope): bool {
    // refuses while the contact is still some active domain's registrant --
    // the foreign key would reject it anyway, and this says so first
    return $this->storageSetActive(
      $contact, 0, $scope, 'delete', ['handle' => $contact],
      ' AND (SELECT COUNT(1) FROM domains WHERE registrant = :registrant AND active = 1) = 0',
      [':registrant' => $contact]
    );
  }

  /**
   * reactivate a contact stored in DB (undo a soft delete)
   *
   * @param string $contact contact name / handle
   * @return bool status
   */
  public function restoreContactDB(string $contact, Scope $scope): bool {
    // logged as 'update': the history action enum has no 'restore'
    return $this->storageSetActive(
      $contact, 1, $scope, 'update', ['handle' => $contact, 'active' => 1]
    );
  }

  /**
   * create a brand-new EPP contact copying this contact's data (already
   * fetch()ed), under a new local owner
   *
   * @param Client $nic a live client (used to construct the new Contact object)
   * @param int $newOwnerId the new contact's local owner (users.id)
   * @return string|false the new contact's handle, or false on failure
   */
  public function duplicate(Client $nic, int $resellerId, int $actorId): string|false {
    $fields = [
      'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
      'postalcode', 'countrycode', 'voice', 'email',
      'nationalitycode', 'entitytype', 'regcode', 'schoolcode',
    ];

    $new = new self($nic);
    $new->set('handle', $new->generateHandle());
    foreach ($fields as $field) {
      $value = $this->get($field);
      if ($value === '' || $value === null) {
        continue;
      }
      // get() returns the already-escaped value set() stored -- undo one
      // layer before re-escaping it, or it double-encodes on every copy
      $new->set($field, html_entity_decode((string) $value, ENT_COMPAT, 'UTF-8'));
    }
    $new->set('authinfo', $new->authinfo());

    // the registry wants name == org: the legal name (org) wins, name only
    // fills in when org is empty
    $new->name = $new->org = ((string)$new->org !== '') ? $new->org : $new->name;

    if ( ! $new->create()) {
      return false;
    }
    if ( ! $new->storeDB($resellerId, $actorId)) {
      $this->setError(
        "created at the registry as {$new->get('handle')}, not stored locally: " . $new->getError()
      );
      return false;
    }
    return $new->get('handle');
  }
}
