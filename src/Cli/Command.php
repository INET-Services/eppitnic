<?php

namespace Eppitnic\Cli;

use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Session;
use Eppitnic\Epp\Transport\DryRun;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\EppSession;
use Eppitnic\Setup\ConfigMissing;
use Eppitnic\Support\Idn;

/**
 * Base for every `bin/eppitnic` subcommand: option parsing, name lists, output
 * modes, and withSession() -- EppSession::run() with the CLI's error reporting
 * around it. A subclass declares what it takes and implements run().
 *
 * @category    Net
 * @package     Eppitnic\Cli
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
abstract class Command
{
    /** parsed long options, e.g. ['file' => 'domains.txt', 'json' => true] */
    protected array $options = [];

    /** positional arguments left after the options */
    protected array $arguments = [];

    /** every value of each valued option, for one given more than once */
    private array $values = [];

    /** collected output rows, for --json */
    private array $records = [];

    /** set only by useClient(), for tests */
    private ?Client $client = null;

    /** where warn() writes; swapped by the test suite to keep its output clean */
    private $errorStream = null;

    /** set while a --dry-run session is in flight */
    private ?DryRun $dryRun = null;

    /**
     * How many items of a bulk run failed -- see itemFailed(). Here rather
     * than a local, which fifteen commands passed into their withSession()
     * closure by reference purely to get it back out.
     */
    protected int $failures = 0;

    // ---------------------------------------------------------------
    // what a subcommand declares about itself
    // ---------------------------------------------------------------

    /**
     * @return string one line, shown in the command list and in --help
     */
    abstract public function describe(): string;

    /**
     * @return array<string, string> option name => help text. Append '=' to
     *                               the name for options that take a value.
     */
    public function options(): array {
        return [];
    }

    /**
     * @return string the positional part of the usage line, e.g. '<domain>...'
     */
    public function arguments(): string {
        return '';
    }

    /**
     * @return int an exit code; 0 for success
     */
    abstract public function run(): int;

    // ---------------------------------------------------------------
    // options global to every subcommand
    // ---------------------------------------------------------------

    /**
     * Options every subcommand accepts. --dry-run and --yes are not among
     * them: advertising those globally would promise behaviour a read does not
     * have, so a mutating command declares MUTATING_OPTIONS instead.
     */
    public const GLOBAL_OPTIONS = [
        'verbose' => 'include the full EPP request/response in errors, and record every command to the database',
        'json'    => 'print one JSON document describing the result',
        'jsonl'   => 'print one JSON object per line (JSON Lines), for bulk output',
        'user='   => 'act as this local user id (default 1)',
        'help'    => 'show this help',
    ];

    /**
     * What a command that changes something adds to its own options().
     */
    public const MUTATING_OPTIONS = [
        'dry-run' => 'print the EPP request that would be sent, and send nothing',
        'yes'     => 'do not ask for confirmation',
    ];

    /**
     * The same for a command that only writes locally -- the `config` verbs.
     * Its own const because MUTATING_OPTIONS's --dry-run promises to print the
     * EPP request, which a settings write has none of.
     */
    public const LOCAL_MUTATING_OPTIONS = [
        'dry-run' => 'print what would change, without writing it',
        'yes'     => 'do not ask for confirmation',
    ];

    /**
     * The --file option, whose text differs only in what the lines hold.
     *
     * @param string $noun what one line carries, e.g. 'domain names'
     * @param string $qualifier appended in brackets, for the one command where
     *                          --file only applies in a particular mode
     * @return array<string, string> to merge into options()
     */
    protected static function fileOption(string $noun, string $qualifier = ''): array {
        $text = "read {$noun} from this file, one per line";
        return ['file=' => $qualifier === '' ? $text : "{$text} ({$qualifier})"];
    }

    public const FORMAT_TEXT  = 'text';
    public const FORMAT_JSON  = 'json';
    public const FORMAT_JSONL = 'jsonl';
    public const FORMAT_CSV   = 'csv';

    /**
     * @param array $argv arguments after the verb, as given on the command line
     */
    public function __construct(array $argv = []) {
        $this->parse($argv);
    }

    /**
     * Long options only, as `--name` and `--name=value`. Not getopt(): it reads
     * $argv itself, so a subcommand cannot be tested without faking global
     * state, and it cannot tell a typo like `--ns1` from a positional argument.
     */
    private function parse(array $argv): void {
        $known = array_merge(self::GLOBAL_OPTIONS, $this->options());
        $valued = [];
        foreach (array_keys($known) as $name) {
            $valued[rtrim($name, '=')] = str_ends_with($name, '=');
        }

        foreach ($argv as $arg) {
            if ( ! str_starts_with($arg, '--')) {
                $this->arguments[] = $arg;
                continue;
            }

            $body = substr($arg, 2);
            $value = true;
            if (str_contains($body, '=')) {
                [$body, $value] = explode('=', $body, 2);
            }

            if ( ! array_key_exists($body, $valued)) {
                throw new UsageError("unknown option '--{$body}'");
            }
            if ($valued[$body] && $value === true) {
                throw new UsageError("option '--{$body}' needs a value, as --{$body}=...");
            }
            if ( ! $valued[$body] && $value !== true) {
                throw new UsageError("option '--{$body}' takes no value");
            }

            $this->options[$body] = $value;
            if ($valued[$body]) {
                $this->values[$body][] = $value;
            }
        }
    }

    // ---------------------------------------------------------------
    // reading options
    // ---------------------------------------------------------------

    protected function option(string $name, mixed $default = null): mixed {
        return $this->options[$name] ?? $default;
    }

    /** @return string[] each value of an option given any number of times */
    protected function optionValues(string $name): array {
        return $this->values[$name] ?? [];
    }

    protected function hasOption(string $name): bool {
        return array_key_exists($name, $this->options);
    }

    protected function isVerbose(): bool {
        return $this->hasOption('verbose');
    }

    /**
     * How results should be printed. --json is one document, for a command
     * answering about one thing; --jsonl is one object per line, greppable and
     * streamable for thousands. A command may default elsewhere (CSV, export).
     */
    protected function format(): string {
        if ($this->hasOption('json') && $this->hasOption('jsonl')) {
            throw new UsageError('--json and --jsonl are alternatives; give one or neither');
        }
        if ($this->hasOption('json')) {
            return self::FORMAT_JSON;
        }
        if ($this->hasOption('jsonl')) {
            return self::FORMAT_JSONL;
        }
        return $this->defaultFormat();
    }

    /**
     * @return string what this command prints when no format is asked for
     */
    protected function defaultFormat(): string {
        return self::FORMAT_TEXT;
    }

    /**
     * Whether output is for a program rather than a person -- in which case
     * the running commentary is suppressed so stdout stays parseable.
     */
    protected function isMachineReadable(): bool {
        return $this->format() !== self::FORMAT_TEXT;
    }

    protected function isJson(): bool {
        return $this->format() === self::FORMAT_JSON;
    }

    protected function userId(): int {
        return (int) $this->option('user', 1);
    }

    /**
     * What a command may touch: --user's own reseller's objects, or every
     * reseller's with $unrestricted (e.g. --all-resellers). History names
     * --user either way.
     */
    protected function scope(bool $unrestricted = false): Scope {
        $this->database();
        if ($unrestricted) {
            return Scope::operator($this->userId());
        }
        $resellerId = (int) \RedBeanPHP\R::getCell('SELECT reseller_id FROM users WHERE id = ?', [$this->userId()]);
        return new Scope($this->userId(), $resellerId ?: 1, 'user');
    }

    /**
     * The acting user (--user) with their real role, so the API's rules about
     * who may reach whom apply unchanged.
     */
    protected function actor(): Scope {
        $row = \RedBeanPHP\R::getRow('SELECT id, reseller_id, role FROM users WHERE id = ?', [$this->userId()]);
        if (empty($row)) {
            throw new UsageError("no user with id {$this->userId()} to act as (--user)");
        }
        return new Scope((int) $row['id'], (int) $row['reseller_id'], (string) $row['role']);
    }

    protected function isDryRun(): bool {
        return $this->hasOption('dry-run');
    }

    /**
     * Ask before doing something that cannot be undone. Answers yes without
     * asking under --yes or --dry-run, and no when stdin is not a terminal --
     * a cron job has nobody to answer, and blocking there protects nothing.
     *
     * @param string $question stated so that the consequence is visible
     * @return bool whether to proceed
     */
    protected function confirm(string $question): bool {
        if ($this->hasOption('yes') || $this->isDryRun()) {
            return true;
        }
        if ( ! stream_isatty(STDIN)) {
            $this->warn($question);
            $this->warn('Not confirmed: no terminal to ask at. Pass --yes to proceed.');
            return false;
        }

        fwrite(STDOUT, $question . ' [y/N] ');
        $answer = trim((string) fgets(STDIN));
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }

    /**
     * Ask for one value. On Command rather than in SetupCommand, its only
     * caller today, so a second interactive command need not re-implement it.
     *
     * @param string $label prompt text
     * @param string $default value used if the user just presses enter
     * @return string the entered value, or $default if left blank
     */
    protected function prompt(string $label, string $default = ''): string {
        $suffix = $default !== '' ? " [{$default}]" : '';
        fwrite(STDOUT, "{$label}{$suffix}: ");
        $line = trim((string) fgets(STDIN));
        return $line !== '' ? $line : $default;
    }

    /**
     * like prompt(), but best-effort hides the typed characters (via `stty
     * -echo`, when available -- not on Windows, or if `stty` isn't on
     * PATH -- falling back to a plain visible prompt otherwise)
     *
     * @param string $label prompt text
     * @return string the entered value
     */
    protected function promptHidden(string $label): string {
        $canHide = PHP_OS_FAMILY !== 'Windows' && trim((string) @shell_exec('command -v stty')) !== '';
        if ( ! $canHide) {
            return $this->prompt($label);
        }

        fwrite(STDOUT, "{$label}: ");
        shell_exec('stty -echo');
        try {
            $line = trim((string) fgets(STDIN));
        } finally {
            shell_exec('stty echo');
        }
        fwrite(STDOUT, "\n");
        return $line;
    }

    /** where readPasswordStdin() reads from; swapped by the test suite */
    private $input = null;

    public function useInputStream($stream): void {
        $this->input = $stream;
    }

    /** One line from stdin for --password-stdin, minus the line break. */
    protected function readPasswordStdin(): string {
        $line = fgets($this->input ?? STDIN);
        if ($line === false || rtrim($line, "\r\n") === '') {
            throw new UsageError('--password-stdin: no password on stdin');
        }
        return rtrim($line, "\r\n");
    }

    /** Asked twice, hidden; the answers must match. */
    protected function promptNewPassword(string $label = 'New password'): string {
        $password = $this->promptHidden($label);
        if ($password !== $this->promptHidden('Repeat the password')) {
            throw new UsageError('the passwords do not match');
        }
        return $password;
    }

    /**
     * Names given as positional arguments, or one per line from --file -- a
     * file is just another way of supplying the same list. Blank lines and
     * '#' comments are skipped.
     *
     * @return string[]
     */
    protected function names(): array {
        $names = $this->arguments;

        if ($file = $this->option('file')) {
            if ( ! is_readable($file)) {
                throw new UsageError("'{$file}' is not a readable file");
            }
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $names[] = $line;
                }
            }
        }

        return array_values(array_unique(array_filter($names, fn($n) => trim($n) !== '')));
    }

    /**
     * names() as domain names, each in the form Idn::domain() gives.
     *
     * @return string[]
     */
    protected function domainNames(): array {
        return self::domainList($this->names());
    }

    /**
     * @param string[] $names
     * @return string[] the domain names among them, canonical and unique
     */
    protected static function domainList(array $names): array {
        return array_values(array_unique(array_filter(array_map(Idn::domain(...), $names), 'strlen')));
    }

    // ---------------------------------------------------------------
    // database / registry session
    // ---------------------------------------------------------------

    /**
     * Ensure the database is connected and migrated. Only commands that work
     * locally need it: the rest connect as a side effect of Client's
     * constructor reading its settings.
     */
    protected function database(): void {
        Config::init();

        // as public/index.php does for the web tier; otherwise the CLI runs
        // in the host's (a container's: UTC) zone until a Client sets it
        $timezone = Config::all()['region']['timezone'] ?? null;
        if (is_string($timezone) && $timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Run $fn against a logged-in registry session -- the shared, kept-alive
     * one when `keepalive` is on and $override is not given (EppSession::run()
     * decides that from a fresh Client; a Client passed in here keeps
     * whatever $keepalive it already carries).
     *
     * @param callable $fn function(Client $nic, Session $session)
     * @param Client|null $override talk to this client instead of a default
     *                    one -- for the restore endpoint, which is a different
     *                    host. Ignored under --dry-run, which answers locally
     *                    and never joins the shared session.
     * @param bool $joinShared see EppSession::run()
     * @return mixed whatever $fn returns
     * @throws SessionError if the registry is unreachable or rejects the login
     */
    protected function withSession(callable $fn, ?Client $override = null, bool $joinShared = true): mixed {
        $client = $override ?? $this->client;

        if ($this->isDryRun()) {
            // a client of its own, answering locally: nothing reaches the
            // network, so this needs neither credentials nor connectivity
            $client = new Client();
            $client->setTransport($this->dryRun = new DryRun());
            // Explicit, though already the default: DryRun's canned "1000"
            // answers must never be mistaken for a real session and written
            // to SessionState -- the next real command would then skip
            // login and open against a session that was never authenticated.
            $client->keepalive = false;
        }

        try {
            $result = EppSession::run($fn, $this->isVerbose(), $client, $joinShared);
        } catch (ConfigMissing $e) {
            // Rethrown, not wrapped: an uninstalled application is not a
            // registry problem, and it must keep its own exit code -- the same
            // condition reaches bin/eppitnic directly on a local-only verb
            throw $e;
        } catch (\RuntimeException $e) {
            throw new SessionError($e->getMessage(), 0, $e);
        }

        if ($this->dryRun !== null) {
            $this->reportDryRun();
        }

        return $result;
    }

    /**
     * Print the requests a dry run would have sent.
     */
    private function reportDryRun(): void {
        $requests = $this->dryRun->sentRequests();

        if ($requests === []) {
            $this->warn('dry run: nothing would have been sent');
            return;
        }

        foreach ($requests as $request) {
            // straight to stdout: the XML is the point of the exercise, and it
            // is what someone will pipe into a validator
            echo rtrim($request), "\n";
        }
        $this->warn('dry run: ' . count($requests) . ' request(s) shown, none sent');
    }

    /**
     * Substitute the client the command talks to. Test suite only -- see the
     * note on EppSession::run()'s third argument.
     */
    public function useClient(Client $client): void {
        $this->client = $client;
    }

    /**
     * A Client for a command that talks to the registry directly, outside
     * withSession()'s hello + login + $fn + logout shape -- `session
     * keepalive`'s own hello, and `config keepalive off`'s logout of the
     * session it is closing. Honours useClient(), the same test seam
     * withSession() reads.
     */
    protected function client(): Client {
        return $this->client ?? new Client();
    }

    // ---------------------------------------------------------------
    // output
    // ---------------------------------------------------------------

    /**
     * A line for a human. Suppressed under --json so that stdout stays a
     * single parseable document.
     */
    protected function line(string $text = ''): void {
        if ( ! $this->isMachineReadable()) {
            echo $text, "\n";
        }
    }

    /**
     * Warnings and errors, on stderr so redirecting stdout still shows them.
     * Written straight to the stream so an output buffer cannot swallow them.
     */
    protected function warn(string $text): void {
        fwrite($this->errorStream(), $text . "\n");
    }

    /** @return resource where warn() writes, for a command that runs others */
    protected function errorStream() {
        return $this->errorStream ?? STDERR;
    }

    /**
     * Send warnings somewhere other than STDERR. Test suite only.
     *
     * @param resource $stream
     */
    public function useErrorStream($stream): void {
        $this->errorStream = $stream;
    }

    /**
     * One item of a bulk run failed: say so, and count it. The 'not found'
     * fallback is the fetch case -- an object that is simply absent answers
     * with no error text, and "example.it: " alone says nothing.
     *
     * @param string $item the domain, handle or other name being worked on
     * @param string $error what went wrong, from the object's getError()
     */
    protected function itemFailed(string $item, string $error = ''): void {
        $this->failures++;
        $this->warn("{$item}: " . ($error !== '' ? $error : 'not found'));
    }

    /**
     * The exit code for a bulk run: $failureCode if anything failed, else 0.
     *
     * @param int $failureCode the command's own code, from config/constants.php
     */
    protected function outcome(int $failureCode): int {
        return $this->failures > 0 ? $failureCode : 0;
    }

    /**
     * Split a ':'-separated option value into at most six entries -- the
     * registry's ceiling for nameservers and technical contacts alike
     * (domain:hostAttr, domain:contact in xsd/domain-1.0.xsd).
     *
     * @return string[]
     */
    protected function splitList(string $value): array {
        return array_slice(array_values(array_filter(array_map('trim', explode(':', $value)))), 0, 6);
    }

    /**
     * One result. Printed immediately in text mode; collected and emitted as a
     * JSON array by flush() under --json.
     *
     * @param string $text how a human should see it
     * @param array $record the same thing as data
     */
    protected function record(string $text, array $record): void {
        // Keyed on a faked session, not on the option: a local-only command
        // uses --dry-run to mean "show me what you would change", and that
        // output is the whole point of running it
        if ($this->dryRun !== null) {
            return;
        }

        switch ($this->format()) {
            case self::FORMAT_JSON:
                // held until flush(): one document means one array
                $this->records[] = $record;
                break;
            case self::FORMAT_JSONL:
                // emitted as it happens, so a long run can be piped and read
                // before it finishes
                echo json_encode($record, JSON_UNESCAPED_SLASHES), "\n";
                break;
            default:
                echo $text, "\n";
        }
    }

    /**
     * Emit whatever --json collected. Called once by the dispatcher after
     * run() returns, so a subcommand never has to remember to.
     */
    public function flush(): void {
        if ($this->format() === self::FORMAT_JSON) {
            echo json_encode($this->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        }
    }

    // ---------------------------------------------------------------
    // help
    // ---------------------------------------------------------------

    public function usage(string $verb): string {
        $text = "Usage: eppitnic {$verb}";
        if ($this->options() !== []) {
            $text .= ' [options]';
        }
        if ($this->arguments() !== '') {
            $text .= ' ' . $this->arguments();
        }
        $text .= "\n\n" . $this->describe() . "\n";

        foreach ([$this->options(), self::GLOBAL_OPTIONS] as $i => $set) {
            if ($set === []) {
                continue;
            }
            $text .= "\n" . ($i === 0 ? "Options:" : "Common options:") . "\n";
            foreach ($set as $name => $help) {
                $flag = '--' . rtrim($name, '=') . (str_ends_with($name, '=') ? '=VALUE' : '');
                $text .= sprintf("  %-22s %s\n", $flag, $help);
            }
        }

        return $text;
    }
}
