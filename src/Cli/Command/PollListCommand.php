<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Support\Idn;
use RedBeanPHP\R;

/**
 * The poll messages already drained into the local database.
 *
 * Reads only -- nothing here touches the registry queue.
 */
final class PollListCommand extends Command
{
    public function describe(): string {
        return 'list poll messages stored locally';
    }

    public function options(): array {
        return [
            'all'     => 'include messages already acknowledged',
            'type='   => 'only messages of this type, e.g. dnsErrorMsgData',
            'domain=' => 'only messages about this domain',
            'limit='  => 'stop after this many messages (default 50, 0 for all)',
        ];
    }

    public function run(): int {
        $this->database();

        $where = [];
        $params = [];

        if ( ! $this->hasOption('all')) {
            $where[] = 'archived_time IS NULL';
        }
        if ($type = $this->option('type')) {
            $where[] = 'type = :type';
            $params[':type'] = $type;
        }
        if ($domain = $this->option('domain')) {
            $where[] = 'domain = :domain';
            $params[':domain'] = Idn::domain($domain);
        }

        $limit = $this->option('limit');
        $limit = ($limit === null) ? 50 : (int) $limit;

        $sql = 'SELECT id, type, domain, data, archived_time FROM messages';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        // interpolated, not bound: LIMIT takes no placeholder in a prepared
        // statement, and the value is an int cast a line above
        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        $messages = R::getAll($sql, $params);

        foreach ($messages as $message) {
            $this->record(
                sprintf('%-8s %-30s %-28s %s',
                    $message['id'],
                    $message['type'],
                    $message['domain'] !== '' ? $message['domain'] : '-',
                    $message['data']),
                $message
            );
        }

        $this->line('');
        $this->line(count($messages) . ' message(s)');
        return 0;
    }
}
