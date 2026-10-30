<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Epp\Session;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Feeds real registry responses, captured and anonymised by
 * tests/capture-responses.php, through the real parsers. Validating what we
 * send proves it well-formed; only these prove what nic.it sends back is
 * understood.
 */
final class ResponseParsingTest extends EppTestCase
{
    private const DIR = __DIR__ . '/../fixtures/responses';

    private static function fixture(string $name): string {
        $path = self::DIR . '/' . $name . '.xml';
        self::assertFileExists($path, "no captured fixture '{$name}' (run tests/capture-responses.php)");
        return file_get_contents($path);
    }

    public static function allFixtures(): array {
        $cases = [];
        foreach (glob(self::DIR . '/*.xml') ?: [] as $file) {
            $name = basename($file, '.xml');
            $cases[$name] = [$name];
        }
        return $cases;
    }

    /**
     * Every captured response must at minimum survive the generic result
     * handling in AbstractObject::ExecuteQuery() -- a code, a message, and no
     * warnings from walking a structure that isn't shaped as expected.
     */
    #[DataProvider('allFixtures')]
    public function testResponseIsUnderstoodGenerically(string $name): void {
        $this->transport->queue(self::fixture($name));

        $session = new Session($this->nic);
        $session->poll(false, 'req');

        $this->assertNotSame('', (string) $session->svCode, "'{$name}' produced no result code");
        $this->assertMatchesRegularExpression('/^[12]\d{3}$/', (string) $session->svCode);
    }

    public function testDomainInfoIsFullyParsed(): void {
        $this->transport->queue(self::fixture('domain-info-ok'));

        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-1.it'), 'fetch() rejected a real successful domain:info');

        $this->assertNotSame('', $domain->get('registrant'), 'registrant not parsed');
        $this->assertNotEmpty($domain->get('status'), 'status not parsed');
        $this->assertNotSame('', $domain->get('authinfo'), 'authInfo not parsed');
        $this->assertNotSame('', $domain->get('crDate'), 'crDate not parsed');
        $this->assertNotSame('', $domain->get('exDate'), 'exDate not parsed');

        // 'tech' is documented to always come back as an array keyed by handle,
        // even for a single technical contact -- the single-contact case used
        // to return a bare string and silently broke every uniform caller
        $this->assertIsArray($domain->get('tech'));
    }

    public function testContactInfoIsFullyParsed(): void {
        $this->transport->queue(self::fixture('contact-info-ok'));

        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('TESTHANDLE000001'), 'fetch() rejected a real successful contact:info');

        $this->assertNotSame('', $contact->get('name'), 'name not parsed');
        $this->assertNotSame('', $contact->get('email'), 'email not parsed');
        $this->assertNotSame('', $contact->get('countrycode'), 'cc not parsed');
        $this->assertNotEmpty($contact->get('status'), 'status not parsed');
    }

    /**
     * An error response must be reported as a failure with the registry's own
     * code and message preserved -- swallowing either leaves an operator
     * guessing why a command did not take effect.
     */
    public function testErrorResponsesSurfaceCodeAndMessage(): void {
        $this->transport->queue(self::fixture('contact-create-error'));

        $contact = new Contact($this->nic);
        $contact->set('handle', 'TESTHANDLE000001');
        $contact->set('entitytype', 1);
        $this->assertFalse($contact->create(), 'a 2xxx response was reported as success');

        $this->assertStringStartsWith('2', (string) $contact->svCode);
        $this->assertNotSame('', $contact->getError());
    }

    /**
     * Poll messages, one per document shape seen in the queue -- both
     * extdom-1.0 and 2.0 on purpose, nic.it having reused element names while
     * changing structure. Handling either alone silently drops the other.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     *         fixture => [fixture, expected type, carries a domain]
     */
    public static function pollFixtures(): array {
        return [
            'chgStatus 1.0'      => ['poll-chgStatusMsgData-extdom-1.0', 'chgStatusMsgData', true],
            'chgStatus 2.0'      => ['poll-chgStatusMsgData-extdom-2.0', 'chgStatusMsgData', true],
            'simpleMsg 1.0'      => ['poll-simpleMsgData-extdom-1.0', 'simpleMsgData', true],
            'simpleMsg 2.0'      => ['poll-simpleMsgData-extdom-2.0', 'simpleMsgData', true],
            'dnsError 1.0'       => ['poll-dnsErrorMsgData-extdom-1.0', 'dnsErrorMsgData', true],
            'dnsError 2.0'       => ['poll-dnsErrorMsgData-extdom-2.0', 'dnsErrorMsgData', true],
            'dnsWarning 2.0'     => ['poll-dnsWarningMsgData-extdom-2.0', 'dnsWarningMsgData', true],
            'delayedDebit 2.0'   => ['poll-delayedDebitAndRefundMsgData-extdom-2.0', 'delayedDebitAndRefundMsgData', true],
            'passwdReminder 1.0' => ['poll-passwdReminder-extepp-1.0', 'passwdReminder', false],
            'passwdReminder 2.0' => ['poll-passwdReminder-extepp-2.0', 'passwdReminder', false],
            'credit 1.0'         => ['poll-creditMsgData-extepp-1.0', 'creditMsgData', false],
            'credit 2.0'         => ['poll-creditMsgData-extepp-2.0', 'creditMsgData', false],
            'trade 1.0'          => ['poll-trade-extdom-1.0', 'Transfer', true],
            'trade 2.0'          => ['poll-trade-extdom-2.0', 'Transfer', true],
            'transfer pending'   => ['poll-transfer-pending', 'pendingTransfer', true],
            'transfer client'    => ['poll-transfer-clientApproved', 'clientApprovedTransfer', true],
            'transfer server'    => ['poll-transfer-serverApproved', 'serverApprovedTransfer', true],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}> the
     *         pollFixtures() about one particular domain
     */
    public static function domainPollFixtures(): array {
        return array_filter(self::pollFixtures(), fn($case) => $case[2]);
    }

    private function parsePoll(string $fixture): array {
        $this->transport->queue(self::fixture($fixture));

        $session = new Session($this->nic);
        $session->poll(false, 'req');

        return (new \ReflectionMethod(Session::class, 'parsePollReq'))->invoke($session);
    }

    #[DataProvider('pollFixtures')]
    public function testPollMessageIsClassified(string $fixture, string $expectedType, bool $hasDomain): void {
        $parsed = $this->parsePoll($fixture);

        $this->assertStringContainsString(
            $expectedType,
            $parsed['type'],
            "'{$fixture}' was classified as '{$parsed['type']}'"
        );
    }

    /**
     * A message about a specific domain must carry that domain through:
     * PollProcessor, the DNS-sync queue and the tasks view all key off
     * messages.domain, so one parsed without it is a failure nobody hears
     * about.
     */
    #[DataProvider('domainPollFixtures')]
    public function testDomainScopedPollMessagesCarryTheirDomain(string $fixture, string $expectedType, bool $hasDomain): void {
        $parsed = $this->parsePoll($fixture);

        $this->assertNotSame('', $parsed['domain'], "'{$fixture}' lost the domain it refers to");
        $this->assertStringEndsNotWith('.', $parsed['domain'], 'trailing dot not stripped');
    }

    /**
     * Every poll message must carry a non-empty human-readable summary --
     * it is what an operator reads in the poll-queue view.
     */
    #[DataProvider('pollFixtures')]
    public function testPollMessagesCarryASummary(string $fixture, string $expectedType, bool $hasDomain): void {
        $this->assertNotSame('', trim((string) $this->parsePoll($fixture)['data']), "'{$fixture}' produced no summary");
    }

    /**
     * The DNS check results are the reason 7.2 mattered: the per-test
     * outcomes are what tells an operator *why* a zone failed validation.
     */
    public function testDnsErrorSummaryIncludesFailedTests(): void {
        foreach (['poll-dnsErrorMsgData-extdom-1.0', 'poll-dnsErrorMsgData-extdom-2.0'] as $fixture) {
            $parsed = $this->parsePoll($fixture);
            $this->assertMatchesRegularExpression(
                '/\b(FAILED|SUCCEEDED|WARNING)\b/',
                $parsed['data'],
                "'{$fixture}' summary carries no test outcomes: {$parsed['data']}"
            );
        }
    }
}
