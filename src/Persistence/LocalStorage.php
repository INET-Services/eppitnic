<?php

namespace Eppitnic\Persistence;

use Eppitnic\Support\Validate;
use RedBeanPHP\R;

/**
 * The plumbing Contact and Domain repeat verbatim: the reseller-scoping clause, a
 * SQL failure turned into setError() plus false, and the row-id lookup for
 * history. Primitives: the two really do store differently.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\LocalStorage
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
trait LocalStorage
{
    /**
     * @return string the table this object is stored in
     */
    abstract protected static function storageTable(): string;

    /**
     * @return string the column holding the object's own identifier
     */
    abstract protected static function storageKeyColumn(): string;

    /**
     * @return string what to call the object in an error message
     */
    abstract protected static function storageNoun(): string;

    /**
     * Restrict a statement to the caller's reseller's rows, unless admin. Its
     * own placeholder, since some UPDATEs already bind :reseller_id, and
     * rebinding it fails silently on the rows it then does not match.
     *
     * @param array $params bound parameters, added to in place
     * @return string the SQL to append
     */
    private function storageScope(array &$params, Scope $scope): string {
        if ($scope->isAdmin()) {
            return '';
        }
        $params[':acl_reseller_id'] = $scope->resellerId;
        return ' AND reseller_id = :acl_reseller_id';
    }

    /**
     * Run a write, reporting a SQL failure the way every caller expects.
     *
     * @param string $action what failed, for the message ('deactivate',
     *               'store', ...)
     * @return bool false with the error set, true on success
     */
    private function storageWrite(string $sql, array $params, string $action, string $key): bool {
        try {
            R::exec($sql, $params);
        } catch (\RedBeanPHP\RedException\SQL $e) {
            $this->setError(
                "unable to {$action} " . static::storageNoun() . " '{$key}': " . $e->getMessage()
            );
            return false;
        }
        return true;
    }

    /**
     * @return int the row's own id, which the history table references
     */
    private function storageId(string $key): int {
        return (int) R::getCell(
            'SELECT id FROM ' . static::storageTable() . ' WHERE ' . static::storageKeyColumn() . ' = ?',
            [$key]
        );
    }

    /**
     * One row, scoped to the caller's reseller unless acting as an admin.
     *
     * @return array|null the row, or null when it does not exist or is not
     *                    theirs
     */
    private function storageFind(string $key, Scope $scope): ?array {
        $params = [':key' => $key];
        $sql = 'SELECT * FROM ' . static::storageTable()
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $this->storageScope($params, $scope);

        $row = R::getRow($sql, $params);
        return empty($row) ? null : $row;
    }

    /**
     * Whether the row exists within the caller's scope -- checked before a
     * write rather than read off its affected-row count, which MariaDB
     * reports as 0 for a row the write left unchanged.
     *
     * @param string $extraWhere an additional condition, bound by $extraParams
     */
    private function storageExists(string $key, Scope $scope, string $extraWhere = '', array $extraParams = []): bool {
        $params = [':key' => $key] + $extraParams;
        $sql = 'SELECT COUNT(*) FROM ' . static::storageTable()
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $extraWhere
             . $this->storageScope($params, $scope);

        return (int) R::getCell($sql, $params) > 0;
    }

    /**
     * Copy a row onto this object's declared properties.
     *
     * Columns with no matching property are skipped, which is what keeps
     * bookkeeping columns -- id, active, last_invoice -- out of the object.
     *
     * @param string[] $serialized columns stored with serialize()
     */
    private function storageHydrate(array $row, array $serialized): void {
        foreach ($row as $column => $value) {
            $column = strtolower($column);

            if (in_array($column, $serialized, true)) {
                // via SerializedColumn, not unserialize(): 6.x rows carry a
                // base64 envelope a bare call answers false to, leaving every
                // one of these columns not-an-array
                $this->$column = SerializedColumn::toArray($value);
            } elseif (property_exists($this, $column)) {
                $this->$column = $value;
            }
        }
    }

    /**
     * The soft delete both classes use: rows are deactivated, never removed,
     * because `domains`.`registrant` is a foreign key onto `contacts`.`handle`
     * and a delete on either side would be refused.
     *
     * @param int $active 0 to deactivate, 1 to restore
     * @param string $logAction the history action; a restore logs as 'update',
     *               since the history enum has no 'restore'
     * @param string $extraWhere an additional condition, e.g. the check that a
     *               contact is not still some domain's registrant; failing
     *               it is an error too, not a silent no-op
     */
    private function storageSetActive(
        string $key,
        int $active,
        Scope $scope,
        string $logAction,
        array $logData,
        string $extraWhere = '',
        array $extraParams = []
    ): bool {
        if ( ! $this->storageExists($key, $scope)) {
            $this->setError(static::storageNoun() . " '{$key}' not found");
            return false;
        }
        if ($extraWhere !== '' && ! $this->storageExists($key, $scope, $extraWhere, $extraParams)) {
            $this->setError(static::storageNoun() . " '{$key}' is still in use");
            return false;
        }

        $params = [':key' => $key] + $extraParams;
        $sql = 'UPDATE ' . static::storageTable() . ' SET active = ' . $active
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $extraWhere
             . $this->storageScope($params, $scope);

        if ( ! $this->storageWrite($sql, $params, $active === 0 ? 'deactivate' : 'activate', $key)) {
            return false;
        }

        History::record(static::storageTable(), $this->storageId($key), $logAction, $logData, $scope->userId);
        return true;
    }

    /**
     * Refuse a value longer than its column: MariaDB would fail the write in
     * strict mode and truncate it otherwise. Sets the error on overflow.
     *
     * @param array $data column => value
     */
    private function storageFits(array $data, string $key): bool {
        $limits = match (static::storageTable()) {
            'contacts' => Validate::CONTACT_FIELD_MAX_LENGTHS,
            'domains'  => Validate::DOMAIN_FIELD_MAX_LENGTHS,
            default    => [],
        };
        foreach ($limits as $column => $max) {
            $value = $data[$column] ?? null;
            if (is_string($value) && mb_strlen($value) > $max) {
                $this->setError(
                    static::storageNoun() . " {$key}: {$column} is " . mb_strlen($value)
                    . " characters, the database holds at most {$max}"
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Write changed columns back, scoped to the reseller unless admin. Writes only:
     * the history entry is the caller's, since an update records the changed
     * columns where an upsert's update half records that it was stored.
     *
     * @param array $data column => value
     */
    private function storageUpdate(string $key, array $data, Scope $scope): bool {
        if ( ! $this->storageFits($data, $key)) {
            return false;
        }
        if ( ! $this->storageExists($key, $scope)) {
            $this->setError(static::storageNoun() . " '{$key}' not found");
            return false;
        }

        $set = [];
        $params = [':key' => $key];
        foreach ($data as $column => $value) {
            $set[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        $sql = 'UPDATE ' . static::storageTable() . ' SET ' . implode(', ', $set)
             . ' WHERE ' . static::storageKeyColumn() . ' = :key'
             . $this->storageScope($params, $scope);

        return $this->storageWrite($sql, $params, 'update', $key);
    }

    /**
     * Insert a row. The caller has already decided what to do about one that
     * exists -- the two classes answer that differently.
     *
     * @param array $data column => value
     */
    private function storageInsert(array $data, string $key): bool {
        if ( ! $this->storageFits($data, $key)) {
            return false;
        }
        $params = [];
        foreach ($data as $column => $value) {
            $params[":{$column}"] = $value;
        }

        $sql = 'INSERT INTO ' . static::storageTable()
             . ' (' . implode(', ', array_keys($data)) . ')'
             . ' VALUES (' . implode(', ', array_keys($params)) . ')';

        return $this->storageWrite($sql, $params, 'store', $key);
    }
}
