<?php

namespace Eppitnic\Selftest\Scenario;

use Eppitnic\Epp\Contact;
use Eppitnic\Selftest\ContactFactory;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Scenario;

/**
 * A contact never attached to anything, through its whole life -- the only
 * place a delete can be proved rather than attempted. Each read uses a *fresh*
 * object, or it proves only that this process remembers what it said.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Scenario\ContactLifecycle
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactLifecycle implements Scenario
{
    public function name(): string {
        return 'Contact lifecycle';
    }

    public function execute(Run $run): void {
        $handle = $run->names->handle('D');

        if ( ! $this->createIt($run, $handle)) {
            return;
        }

        $this->readItBack($run, $handle);
        $this->updateIt($run, $handle);
        $this->deleteIt($run, $handle);
    }

    // -----------------------------------------------------------------
    // create
    // -----------------------------------------------------------------

    private function createIt(Run $run, string $handle): bool {
        $run->step('contact check', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);
            $answer = $contact->check($handle);

            $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
            $run->expect($answer->available() === true, 'the handle is already taken: ' . $answer->reason());

            return 'free';
        });

        $created = $run->step('contact create', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);
            ContactFactory::fill($contact, $handle);

            $run->ensure($contact->create(), $contact);
            $run->noteContact($handle, 'disposable');

            return 'authinfo ' . $contact->get('authinfo');
        });

        if ($created) {
            // the create said it worked; the registry saying the handle is now
            // taken is a second, independent witness that it did
            $run->step('contact check again', $handle, function () use ($run, $handle) {
                $contact = new Contact($run->nic);
                $answer = $contact->check($handle);

                $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
                $run->expect($answer->available() === false, 'the handle is still free after being created');

                return 'taken, as it should be';
            });
        }

        return $created;
    }

    // -----------------------------------------------------------------
    // read, update, read again
    // -----------------------------------------------------------------

    private function readItBack(Run $run, string $handle): void {
        $run->step('contact info', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);
            $run->ensure($contact->fetch($handle), $contact);

            $run->expect(
                $contact->get('name') === 'Selftest ' . $handle,
                "name came back as '" . $contact->get('name') . "'"
            );
            $run->expect(
                strtolower((string) $contact->get('email')) === strtolower($handle) . '@example.it',
                "email came back as '" . $contact->get('email') . "'"
            );

            return 'fields match what was sent';
        });
    }

    private function updateIt(Run $run, string $handle): void {
        $changes = ContactFactory::changes($handle);

        $applied = $run->step('contact update', $handle, function () use ($run, $handle, $changes) {
            $contact = new Contact($run->nic);
            $run->ensure($contact->fetch($handle), $contact);

            foreach ($changes as $field => $value) {
                $contact->set($field, $value);
            }
            $run->ensure($contact->update(), $contact);

            return implode(', ', array_keys($changes));
        });

        if ( ! $applied) {
            return;
        }

        $run->step('contact info after update', $handle, function () use ($run, $handle, $changes) {
            $contact = new Contact($run->nic);
            $run->ensure($contact->fetch($handle), $contact);

            foreach ($changes as $field => $expected) {
                $run->expect(
                    (string) $contact->get($field) === $expected,
                    "{$field} came back as '" . $contact->get($field) . "', not '{$expected}'"
                );
            }

            return 'all ' . count($changes) . ' changes are in place';
        });
    }

    // -----------------------------------------------------------------
    // delete
    // -----------------------------------------------------------------

    private function deleteIt(Run $run, string $handle): void {
        $deleted = $run->step('contact delete', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);
            $run->ensure($contact->delete($handle), $contact);
            // nothing was ever linked to it, so this really is gone
            $run->forgetContact($handle);

            return 'accepted';
        });

        if ( ! $deleted) {
            return;
        }

        $run->step('contact gone', $handle, function () use ($run, $handle) {
            $contact = new Contact($run->nic);

            $run->expect(
                ! $contact->fetch($handle),
                'the registry still answers for a contact it accepted a delete for'
            );

            return 'no longer known to the registry';
        });
    }
}
