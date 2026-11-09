<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\PollDrainCommand;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `poll drain` reads each message with a plain `req` -- the registry refuses a
 * message id on it -- and names the id only when acknowledging.
 */
final class PollDrainCommandTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'msgqueue'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, type TEXT,
                 domain TEXT, ac_id TEXT, re_id TEXT, data TEXT)');
        R::exec('CREATE TABLE msgqueue (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, sv_code TEXT,
                 status INTEGER, sv_httpcode INTEGER, sv_httpheaders TEXT, sv_httpdata TEXT)');
    }

    private function drain(): int {
        $command = new PollDrainCommand(['--yes']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);     // login
        $this->sent->queue(CommandCatalog::POLL_RESPONSE);   // the count
        $this->sent->queue(CommandCatalog::POLL_RESPONSE);   // req
        $this->sent->queue(CommandCatalog::OK_RESPONSE);     // ack: the queue is empty
        $this->sent->queue(CommandCatalog::OK_RESPONSE);     // logout
        $this->nic->setTransport($this->sent);
        $command->useClient($this->nic);

        ob_start();
        try {
            return $command->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testTheRequestCarriesNoMessageIdButTheAcknowledgementDoes(): void {
        $this->assertSame(0, $this->drain());

        $polls = array_values(array_filter($this->sent->requests, static fn(string $r) => str_contains($r, '<poll ')));
        $this->assertCount(3, $polls);
        foreach ([0, 1] as $i) {
            $this->assertStringContainsString('<poll op="req"/>', $polls[$i]);
            $this->assertStringNotContainsString('msgID', $polls[$i]);
        }
        $this->assertMatchesRegularExpression('#<poll (?=[^>]*op="ack")(?=[^>]*msgID="4711")#', $polls[2]);
    }

    public function testTheMessageIsStored(): void {
        $this->drain();

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM messages'));
        $this->assertSame('chgStatusMsgData', R::getCell('SELECT type FROM messages'));
    }
}
