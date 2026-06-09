<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace marcinmisiak\db\firebird;

/**
 *
 * @author Edgard Lorraine Messias <edgardmessias@gmail.com>
 * @since 2.0
 */
class Command extends \yii\db\Command
{

    /**
     * Binds a parameter to the SQL statement to be executed.
     * @param string|integer $name parameter identifier. For a prepared statement
     * using named placeholders, this will be a parameter name of
     * the form `:name`. For a prepared statement using question mark
     * placeholders, this will be the 1-indexed position of the parameter.
     * @param mixed $value Name of the PHP variable to bind to the SQL statement parameter
     * @param integer $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
     * @param integer $length length of the data type
     * @param mixed $driverOptions the driver-specific options
     * @return static the current command being executed
     * @see http://www.php.net/manual/en/function.PDOStatement-bindParam.php
     */
    public function bindParam($name, &$value, $dataType = null, $length = null, $driverOptions = null)
    {
        if ($dataType === null) {
            $dataType = $this->db->getSchema()->getPdoType($value);
        }
        if ($dataType == \PDO::PARAM_BOOL) {
            $dataType = \PDO::PARAM_INT;
        }
        return parent::bindParam($name, $value, $dataType, $length, $driverOptions);
    }

    /**
     * Specifies the SQL statement to be executed.
     * The previous SQL execution (if any) will be cancelled, and [[params]] will be cleared as well.
     * @param string $sql the SQL statement to be set.
     * @return static this command instance
     */
    public function setSql($sql)
    {
        $matches = null;
         if (preg_match("/^\s*DROP TABLE IF EXISTS (['\"]?([^\s\;]+)['\"]?);?\s*$/i", $sql ?? '', $matches)) {
            if ($this->db->getSchema()->getTableSchema($matches[2]) !== null) {
                $sql = $this->db->getQueryBuilder()->dropTable($matches[2]);
            } else {
                $sql = 'select 1 from RDB$DATABASE;'; //Prevent Drop Table
            }
        }
        
        return parent::setSql($sql);
    }

    /**
     * Binds a value to a parameter.
     * @param string|integer $name Parameter identifier. For a prepared statement
     * using named placeholders, this will be a parameter name of
     * the form `:name`. For a prepared statement using question mark
     * placeholders, this will be the 1-indexed position of the parameter.
     * @param mixed $value The value to bind to the parameter
     * @param integer $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
     * @return static the current command being executed
     * @see http://www.php.net/manual/en/function.PDOStatement-bindValue.php
     */
    public function bindValue($name, $value, $dataType = null)
    {
        if ($dataType === null) {
            $dataType = $this->db->getSchema()->getPdoType($value);
        }
        if ($dataType == \PDO::PARAM_BOOL) {
            $dataType = \PDO::PARAM_INT;
        }

        return parent::bindValue($name, $value, $dataType);
    }

    /**
     * @inheritdoc
     * Removes unused parameters before preparing the query (PHP 8.5+ compatibility)
     */
    public function prepare($forRead = null)
    {
        if (!$this->pdoStatement && !empty($this->pendingParams) && $this->db->stripUnusedParams) {
            $sql = $this->getSql();
            if ($sql !== '' && $sql !== null) {
                $this->filterUnusedParams($sql);
            }
        }
        return parent::prepare($forRead);
    }

    /**
     * Removes parameters from pendingParams and params that are not used in SQL.
     * Logs removed parameters to a file for later fixing.
     *
     * @param string $sql SQL query
     */
    protected function filterUnusedParams($sql)
    {
        // Extract named parameters from SQL (:param_name)
        preg_match_all('/:(\w+)/', $sql, $matches);
        $usedParams = [];
        foreach ($matches[0] as $param) {
            $usedParams[strtolower($param)] = true;
        }

        $removedParams = [];

        foreach ($this->pendingParams as $name => $value) {
            // Normalize parameter name for comparison
            $normalizedName = $name;
            if (is_string($name) && strncmp(':', $name, 1) !== 0) {
                $normalizedName = ':' . $name;
            }
            $normalizedName = strtolower($normalizedName);

            if (!isset($usedParams[$normalizedName])) {
                $removedParams[] = $name;
                unset($this->pendingParams[$name]);
                unset($this->params[$name]);
            }
        }

        // Log only if parameters were removed
        if (!empty($removedParams)) {
            $this->logRemovedParams($sql, $removedParams);
        }
    }

    /**
     * Logs information about removed parameters to a file
     *
     * @param string $sql SQL query
     * @param array $removedParams list of removed parameters
     */
    protected function logRemovedParams($sql, $removedParams)
    {
        $logDir = \Yii::getAlias('@app/runtime/logs');
        if (!is_dir($logDir)) {
            return;
        }

        $logFile = $logDir . '/unused-params.log';
        $timestamp = date('Y-m-d H:i:s');

        // Truncate SQL to 500 characters
        $shortSql = mb_strlen($sql) > 500 ? mb_substr($sql, 0, 500) . '...' : $sql;

        // Get call stack (first 3 files after Command.php)
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $traceLines = [];
        foreach ($trace as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], 'Command.php') === false) {
                $traceLines[] = (isset($frame['file']) ? $frame['file'] : '')
                    . (isset($frame['line']) ? ':' . $frame['line'] : '');
                if (count($traceLines) >= 3) {
                    break;
                }
            }
        }

        $logEntry = sprintf(
            "[%s] SQL: %s\n  Removed parameters: %s\n  Stack: %s\n\n",
            $timestamp,
            $shortSql,
            implode(', ', $removedParams),
            implode(' -> ', $traceLines)
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
