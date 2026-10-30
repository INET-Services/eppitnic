<?php

namespace Eppitnic\Epp;

/**
 * Builds EPP requests with DOMDocument, one method per command: a node is
 * either added or it is not, so no caller assigns an empty value to keep a
 * template quiet, and escaping happens once at serialization.
 *
 * @category    Net
 * @package     Eppitnic\Epp\XmlBuilder
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class XmlBuilder
{
    public const EPP     = 'urn:ietf:params:xml:ns:epp-1.0';
    public const DOMAIN  = 'urn:ietf:params:xml:ns:domain-1.0';
    public const CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
    public const XSI     = 'http://www.w3.org/2001/XMLSchema-instance';
    public const EXTCON  = 'http://www.nic.it/ITNIC-EPP/extcon-1.0';
    public const EXTDOM  = 'http://www.nic.it/ITNIC-EPP/extdom-2.0';
    public const EXTEPP  = 'http://www.nic.it/ITNIC-EPP/extepp-2.0';
    public const SECDNS  = 'urn:ietf:params:xml:ns:secDNS-1.1';
    public const EXTSEC  = 'http://www.nic.it/ITNIC-EPP/extsecDNS-1.0';
    public const RGP     = 'urn:ietf:params:xml:ns:rgp-1.0';

    private \DOMDocument $dom;
    private \DOMElement $root;

    private function __construct(bool $schemaLocation = true) {
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        // the registry's own responses are standalone="no"; requests match
        $this->dom->xmlStandalone = false;

        $this->root = $this->dom->createElementNS(self::EPP, 'epp');
        $this->dom->appendChild($this->root);

        if ($schemaLocation) {
            $this->root->setAttributeNS(
                'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI
            );
            $this->root->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::EPP . ' epp-1.0.xsd');
        }
    }

    // ---------------------------------------------------------------
    // primitives
    // ---------------------------------------------------------------

    /**
     * @param string|null $namespace null for a plain element in no namespace
     */
    private function element(\DOMNode $parent, string $name, ?string $text = null, ?string $namespace = null): \DOMElement {
        $node = $namespace === null
            ? $this->dom->createElement($name)
            : $this->dom->createElementNS($namespace, $name);

        if ($text !== null) {
            // createTextNode, not the constructor's $value argument: the
            // latter does not escape, so '&' in an organisation name would
            // produce a malformed document
            $node->appendChild($this->dom->createTextNode($text));
        }

        $parent->appendChild($node);
        return $node;
    }

    /**
     * The <command> wrapper every request but <hello> uses.
     */
    private function command(): \DOMElement {
        return $this->element($this->root, 'command', null, self::EPP);
    }

    /**
     * The object-level element inside a command verb, carrying its own
     * namespace and schemaLocation the way the registry's examples do.
     */
    private function object(\DOMNode $parent, string $qualified, string $namespace, string $schema): \DOMElement {
        $node = $this->element($parent, $qualified, null, $namespace);
        $node->setAttributeNS(self::XSI, 'xsi:schemaLocation', $namespace . ' ' . $schema);
        return $node;
    }

    /**
     * clTRID closes a command, and the document.
     */
    private function finish(\DOMElement $command, string $clTRID): string {
        $this->element($command, 'clTRID', $clTRID, self::EPP);
        return $this->dom->saveXML();
    }

    // ---------------------------------------------------------------
    // session
    // ---------------------------------------------------------------

    public static function hello(): string {
        $builder = new self(false);
        $builder->element($builder->root, 'hello', null, self::EPP);
        return $builder->dom->saveXML();
    }

    /**
     * @param string $newPW a new password, changing it as part of the login
     * @param bool $dnssec announce the DNSSEC extensions
     */
    public static function login(string $username, string $password, string $lang, string $newPW = '', bool $dnssec = false): string {
        $builder = new self(false);
        $command = $builder->command();
        $login = $builder->element($command, 'login', null, self::EPP);

        $builder->element($login, 'clID', $username, self::EPP);
        $builder->element($login, 'pw', $password, self::EPP);
        if ($newPW !== '') {
            $builder->element($login, 'newPW', $newPW, self::EPP);
        }

        $options = $builder->element($login, 'options', null, self::EPP);
        $builder->element($options, 'version', '1.0', self::EPP);
        $builder->element($options, 'lang', $lang, self::EPP);

        $svcs = $builder->element($login, 'svcs', null, self::EPP);
        $builder->element($svcs, 'objURI', self::CONTACT, self::EPP);
        $builder->element($svcs, 'objURI', self::DOMAIN, self::EPP);

        $extension = $builder->element($svcs, 'svcExtension', null, self::EPP);
        foreach ([self::EXTEPP, self::EXTCON, self::EXTDOM, self::RGP] as $uri) {
            $builder->element($extension, 'extURI', $uri, self::EPP);
        }
        if ($dnssec) {
            foreach ([self::SECDNS, self::EXTSEC] as $uri) {
                $builder->element($extension, 'extURI', $uri, self::EPP);
            }
        }

        // login carries no clTRID
        return $builder->dom->saveXML();
    }

    public static function logout(string $clTRID): string {
        $builder = new self(false);
        $command = $builder->command();
        $builder->element($command, 'logout', null, self::EPP);
        return $builder->finish($command, $clTRID);
    }

    /**
     * @param string $type 'req' to read the next message, 'ack' to dequeue one
     * @param int|null $msgID which message to acknowledge
     */
    public static function poll(string $clTRID, string $type, ?int $msgID = null): string {
        $builder = new self(false);
        $command = $builder->command();

        $poll = $builder->element($command, 'poll', null, self::EPP);
        $poll->setAttribute('op', $type);
        if ($msgID !== null) {
            $poll->setAttribute('msgID', (string) $msgID);
        }

        return $builder->finish($command, $clTRID);
    }

    // ---------------------------------------------------------------
    // contact
    // ---------------------------------------------------------------

    /**
     * @param string[] $handles
     */
    public static function contactCheck(string $clTRID, array $handles): string {
        $builder = new self(false);
        $command = $builder->command();
        $check = $builder->element($command, 'check', null, self::EPP);

        // no schemaLocation here, matching what the registry's own examples
        // show
        $contact = $builder->element($check, 'contact:check', null, self::CONTACT);
        foreach ($handles as $handle) {
            $builder->element($contact, 'contact:id', (string) $handle, self::CONTACT);
        }

        return $builder->finish($command, $clTRID);
    }

    public static function contactInfo(string $clTRID, string $handle): string {
        $builder = new self();
        $command = $builder->command();
        $info = $builder->element($command, 'info', null, self::EPP);

        $contact = $builder->object($info, 'contact:info', self::CONTACT, 'contact-1.0.xsd');
        $builder->element($contact, 'contact:id', $handle, self::CONTACT);

        return $builder->finish($command, $clTRID);
    }

    public static function contactDelete(string $clTRID, string $handle): string {
        $builder = new self();
        $command = $builder->command();
        $delete = $builder->element($command, 'delete', null, self::EPP);

        $contact = $builder->object($delete, 'contact:delete', self::CONTACT, 'contact-1.0.xsd');
        $builder->element($contact, 'contact:id', $handle, self::CONTACT);

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param string $operation 'add' or 'rem'
     */
    public static function contactStatus(string $clTRID, string $handle, string $operation, string $state): string {
        $builder = new self();
        $command = $builder->command();
        $update = $builder->element($command, 'update', null, self::EPP);

        $contact = $builder->object($update, 'contact:update', self::CONTACT, 'contact-1.0.xsd');
        $builder->element($contact, 'contact:id', $handle, self::CONTACT);

        $wrapper = $builder->element($contact, 'contact:' . $operation, null, self::CONTACT);
        $status = $builder->element($wrapper, 'contact:status', null, self::CONTACT);
        $status->setAttribute('s', $state);

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param array $data id, name, org, street[], city, sp, pc, cc, voice, email,
     *              authinfo, consentForPublishing, nationalityCode, entityType,
     *              regCode, schoolCode
     */
    public static function contactCreate(string $clTRID, array $data): string {
        $builder = new self();
        $command = $builder->command();
        $create = $builder->element($command, 'create', null, self::EPP);

        $contact = $builder->object($create, 'contact:create', self::CONTACT, 'contact-1.0.xsd');
        $builder->element($contact, 'contact:id', $data['id'], self::CONTACT);

        $postal = $builder->element($contact, 'contact:postalInfo', null, self::CONTACT);
        $postal->setAttribute('type', 'loc');
        $builder->element($postal, 'contact:name', $data['name'], self::CONTACT);
        $builder->element($postal, 'contact:org', $data['org'], self::CONTACT);

        $addr = $builder->element($postal, 'contact:addr', null, self::CONTACT);
        foreach ($data['street'] as $street) {
            if ((string) $street !== '') {
                $builder->element($addr, 'contact:street', (string) $street, self::CONTACT);
            }
        }
        $builder->element($addr, 'contact:city', $data['city'], self::CONTACT);
        $builder->element($addr, 'contact:sp', $data['sp'], self::CONTACT);
        $builder->element($addr, 'contact:pc', $data['pc'], self::CONTACT);
        $builder->element($addr, 'contact:cc', $data['cc'], self::CONTACT);

        $builder->element($contact, 'contact:voice', $data['voice'], self::CONTACT);
        $builder->element($contact, 'contact:email', $data['email'], self::CONTACT);

        $auth = $builder->element($contact, 'contact:authInfo', null, self::CONTACT);
        $builder->element($auth, 'contact:pw', $data['authinfo'], self::CONTACT);

        // extcon: the .it-specific registrant details
        $extension = $builder->element($command, 'extension', null, self::EPP);
        $extcon = $builder->object($extension, 'extcon:create', self::EXTCON, 'extcon-1.0.xsd');
        $builder->element($extcon, 'extcon:consentForPublishing', (string) $data['consentForPublishing'], self::EXTCON);

        $registrant = $builder->element($extcon, 'extcon:registrant', null, self::EXTCON);
        $builder->element($registrant, 'extcon:nationalityCode', $data['nationalityCode'], self::EXTCON);
        $builder->element($registrant, 'extcon:entityType', (string) $data['entityType'], self::EXTCON);
        $builder->element($registrant, 'extcon:regCode', $data['regCode'], self::EXTCON);
        if ((string) $data['schoolCode'] !== '') {
            $builder->element($registrant, 'extcon:schoolCode', $data['schoolCode'], self::EXTCON);
        }

        return $builder->finish($command, $clTRID);
    }

    /**
     * name, org and fax are never sent: the registry fixes the first two at
     * creation and no longer supports fax.
     *
     * @param array $addr street/city/sp/pc/cc pairs that changed
     * @param array $contactFields voice/email pairs that changed
     * @param array $registrant extcon registrant fields that changed
     */
    public static function contactUpdate(
        string $clTRID,
        string $handle,
        array $addr,
        array $contactFields,
        string $authinfo,
        int|string $consentForPublishing,
        array $registrant
    ): string {
        $builder = new self();
        $command = $builder->command();
        $update = $builder->element($command, 'update', null, self::EPP);

        $contact = $builder->object($update, 'contact:update', self::CONTACT, 'contact-1.0.xsd');
        $builder->element($contact, 'contact:id', $handle, self::CONTACT);

        $hasChange = $addr !== [] || $contactFields !== [] || $authinfo !== '';
        if ($hasChange) {
            $chg = $builder->element($contact, 'contact:chg', null, self::CONTACT);

            if ($addr !== []) {
                $postal = $builder->element($chg, 'contact:postalInfo', null, self::CONTACT);
                $postal->setAttribute('type', 'loc');

                $address = $builder->element($postal, 'contact:addr', null, self::CONTACT);
                foreach ($addr as $field) {
                    if ((string) $field['value'] !== '') {
                        $builder->element($address, 'contact:' . $field['name'], (string) $field['value'], self::CONTACT);
                    }
                }
            }

            foreach ($contactFields as $field) {
                $value = (string) $field['value'];
                if ($value !== '' && in_array($field['name'], ['voice', 'email'], true)) {
                    $builder->element($chg, 'contact:' . $field['name'], $value, self::CONTACT);
                }
            }

            // authInfo belongs inside <chg>, last before <disclose>
            // (contact-1.0.xsd chgType)
            if ($authinfo !== '') {
                $auth = $builder->element($chg, 'contact:authInfo', null, self::CONTACT);
                $builder->element($auth, 'contact:pw', $authinfo, self::CONTACT);
            }
        }

        $consentChanged = $consentForPublishing === 0 || $consentForPublishing === 1;
        if ($consentChanged || $registrant !== []) {
            $extension = $builder->element($command, 'extension', null, self::EPP);
            $extcon = $builder->object($extension, 'extcon:update', self::EXTCON, 'extcon-1.0.xsd');

            if ($consentChanged) {
                $builder->element($extcon, 'extcon:consentForPublishing', (string) $consentForPublishing, self::EXTCON);
            }
            if ($registrant !== []) {
                $node = $builder->element($extcon, 'extcon:registrant', null, self::EXTCON);
                foreach (['nationalityCode', 'entityType', 'regCode', 'schoolCode'] as $field) {
                    if (isset($registrant[$field])) {
                        $builder->element($node, 'extcon:' . $field, (string) $registrant[$field], self::EXTCON);
                    }
                }
            }
        }

        return $builder->finish($command, $clTRID);
    }

    // ---------------------------------------------------------------
    // domain
    // ---------------------------------------------------------------

    /**
     * @param string[] $domains
     */
    public static function domainCheck(string $clTRID, array $domains): string {
        $builder = new self();
        $command = $builder->command();
        $check = $builder->element($command, 'check', null, self::EPP);

        $domain = $builder->object($check, 'domain:check', self::DOMAIN, 'domain-1.0.xsd');
        foreach ($domains as $name) {
            $builder->element($domain, 'domain:name', (string) $name, self::DOMAIN);
        }

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param string $infContacts 'all', 'registrant' or ''
     */
    public static function domainInfo(string $clTRID, string $name, string $authinfo = '', string $infContacts = ''): string {
        $builder = new self();
        $command = $builder->command();
        $info = $builder->element($command, 'info', null, self::EPP);

        $domain = $builder->object($info, 'domain:info', self::DOMAIN, 'domain-1.0.xsd');
        $nameNode = $builder->element($domain, 'domain:name', $name, self::DOMAIN);
        $nameNode->setAttribute('hosts', 'all');

        if ($authinfo !== '') {
            $auth = $builder->element($domain, 'domain:authInfo', null, self::DOMAIN);
            $builder->element($auth, 'domain:pw', $authinfo, self::DOMAIN);
        }

        // linked-contact detail is only available with the authinfo
        if ($authinfo !== '' && in_array($infContacts, ['all', 'registrant'], true)) {
            $extension = $builder->element($command, 'extension', null, self::EPP);
            $node = $builder->object($extension, 'extdom:infContacts', self::EXTDOM, 'extdom-2.0.xsd');
            $node->setAttribute('op', $infContacts);
        }

        return $builder->finish($command, $clTRID);
    }

    public static function domainDelete(string $clTRID, string $name): string {
        $builder = new self();
        $command = $builder->command();
        $delete = $builder->element($command, 'delete', null, self::EPP);

        $domain = $builder->object($delete, 'domain:delete', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        return $builder->finish($command, $clTRID);
    }

    public static function domainStatus(string $clTRID, string $name, string $operation, string $state): string {
        $builder = new self();
        $command = $builder->command();
        $update = $builder->element($command, 'update', null, self::EPP);

        $domain = $builder->object($update, 'domain:update', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        $wrapper = $builder->element($domain, 'domain:' . $operation, null, self::DOMAIN);
        $status = $builder->element($wrapper, 'domain:status', null, self::DOMAIN);
        $status->setAttribute('s', $state);

        return $builder->finish($command, $clTRID);
    }

    public static function domainRestore(string $clTRID, string $name): string {
        $builder = new self();
        $command = $builder->command();
        $update = $builder->element($command, 'update', null, self::EPP);

        $domain = $builder->object($update, 'domain:update', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);
        $builder->element($domain, 'domain:chg', null, self::DOMAIN);

        $extension = $builder->element($command, 'extension', null, self::EPP);
        $rgp = $builder->object($extension, 'rgp:update', self::RGP, 'rgp-1.0.xsd');
        $restore = $builder->element($rgp, 'rgp:restore', null, self::RGP);
        $restore->setAttribute('op', 'request');

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param array $nameservers name => ['name' => ..., 'ip' => [['type','address'], ...]]
     * @param array $dnssec digest => ['keytag','algorithm','digesttype']
     */
    public static function domainCreate(
        string $clTRID,
        string $name,
        array $nameservers,
        string $registrant,
        string $authinfo,
        array $dnssec = []
    ): string {
        $builder = new self();
        $command = $builder->command();
        $create = $builder->element($command, 'create', null, self::EPP);

        $domain = $builder->object($create, 'domain:create', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        $period = $builder->element($domain, 'domain:period', '1', self::DOMAIN);
        $period->setAttribute('unit', 'y');

        $ns = $builder->element($domain, 'domain:ns', null, self::DOMAIN);
        foreach ($nameservers as $server) {
            $builder->hostAttr($ns, $server, true);
        }

        $builder->element($domain, 'domain:registrant', $registrant, self::DOMAIN);

        $auth = $builder->element($domain, 'domain:authInfo', null, self::DOMAIN);
        $builder->element($auth, 'domain:pw', $authinfo, self::DOMAIN);

        if ($dnssec !== []) {
            $extension = $builder->element($command, 'extension', null, self::EPP);
            $secDNS = $builder->element($extension, 'secDNS:create', null, self::SECDNS);
            foreach ($dnssec as $digest => $key) {
                $builder->dsData($secDNS, (string) $digest, $key);
            }
        }

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param array $add ns to add
     * @param array $remove ns to remove
     * @param array $change registrant/authinfo
     * @param array $dnssecAdd digest => key info
     * @param array $dnssecRemove digest => key info
     */
    public static function domainUpdate(
        string $clTRID,
        string $name,
        array $add,
        array $remove,
        array $change,
        array $dnssecAdd = [],
        array $dnssecRemove = []
    ): string {
        $builder = new self();
        $command = $builder->command();
        $update = $builder->element($command, 'update', null, self::EPP);

        $domain = $builder->object($update, 'domain:update', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        foreach ([['add', $add], ['rem', $remove]] as [$verb, $set]) {
            $nameservers = $set['ns'] ?? [];

            if ($nameservers === []) {
                continue;
            }

            $node = $builder->element($domain, 'domain:' . $verb, null, self::DOMAIN);

            if ($nameservers !== []) {
                $ns = $builder->element($node, 'domain:ns', null, self::DOMAIN);
                foreach ($nameservers as $server) {
                    // a removal names the host only; the addresses are not part
                    // of what is being taken away
                    $builder->hostAttr($ns, $server, $verb === 'add');
                }
            }
        }

        $registrant = (string) ($change['registrant'] ?? '');
        $authinfo = (string) ($change['authinfo'] ?? '');
        if ($registrant !== '' || $authinfo !== '') {
            $chg = $builder->element($domain, 'domain:chg', null, self::DOMAIN);
            if ($registrant !== '') {
                $builder->element($chg, 'domain:registrant', $registrant, self::DOMAIN);
            }
            if ($authinfo !== '') {
                $auth = $builder->element($chg, 'domain:authInfo', null, self::DOMAIN);
                $builder->element($auth, 'domain:pw', $authinfo, self::DOMAIN);
            }
        }

        if ($dnssecAdd !== [] || $dnssecRemove !== []) {
            $extension = $builder->element($command, 'extension', null, self::EPP);
            $secDNS = $builder->element($extension, 'secDNS:update', null, self::SECDNS);

            // removals first, as the template had them: the registry applies
            // the element order it is given
            if ($dnssecRemove !== []) {
                $rem = $builder->element($secDNS, 'secDNS:rem', null, self::SECDNS);
                foreach ($dnssecRemove as $digest => $key) {
                    $builder->dsData($rem, (string) $digest, $key);
                }
            }
            if ($dnssecAdd !== []) {
                $addNode = $builder->element($secDNS, 'secDNS:add', null, self::SECDNS);
                foreach ($dnssecAdd as $digest => $key) {
                    $builder->dsData($addNode, (string) $digest, $key);
                }
            }
        }

        return $builder->finish($command, $clTRID);
    }

    /**
     * @param string $operation request, approve, reject or cancel
     */
    public static function domainTransfer(
        string $clTRID,
        string $name,
        string $authinfo,
        string $operation = 'request',
        string $newRegistrant = '',
        string $newAuthinfo = ''
    ): string {
        $builder = new self();
        $command = $builder->command();

        $transfer = $builder->element($command, 'transfer', null, self::EPP);
        $transfer->setAttribute('op', $operation);

        $domain = $builder->object($transfer, 'domain:transfer', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        $auth = $builder->element($domain, 'domain:authInfo', null, self::DOMAIN);
        $builder->element($auth, 'domain:pw', $authinfo, self::DOMAIN);

        // a trade is a transfer that also changes the registrant
        if ($newRegistrant !== '') {
            $extension = $builder->element($command, 'extension', null, self::EPP);
            $trade = $builder->object($extension, 'extdom:trade', self::EXTDOM, 'extdom-2.0.xsd');
            $node = $builder->element($trade, 'extdom:transferTrade', null, self::EXTDOM);
            $builder->element($node, 'extdom:newRegistrant', $newRegistrant, self::EXTDOM);
            $newAuth = $builder->element($node, 'extdom:newAuthInfo', null, self::EXTDOM);
            $builder->element($newAuth, 'extdom:pw', $newAuthinfo, self::EXTDOM);
        }

        return $builder->finish($command, $clTRID);
    }

    public static function domainTransferQuery(string $clTRID, string $name, string $authinfo = ''): string {
        $builder = new self();
        $command = $builder->command();

        $transfer = $builder->element($command, 'transfer', null, self::EPP);
        $transfer->setAttribute('op', 'query');

        $domain = $builder->object($transfer, 'domain:transfer', self::DOMAIN, 'domain-1.0.xsd');
        $builder->element($domain, 'domain:name', $name, self::DOMAIN);

        if ($authinfo !== '') {
            $auth = $builder->element($domain, 'domain:authInfo', null, self::DOMAIN);
            $builder->element($auth, 'domain:pw', $authinfo, self::DOMAIN);
        }

        return $builder->finish($command, $clTRID);
    }

    // ---------------------------------------------------------------
    // shared fragments
    // ---------------------------------------------------------------

    /**
     * @param array $server ['name' => ..., 'ip' => [['type' => 'v4', 'address' => ...], ...]]
     * @param bool $withAddresses include the glue addresses
     */
    private function hostAttr(\DOMNode $parent, array $server, bool $withAddresses): void {
        $host = $this->element($parent, 'domain:hostAttr', null, self::DOMAIN);
        $this->element($host, 'domain:hostName', (string) $server['name'], self::DOMAIN);

        if ( ! $withAddresses) {
            return;
        }
        foreach ($server['ip'] ?? [] as $address) {
            $node = $this->element($host, 'domain:hostAddr', (string) $address['address'], self::DOMAIN);
            $node->setAttribute('ip', (string) $address['type']);
        }
    }

    /**
     * @param array $key ['keytag' => ..., 'algorithm' => ..., 'digesttype' => ...]
     */
    private function dsData(\DOMNode $parent, string $digest, array $key): void {
        $ds = $this->element($parent, 'secDNS:dsData', null, self::SECDNS);
        $this->element($ds, 'secDNS:keyTag', (string) $key['keytag'], self::SECDNS);
        $this->element($ds, 'secDNS:alg', (string) $key['algorithm'], self::SECDNS);
        $this->element($ds, 'secDNS:digestType', (string) $key['digesttype'], self::SECDNS);
        $this->element($ds, 'secDNS:digest', $digest, self::SECDNS);
    }
}
