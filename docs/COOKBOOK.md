# Cookbook

Recipes for using `eppitnic` as a PHP library: open a registry session with
`EppSession::run()` and work with `Domain`, `Contact` and `Session` objects
inside its callback. For the command line see `bin/eppitnic --help`, and for
the REST API see [API.md](API.md).

## Prerequisites

- A configured installation: `config/config.php` and a populated database
  (see [INSTALL.md](INSTALL.md)).
- The registry credentials in the `epp` setting.

Every snippet below assumes this preamble:

```php
require 'vendor/autoload.php';

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Epp\Session;
use Eppitnic\Service\EppSession;
```

`$userId` is the id of the acting user (recorded in `history`), and
`$resellerId` the id of the reseller that owns what you create. Values
written `<LIKE_THIS>` are handles or codes you supply.

## Open a registry session

`EppSession::run()` opens a session and hands you a logged-in client:

```php
$credit = EppSession::run(function (Client $nic, Session $session) {
    return $session->showCredit();
});
```

With the `keepalive` setting off — the default — it logs out afterwards, even
when your callback throws. With it on, it reuses the session
`eppitnic session keepalive` keeps fresh and never logs out (see "Session
keep-alive" in [INSTALL.md](INSTALL.md)).

It throws `RuntimeException` if the registry is unreachable or refuses the
login. Every snippet below runs inside such a callback, with `$nic` in scope.

Opening a session by hand is only worth it to get the greeting without
logging in, for example to check that the registry is reachable:

```php
$nic = new Client();
$session = new Session($nic);

if ($session->hello()) {
    echo $session->xmlResult->greeting->svID, "\n";
}
```

## Check whether a domain is available

```php
$domain = new Domain($nic);

$answer = $domain->check('example.it');

if ( ! $answer->answered()) {
    // the registry never answered -- neither available nor taken
    echo $answer->error(), "\n";
} elseif ($answer->available()) {
    echo "free\n";
} else {
    echo "taken: ", $answer->reason(), "\n";
}
```

Pass an array for up to five names at once. `available('example.it')` and
`reason('example.it')` then take the name, `all()` gives every answer keyed
by name, and `availableNames()` gives just the free ones. Batch longer lists
yourself — the registry rejects more than five names in one request.

`Contact::check()` answers the same way, keyed by handle.

## Read a domain

```php
$domain = new Domain($nic);

if ($domain->fetch('example.it')) {
    echo $domain->get('registrant'), "\n";
    echo implode(', ', $domain->get('status')), "\n";

    // an array, keyed by hostname
    echo implode(', ', array_keys($domain->get('ns'))), "\n";
}
```

A domain sponsored by another registrar needs its authinfo, and can return
the linked contacts in the same round trip (`'all'` or `'registrant'`):

```php
$domain->fetch('example.it', '<AUTHINFO>', 'all');
$contacts = $domain->get('infcontacts');
```

## Create a contact

The registry assigns no handles: pick one and check it is free.
`generateHandle()` does both.

```php
$contact = new Contact($nic);
$contact->set('handle', $contact->generateHandle());   // random, checked free

$contact->set('name', 'Mario Rossi');
$contact->set('street', 'Via Roma 1');
$contact->set('city', 'Bolzano');
$contact->set('province', 'BZ');
$contact->set('postalcode', '39100');
$contact->set('countrycode', 'IT');
$contact->set('voice', '+39.0471000000');
$contact->set('email', 'mario.rossi@example.it');

// an authinfo is generated for you; set one explicitly to choose it

// every contact is a registrant, so all three are required
$contact->set('nationalitycode', 'IT');
$contact->set('entitytype', 2);
$contact->set('regcode', '01234567897');   // a partita IVA; the checksum is verified

// required for this entity type -- see below
$contact->setConsent();

if ( ! $contact->create()) {
    throw new RuntimeException($contact->getError());
}
$contact->storeDB($resellerId, $userId);   // optional: keep a local copy
```

Consent to publication is not free to choose. Only entity type 1 (a natural
person) and entity type 3 (a freelancer) may withhold it; every other
registrant is published in the public whois by law. Without `setConsent()`
the contact above is refused with EPP code `2308` and extended reason `8028`,
*"consentForPublishing cannot be set to false if entity type != 1 and entity
type != 3"*.

Every contact is a registrant: entity type 1 to 7 is required, and `create()`
refuses anything else before the registry is asked. `name` and `org` must be
the same: set either one and the other is copied from it; setting both to
different values fails with *"name and org must be the same"*. Neither can be
changed afterwards, and `update()` refuses an attempt. There is no fax number.

The registration code is checked, not merely required. For entity type 2 it
is a partita IVA, and the registry verifies its check digit: the last of the
eleven, chosen so that the whole number adds up to a multiple of ten under
the usual Luhn variant. A code that does not add up is refused with EPP code
`2004` and extended reason `8027`, *"Registrant: invalid reg code"*. The
familiar placeholder `01234567890` is **not** valid — its check digit should
be `7`.

## Register a domain

`DomainService::createOrTransfer()` is the whole flow: it checks the name,
then registers it, or requests its transfer if somebody else holds it.

```php
use Eppitnic\Service\DomainService;

$result = DomainService::createOrTransfer($nic, [
    'domain'     => 'example.it',
    'registrant' => '<REGISTRANT_HANDLE>',
    'ns'         => ['ns1.example.it', 'ns2.example.it'],
], $userId);

if ( ! $result['ok']) {
    throw new RuntimeException($result['error']);
}
// $result['action'] is 'created' or 'transfer-requested'
```

A registration is stored locally as a domain of the registrant's reseller. A
requested transfer is stored as a pending row in `transfers`, as `POST
/v1/domains/{name}/transfer` does, and becomes a domain once `eppitnic poll
process` sees it complete: it applies the requested nameservers, retrying a
refused update up to 3 times, 20 minutes apart, and then emails a
`transfer_update_failed` notice. The registrant must be a stored contact.

A domain has a registrant and nameservers, nothing else: the registry has no
administrative or technical contacts. `admin` and `tech` keys are accepted and
ignored, and nothing about them is sent.

The object API underneath, to use the pieces separately:

```php
$domain = new Domain($nic);
$domain->set('domain', 'example.it');
$domain->set('registrant', '<REGISTRANT_HANDLE>');
$domain->addNS('ns1.example.it');
$domain->addNS('ns2.example.it', ['192.0.2.1']);   // glue, when below the domain
$domain->set('authinfo', $domain->authinfo());     // 16 random characters, mixed classes

if ( ! $domain->create()) {
    throw new RuntimeException($domain->getError());
}
```

## Change a domain

An update is a diff against what was fetched, so fetch first. Adding a
nameserver that is already there, or removing one that is not, is no change —
`update()` sends only what differs.

```php
use Eppitnic\Persistence\Scope;

$domain = new Domain($nic);
$domain->fetch('example.it');

$domain->addNS('ns3.example.it');
$domain->remNS('ns1.example.it');

// capture this before update(), which clears it on success
$changes = $domain->changedFields();

if ($domain->update()) {
    $domain->updateDB('example.it', Scope::operator($userId), $changes);
}
```

The registrant is **not** part of this. Changing it is a separate EPP command,
and the authinfo must change with it:

```php
$domain->set('registrant', '<NEW_REGISTRANT_HANDLE>');
$domain->set('authinfo', $domain->authinfo());
$domain->updateRegistrant();
```

## Transfer a domain

```php
$domain = new Domain($nic);

$domain->transfer('example.it', '<AUTHINFO>');         // claim it
$domain->transferApprove('example.it', '<AUTHINFO>');  // agree to someone's claim
$domain->transferReject('example.it', '<AUTHINFO>');
$domain->transferCancel('example.it', '<AUTHINFO>');   // withdraw your own
$domain->transferStatus('example.it', '<AUTHINFO>');   // then get('trStatus')
```

## Read the poll queue

Messages arrive one at a time. Reading one does not remove it; acknowledging
it does, and that reveals the next.

```php
EppSession::run(function (Client $nic, Session $session) {
    while ($session->pollMessageCount() > 0) {
        $id = $session->pollID();

        $session->poll(true, 'req', $id);    // read it, and store it locally
        $session->poll(false, 'ack', $id);   // remove it from the queue
    }
});
```

Prefer `eppitnic poll process`, which does this on a schedule and also
applies completed transfers and registry password reminders.

## Handle errors

Every method returns `false` on failure, with the registry's own words behind
`getError()`:

```php
if ( ! $domain->create()) {
    echo $domain->getError();
    // " EPP code '2302': Object exists"
}
```

Pass `true` as `EppSession::run()`'s second argument (or set
`$domain->debug = true`) to make `getError()` include the full request and
response and record every command in the `transactions`/`responses` tables.

> **Warning:** those rows hold the raw XML — registrant names, addresses and
> authinfo codes. Debug is off by default; the API turns it on per user
> through `users`.`debug`.

## Keep a local copy in the database

The `*DB()` methods maintain a local mirror of registry objects; nothing
calls them for you except `DomainService` and the CLI/API.

```php
use Eppitnic\Persistence\Scope;

$scope = new Scope($userId, $resellerId, 'user');  // or Scope::operator($userId)

$domain->storeDB($userId);                        // insert or replace, as $userId
$domain->loadDB('example.it', $scope);            // read it back
$domain->updateDB('example.it', $scope, $changes);
$domain->listDomains($scope);
$domain->deleteDomainDB('example.it', $scope);    // deactivates
```

A `Scope` limits reads and writes to one reseller's rows; an admin scope
(`Scope::operator()`, what the CLI uses) reaches every reseller's. A stored
domain always belongs to its registrant contact's reseller.

Deletes are soft: a domain row stays, because `domains`.`registrant` is a
foreign key onto `contacts`.`handle`.
