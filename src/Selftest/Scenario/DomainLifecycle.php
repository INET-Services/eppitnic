<?php

namespace Eppitnic\Selftest\Scenario;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\ContactFactory;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Scenario;

/**
 * A domain from registration to deletion, with two registrants -- a spare one
 * to swap the first for. The deletes at the end are attempts: those still on
 * the domain are deferred to `selftest reap`.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Scenario\DomainLifecycle
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DomainLifecycle implements Scenario
{
    /** every contact handle this scenario made, in the order it made them */
    private array $made = [];

    /**
     * What the domain carries right now, by role -- kept current through the
     * update and the registrant change, since only what is still on it at
     * deletion is held by the purge.
     *
     * @var array<string, string> role => handle
     */
    private array $onDomain = [];

    /**
     * @param string[] $nameservers at least two; the third, if given, is what
     *                 the update swaps the second for
     * @param string|null $domain register this name instead of a generated
     *                    one, so its zone can be prepared in advance
     * @param int $verificationWait seconds to leave the registry to check the
     *            delegation before reading it back; 0 not to wait
     */
    public function __construct(
        private readonly array $nameservers,
        private readonly ?string $domain = null,
        private readonly int $verificationWait = 0,
    ) {}

    public function name(): string {
        return 'Domain lifecycle';
    }

    public function execute(Run $run): void {
        $registrant = $this->contact($run, 'R', 1);

        if ($registrant === null) {
            $run->skip('domain create', '', 'the registrant a domain needs could not be created');
            return;
        }

        $name = $this->domain ?? $run->names->domain();

        if ($this->createIt($run, $name, $registrant)) {
            $this->awaitVerification($run, $name);
            $this->readItBack($run, $name, $registrant);
            $this->updateIt($run, $name);
            $this->changeRegistrant($run, $name);
            $this->deleteIt($run, $name);
        }

        $this->attemptContactCleanup($run);
    }

    // -----------------------------------------------------------------
    // the contacts it needs
    // -----------------------------------------------------------------

    /**
     * @return string|null the handle, or null if it could not be created
     */
    private function contact(Run $run, string $role, int $index): ?string {
        $handle = $run->names->handle($role, $index);

        $ok = $run->step('contact create (registrant)', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);
            ContactFactory::fill($contact, $handle);

            $run->ensure($contact->create(), $contact);
            $run->noteContact($handle, 'registrant');

            return 'entity type 2, with a registration code';
        });

        if ( ! $ok) {
            return null;
        }
        $this->made[] = $handle;

        return $handle;
    }

    // -----------------------------------------------------------------
    // create
    // -----------------------------------------------------------------

    private function createIt(Run $run, string $name, string $registrant): bool {
        $run->step('domain check', $name, function () use ($run, $name) {
            $domain = new Domain($run->nic);
            $answer = $domain->check($name);

            $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
            $run->expect($answer->available() === true, 'the name is already taken: ' . $answer->reason());

            return 'free';
        });

        $nameservers = array_slice($this->nameservers, 0, 2);

        $created = $run->step('domain create', $name, function () use ($run, $name, $registrant, $nameservers) {
            $domain = new Domain($run->nic);
            $domain->set('domain', $name);
            $domain->set('registrant', $registrant);
            foreach ($nameservers as $ns) {
                $domain->addNS($ns);
            }

            $run->ensure($domain->create(), $domain);
            $run->noteDomain($name);
            $this->onDomain = ['registrant' => $registrant];

            return 'authinfo ' . $domain->get('authinfo') . ', ns ' . implode(' ', $nameservers);
        });

        if ($created) {
            $run->step('domain check again', $name, function () use ($run, $name) {
                $domain = new Domain($run->nic);
                $answer = $domain->check($name);

                $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
                $run->expect($answer->available() === false, 'the name is still free after being registered');

                return 'taken, as it should be';
            });
        }

        return $created;
    }

    // -----------------------------------------------------------------
    // read it back
    // -----------------------------------------------------------------

    private function readItBack(Run $run, string $name, string $registrant): void {
        $expected = array_slice($this->nameservers, 0, 2);

        $run->step('domain info', $name, function () use ($run, $name, $registrant, $expected) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $run->expect(
                (string) $domain->get('registrant') === $registrant,
                "registrant came back as '" . $domain->get('registrant') . "'"
            );

            return 'registrant matches' . self::checkNameservers($run, $domain, $expected);
        });
    }

    // -----------------------------------------------------------------
    // update: a different nameserver
    // -----------------------------------------------------------------

    private function updateIt(Run $run, string $name): void {
        // The whole target set, not a remove/add pair: the registry reports no
        // nameserver until it passes DNS checks, so fetch() often finds none
        // and remNS/addNS against nothing leaves one -- refused as 9005
        if (count($this->nameservers) < 3) {
            $run->skip('domain update', $name, 'only two nameservers were given; a third is needed to swap one');
            return;
        }
        $target = [$this->nameservers[0], $this->nameservers[2]];

        $applied = $run->step('domain update', $name, function () use ($run, $name, $target) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            self::reconcileNameservers($domain, $target);

            $run->ensure($domain->update(), $domain);

            return 'nameservers now ' . implode(' ', $target);
        });

        if ( ! $applied) {
            return;
        }
        $this->awaitVerification($run, $name);

        $run->step('domain info after update', $name, function () use ($run, $name, $target) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            return 'nameservers swapped' . self::checkNameservers($run, $domain, $target);
        });
    }

    // -----------------------------------------------------------------
    // nameservers, which the registry may not admit to holding
    // -----------------------------------------------------------------

    /**
     * Let the registry check the delegation before asking what it holds. Only
     * worth it for a real domain: nic.it validates out of band, and a generated
     * name can never resolve anyway, so waiting on one buys nothing.
     */
    private function awaitVerification(Run $run, string $name): void {
        if ($this->verificationWait <= 0) {
            return;
        }

        $run->step('await dns verification', $name, function () {
            sleep($this->verificationWait);

            return 'gave the registry ' . $this->verificationWait
                . 's to check the delegation before reading it back';
        });
    }

    /**
     * Bring the domain's nameservers to exactly $target. A diff against fetch()
     * will not do: a delegation that does not resolve reads back empty, and
     * remove-one/add-one against that leaves one, under the registry's minimum.
     *
     * @param string[] $target what the domain should carry afterwards
     */
    private static function reconcileNameservers(Domain $domain, array $target): void {
        foreach (array_keys((array) $domain->get('ns')) as $held) {
            if ( ! in_array($held, $target, true)) {
                $domain->remNS($held);
            }
        }
        // addNS() is idempotent for a name already present with the same
        // addresses, so this neither duplicates nor dirties anything
        foreach ($target as $nameserver) {
            $domain->addNS($nameserver);
        }
    }

    /**
     * Check the nameservers the registry admits to holding. None is not a
     * failure -- it is the ordinary answer for a delegation that has not passed
     * its checks. Holding ones nobody asked for would be, and is still caught.
     *
     * @param string[] $expected what was asked for
     * @return string what to append to the step's note
     */
    private static function checkNameservers(Run $run, Domain $domain, array $expected): string {
        $held = array_keys((array) $domain->get('ns'));

        if ($held === []) {
            return '; nameservers not reported (the registry has not validated the delegation)';
        }

        sort($held);
        sort($expected);
        $run->expect($held === $expected, 'nameservers came back as ' . implode(' ', $held));

        return '; nameservers ' . implode(' ', $held);
    }

    // -----------------------------------------------------------------
    // registrant change
    // -----------------------------------------------------------------

    /**
     * Its own EPP command, and the registry refuses one that does not rotate
     * the authinfo with it -- so this also proves the new authinfo took.
     */
    private function changeRegistrant(Run $run, string $name): void {
        $registrant = $this->contact($run, 'R', 2);

        if ($registrant === null) {
            $run->skip('domain set-registrant', $name, 'the second registrant could not be created');
            return;
        }

        $authinfo = null;

        $applied = $run->step('domain set-registrant', $name, function () use ($run, $name, $registrant, &$authinfo) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $domain->set('registrant', $registrant);
            $domain->set('authinfo', $authinfo = $domain->authinfo());

            $run->ensure($domain->updateRegistrant(), $domain);
            $this->onDomain['registrant'] = $registrant;

            return "registrant is now {$registrant}, authinfo rotated";
        });

        if ( ! $applied) {
            return;
        }

        $run->step('domain info after set-registrant', $name, function () use ($run, $name, $registrant, &$authinfo) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $run->expect(
                (string) $domain->get('registrant') === $registrant,
                "registrant came back as '" . $domain->get('registrant') . "'"
            );
            $run->expect(
                (string) $domain->get('authinfo') === $authinfo,
                'the rotated authinfo is not what the registry holds'
            );

            return 'the domain belongs to the second registrant';
        });
    }

    // -----------------------------------------------------------------
    // delete, and the cleanup that cannot finish today
    // -----------------------------------------------------------------

    private function deleteIt(Run $run, string $name): void {
        $run->step('domain delete', $name, function () use ($run, $name) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->delete($name), $domain);
            $run->forgetDomain($name);

            // whatever it was carrying is now held until the registry purges
            // it; everything else this scenario made is free straight away
            $run->blockContacts(array_values($this->onDomain));

            return 'accepted; the registry now holds it in pendingDelete';
        });
    }

    /**
     * Try to delete every contact this scenario made. Refusal is expected --
     * each stays attached until the domain is purged -- but one that succeeds
     * says the registry changed in a way this code assumes it has not.
     */
    private function attemptContactCleanup(Run $run): void {
        $held = array_values($this->onDomain);

        foreach ($this->made as $handle) {
            $why = in_array($handle, $held, true)
                ? 'still linked to the deleted domain; `selftest reap` clears it once the domain is purged'
                : 'not deleted; `selftest reap` will retry it';

            $run->deferrable(
                'contact delete',
                $handle,
                $why,
                function () use ($run, $handle) {
                    $contact = new Contact($run->nic);
                    $run->ensure($contact->delete($handle), $contact);
                    $run->forgetContact($handle);

                    return 'deleted -- it was already free';
                }
            );
        }
    }
}
