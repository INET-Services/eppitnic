<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Client;
use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeMailer;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * Recovering from a password rotation that did not finish. The dangerous moment
 * is between the registry accepting the new password and this installation
 * recording it, so the candidate is now written first and both are on disk.
 */
final class PasswordRotationTest extends EppTestCase
{
    /** @var FakeTransport[] one per Client the code under test asked for */
    private array $transports = [];

    /** @var string[] the passwords each login was attempted with, in order */
    private array $attempted = [];

    /** what the registry will accept; anything else is refused */
    private string $livePassword = 'old-password';

    protected function setUp(): void {
        parent::setUp();

        // Config::set() writes through, so this needs a database -- in-memory
        // SQLite, the ordering of the writes being what is tested. RedBean
        // holds its connection globally, so it is made once and the tables
        // rebuilt
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'messages', 'history', 'users'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, data TEXT, archived_time TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        R::exec("INSERT INTO users (id, username) VALUES (3, 'operator')");

        Config::loadForTesting(self::SETTINGS);
        $this->seedEpp(['password' => 'old-password']);
        $this->seedSmtp(['enabled' => true, 'recipient' => 'noc@example.it']);
        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));

        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $this->transports[] = $transport;

            // The registry's answer depends on the credential offered, which
            // is the whole point: a login is how the caller finds out which
            // password is live. Queue enough for hello + login + logout.
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request): string {
                $this->attempted[] = self::credentialIn($request);
                return self::credentialIn($request) === $this->livePassword
                    ? CommandCatalog::OK_RESPONSE
                    : self::loginRefused();
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);

            return $client;
        });
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        Notifier::useMailerFactory(null);
        parent::tearDown();
    }

    /**
     * The registry took the new password, and the process died before it was
     * recorded. The candidate is the live credential and must be promoted.
     */
    public function testCandidateIsPromotedWhenTheRegistryAcceptsIt(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'new-password';

        RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('new-password', $epp['password'], 'the live password was not promoted');
        $this->assertArrayNotHasKey('pendingPassword', $epp, 'the candidate was left behind');
    }

    /**
     * The process died before the registry saw the change. The old password is
     * still live and the candidate is worthless.
     */
    public function testCandidateIsDiscardedWhenTheOldPasswordStillWorks(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'old-password';

        RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('old-password', $epp['password']);
        $this->assertArrayNotHasKey('pendingPassword', $epp);
    }

    /**
     * Neither works -- the account is locked, the IP is not authorised,
     * something else entirely. Discarding the candidate here would throw away
     * what may well be the live credential, so it is kept.
     */
    public function testCandidateIsKeptWhenNeitherPasswordWorks(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'something-else';

        $log = RegistryPasswordChange::reconcile();

        $epp = Config::get('epp');
        $this->assertSame('new-password', $epp['pendingPassword'], 'the candidate was discarded');
        $this->assertStringContainsString('CRITICAL', implode("\n", $log));
    }

    /**
     * The candidate is tried first. If the old password were tried first and
     * the rotation had in fact landed, the probe would fail and the candidate
     * be discarded -- locking the installation out with the answer on disk.
     */
    public function testTheCandidateIsTriedBeforeTheStoredPassword(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'new-password';

        RegistryPasswordChange::reconcile();

        $this->assertSame(['new-password'], $this->attempted, 'the stored password should not have been tried');
    }

    /**
     * Nothing to settle is not an error, and costs no registry round trip.
     */
    public function testNoCandidateIsANoOp(): void {
        $this->seedEpp(['password' => 'old-password']);

        $this->assertSame([], RegistryPasswordChange::reconcile());
        $this->assertSame([], $this->attempted, 'the registry was contacted with nothing to reconcile');
    }

    /**
     * The candidate reaches the settings table before it reaches the registry.
     * This is the property the whole design rests on: if the write came second,
     * a crash in between would leave no record of it anywhere.
     */
    public function testTheCandidateIsPersistedBeforeItIsSent(): void {
        $this->seedEpp(['password' => 'old-password']);

        $seenAtLogin = null;
        RegistryPasswordChange::useClientFactory(function () use (&$seenAtLogin): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request) use (&$seenAtLogin): string {
                // read the table, not the cache: what survives a crash here is
                // what has been written, not what the process happens to hold
                $stored = json_decode(R::getCell("SELECT `value` FROM settings WHERE `key` = 'epp'"), true);
                $seenAtLogin = $stored['pendingPassword'] ?? null;
                return CommandCatalog::OK_RESPONSE;
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);
            return $client;
        });

        R::exec("INSERT INTO messages (type, data, archived_time) VALUES ('passwdReminder', '2026-09-01', NULL)");

        RegistryPasswordChange::rotateOnReminder();

        $this->assertNotNull($seenAtLogin, 'no candidate was on disk when the change was sent');
        $this->assertSame(Config::get('epp')['password'], $seenAtLogin, 'a different password was sent than was recorded');
    }

    /**
     * A login that gets no parsable answer may still have landed: the
     * candidate stays for reconcile() instead of being thrown away.
     */
    public function testAnUnansweredLoginKeepsTheCandidate(): void {
        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queue('');
            return $client;
        });

        $outcome = RegistryPasswordChange::apply('new-password');

        $this->assertFalse($outcome['ok']);
        $this->assertSame('unknown', $outcome['stage']);
        $this->assertStringContainsString('unknown', $outcome['error']);
        $this->assertSame('new-password', Config::get('epp')['pendingPassword']);
    }

    public function testARefusedLoginClearsTheCandidate(): void {
        $this->livePassword = 'something-else';

        $outcome = RegistryPasswordChange::apply('new-password');

        $this->assertSame('registry', $outcome['stage']);
        $this->assertArrayNotHasKey('pendingPassword', Config::get('epp'));
    }

    /**
     * apply()'s second argument is what every operator-driven change must pass:
     * `lastPasswordUpdate` is the once-per-24h guard rotateOnReminder() reads,
     * so omitting it lets a rotation follow a manual change moments later.
     */
    public function testApplyStampsTheAttemptOnlyWhenAskedTo(): void {
        $this->seedEpp(['password' => 'old-password', 'lastPasswordUpdate' => 0]);
        $this->livePassword = 'first-password';

        RegistryPasswordChange::apply('first-password');
        $this->assertSame(0, Config::get('epp')['lastPasswordUpdate']);

        $this->livePassword = 'second-password';
        RegistryPasswordChange::apply('second-password', true);
        $this->assertGreaterThan(0, Config::get('epp')['lastPasswordUpdate']);
    }

    /**
     * adopt() -- config epp-password --force. Verified with a real login,
     * never a registry change: a password known good some other way still
     * has to prove it here before this installation trusts it.
     */
    public function testAdoptStoresAVerifiedPasswordWithoutChangingIt(): void {
        $this->seedEpp(['password' => 'old-password']);
        $this->livePassword = 'known-good';

        $outcome = RegistryPasswordChange::adopt('known-good');

        $this->assertTrue($outcome['ok']);
        $this->assertSame('known-good', Config::get('epp')['password']);
        // adopt() only ever logs in with the password it was given -- never
        // a <newPW>, which is what would make this a change rather than a check
        $this->assertSame(['known-good'], $this->attempted);
    }

    public function testAdoptStampsLastPasswordUpdate(): void {
        $this->seedEpp(['password' => 'old-password', 'lastPasswordUpdate' => 0]);
        $this->livePassword = 'known-good';

        RegistryPasswordChange::adopt('known-good');

        $this->assertGreaterThan(0, Config::get('epp')['lastPasswordUpdate']);
    }

    public function testAdoptClearsAStalePendingPassword(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'abandoned-candidate']);
        $this->livePassword = 'known-good';

        RegistryPasswordChange::adopt('known-good');

        $this->assertArrayNotHasKey('pendingPassword', Config::get('epp'));
    }

    public function testAdoptRefusesAndChangesNothingWhenTheRegistryDisagrees(): void {
        $this->seedEpp(['password' => 'old-password']);
        $this->livePassword = 'old-password';

        $outcome = RegistryPasswordChange::adopt('not-the-real-password');

        $this->assertFalse($outcome['ok']);
        $this->assertSame('old-password', Config::get('epp')['password'], 'adopt() changed the password despite a refused login');
    }

    public function testAdoptClassifiesLoginRefusals(): void {
        $cases = [
            ['2200', '', 'credentials'],
            ['2400', '6005', 'credentials'],
            ['2400', '5052', 'refused'],
            ['2501', '', 'refused'],
            ['0000', '', 'unreachable'],
            ['', '', 'unreachable'],
        ];
        foreach ($cases as [$code, $reason, $expected]) {
            $this->assertSame($expected, RegistryPasswordChange::classifyLoginRefusal($code, $reason), "{$code}/{$reason}");
        }
    }

    public function testAdoptDropsTheSharedSession(): void {
        $this->seedEpp(['password' => 'old-password']);
        Config::set('session_cookies', ['JSESSIONID' => 'old']);
        Config::set('session_timestamp', time());
        $this->livePassword = 'known-good';

        $this->assertTrue(RegistryPasswordChange::adopt('known-good')['ok']);

        $this->assertSame(0, \Eppitnic\Service\SessionState::timestamp());
        $this->assertSame([], \Eppitnic\Service\SessionState::cookies());
    }

    // ---------------------------------------------------------------
    // every rotation that lands: on record, and mailed to the system recipient
    // ---------------------------------------------------------------

    public function testAManualRotationIsRecordedForTheActingUser(): void {
        RegistryPasswordChange::apply('first-password', true, 'manual', 3);

        $row = R::getRow("SELECT * FROM history WHERE object = 'security'");
        $this->assertSame(['rotate', 3, 3], [$row['action'], (int) $row['user_id'], (int) $row['object_id']]);
        $data = json_decode($row['data'], true);
        $this->assertSame(['epp_password_rotated', 'manual'], [$data['event'], $data['trigger']]);
        $this->assertStringNotContainsString('first-password', $row['data'], 'the password itself is never recorded');
    }

    public function testARotationMailsTheSystemRecipientWithoutThePassword(): void {
        RegistryPasswordChange::apply('first-password', true, 'manual', 3);

        $this->assertCount(1, FakeMailer::$sent);
        $mail = FakeMailer::$sent[0];
        $this->assertSame('noc@example.it', $mail['to']);
        $this->assertSame('[eppitnic] registry password rotated', $mail['subject']);
        $this->assertStringContainsString('By:      operator', $mail['body']);
        $this->assertStringNotContainsString('first-password', $mail['body']);
    }

    public function testTheMailIgnoresRecipientModeAndFilters(): void {
        $this->seedSmtp(['enabled' => true, 'recipient' => 'noc@example.it', 'recipient_mode' => 'none', 'message_types' => ['creditMsgData']]);

        RegistryPasswordChange::apply('first-password', true, 'manual', 3);

        $this->assertCount(1, FakeMailer::$sent);
    }

    public function testNoMailWithoutARecipientOrWithMailOff(): void {
        $this->seedSmtp(['enabled' => true, 'recipient' => '']);
        RegistryPasswordChange::apply('first-password', true, 'manual', 3);

        $this->seedSmtp(['enabled' => false, 'recipient' => 'noc@example.it']);
        $this->livePassword = 'first-password';
        RegistryPasswordChange::apply('second-password', true, 'manual', 3);

        $this->assertSame([], FakeMailer::$sent);
        $this->assertSame(2, (int) R::getCell("SELECT COUNT(*) FROM history WHERE action = 'rotate'"), 'recorded all the same');
    }

    public function testTheAutomaticRotationIsRecordedWithoutAUser(): void {
        R::exec("INSERT INTO messages (type, data, archived_time) VALUES ('passwdReminder', '2026-09-01', NULL)");

        RegistryPasswordChange::rotateOnReminder();

        $row = R::getRow("SELECT * FROM history WHERE action = 'rotate'");
        $this->assertNull($row['user_id']);
        $this->assertSame('reminder', json_decode($row['data'], true)['trigger']);
        $this->assertStringContainsString('passwdReminder', FakeMailer::$sent[0]['body']);
    }

    public function testACompletedInterruptedRotationAndAnAdoptionAreRotationsToo(): void {
        $this->seedEpp(['password' => 'old-password', 'pendingPassword' => 'new-password']);
        $this->livePassword = 'new-password';
        RegistryPasswordChange::reconcile();

        $this->livePassword = 'known-good';
        RegistryPasswordChange::adopt('known-good', 3);

        $triggers = array_map(
            static fn($data) => json_decode($data, true)['trigger'],
            R::getCol("SELECT data FROM history WHERE action = 'rotate' ORDER BY id")
        );
        $this->assertSame(['reconciled', 'adopted'], $triggers);
    }

    public function testARefusedChangeIsNotARotation(): void {
        $this->livePassword = 'something-else';

        $this->assertFalse(RegistryPasswordChange::apply('first-password', true, 'manual', 3)['ok']);

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
        $this->assertSame([], FakeMailer::$sent);
    }

    /**
     * @param array<string, mixed> $overrides merged over mail-off defaults
     */
    private function seedSmtp(array $overrides): void {
        Config::set('smtp', $overrides + [
            'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
            'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
            'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides merged over the base epp setting
     */
    private function seedEpp(array $overrides): void {
        Config::set('epp', $overrides + self::SETTINGS['epp']);
    }

    /**
     * The password a login request offers as its credential (not <newPW>).
     */
    private static function credentialIn(string $request): string {
        $dom = new \DOMDocument();
        if ( ! @$dom->loadXML($request)) {
            return '';
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
        $pw = $xpath->query('//e:login/e:pw')->item(0);
        return $pw === null ? '' : $pw->textContent;
    }

    private static function loginRefused(): string {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="2200"><msg>Authentication error</msg></result>
                <trID><svTRID>x</svTRID></trID>
              </response>
            </epp>
            XML;
    }
}
