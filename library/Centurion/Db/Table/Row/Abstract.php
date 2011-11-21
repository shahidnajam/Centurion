<?php
/**
 * Centurion
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@centurion-project.org so we can send you a copy immediately.
 *
 * @category    Centurion
 * @package     Centurion_Db
 * @subpackage  Table
 * @copyright   Copyright (c) 2008-2011 Octave & Octave (http://www.octaveoctave.com)
 * @license     http://centurion-project.org/license/new-bsd     New BSD License
 * @version     $Id$
 */

/**
 * @category    Centurion
 * @package     Centurion_Db
 * @subpackage  Table
 * @copyright   Copyright (c) 2008-2011 Octave & Octave (http://www.octaveoctave.com)
 * @license     http://centurion-project.org/license/new-bsd     New BSD License
 * @author      Florent Messa <florent.messa@gmail.com>
 * @author      Laurent Chenay <lchenay@gmail.com>
 */
abstract class Centurion_Db_Table_Row_Abstract extends Zend_Db_Table_Row_Abstract implements Centurion_Traits_Traitsable
{
    protected $_specialGets = array(
        'permalink'       =>  '_getAbsoluteUrl',
        'admin_permalink' =>  '_getAdminUrl',
        'pk'              =>  'getPrimaryKey',
        'px'              =>  'getPx'
    );

    /**
     * References to parent tables.
     *
     * @var $_parents Centurion_Db_Table_Row_Abstract
     */
    protected $_parents = array();

    /**
     * Children, dependent tables and Many to Many constraints.
     *
     * @var Centurion_Db_Table_Row_Abstract
     */
    protected $_children = array();

    /**
     * Children by reference map.
     *
     * @var array[string][string]Centurion_Db_Table_Row_Abstract
     */
    protected static $_relationship = array();

    /**
     * @var array
     * @todo Is it still used ? Trait proxy ?
     */
    protected $_proxyRow = null;

    /**
     * @var unknown_type
     */
    protected $_cache = null;

    protected $_traitQueue;


    public function getTraitQueue()
    {
        if (null == $this->_traitQueue)
            $this->_traitQueue = new Centurion_Traits_Queue();

        return $this->_traitQueue;
    }

    public function __clone()
    {
        $this->_traitQueue = null;
        Centurion_Traits_Common::initTraits($this);
    }

    public function __sleep()
    {
        return array_merge(array('_specialGets', '_traitQueue'), parent::__sleep());
    }

    public function __construct(array $config)
    {
        parent::__construct($config);

        Centurion_Traits_Common::initTraits($this);
    }

    /**
     * Set row field value
     *
     * @param  string $columnName The column key
     * @param  mixed  $value      The value for the property
     * @return void
     * @throws Centurion_Db_Table_Exception
     */
    public function __set($columnName, $value)
    {
        $columnName = $this->_transformColumn($columnName);
        if (!array_key_exists($columnName, array_merge($this->_data, $this->_specialGets))) {
            throw new Centurion_Db_Table_Exception(sprintf("Specified column \"%s\" is not in the row", $columnName));
        }

        $method = 'set' . ucfirst($columnName);
        if (method_exists($this, $method)) {
            call_user_func(array($this , $method), $value);
        } else {
            $this->_data[$columnName] = $value;
        }

        $this->_modifiedFields[$columnName] = true;

        // @todo implement get for trait

    }

    /**
     * Magic method to get relationship.
     *
     * @param   string    $columnName Column name.
     * @return  Centurion_Db_Table_Row_Abstract|Centurion_Db_Table_Rowset_Abstract
     */
    public function __get($columnName)
    {
        $columnName = $this->_transformColumn($columnName);

        if (array_key_exists($columnName, $this->_specialGets)) {
            if (is_string($this->_specialGets[$columnName])) {
                if (!method_exists($this, $this->_specialGets[$columnName])) {
                    throw new Centurion_Db_Table_Exception(sprintf("Specified method \"%s\" does not exist", $this->_specialGets[$columnName]));
                } else {
                    return call_user_func(array($this, $this->_specialGets[$columnName]));
                }
            } elseif (is_array($this->_specialGets[$columnName]) && 2 == count($this->_specialGets[$columnName])) {
                if (is_object($this->_specialGets[$columnName][0]) && is_string($this->_specialGets[$columnName][1]) && method_exists($this->_specialGets[$columnName][0], $this->_specialGets[$columnName][1])) {
                    return call_user_func_array($this->_specialGets[$columnName], array());
                }else if (!method_exists($this, (string) $this->_specialGets[$columnName][0])) {
                    throw new Centurion_Db_Table_Exception(sprintf("Specified method \"%s\" does not exist", $this->_specialGets[$columnName][0]));
                } else {
                    return call_user_func_array(array($this, $this->_specialGets[$columnName][0]), (array) $this->_specialGets[$columnName][1]);
                }
            }
        }

        if (array_key_exists($columnName, $this->_data)) {
            return $this->_getRawData($columnName);
        }

        //TODO: cascade object__attribute__object__atribute...
        if (null !== ($pos = strpos($columnName, '+')) && $pos !== false) {
            $part1 = $this->{trim(substr($columnName, 0, $pos))};
            $part2 = $this->{trim(substr($columnName, $pos + 1))};

            return $part1 . '' . $part2;
        }

        if (null !== ($pos = strpos($columnName, '__')) && $pos !== false) {
            $row = $this->{substr($columnName, 0, $pos)};
            if ($row == null)
                return null;

            return $row->{substr($columnName, $pos + 2)};
        }

        $referenceMap = $this->getTable()->info('referenceMap');
        if (isset($referenceMap[$columnName])) {
            $column = $referenceMap[$columnName]['columns'];
            $className = $referenceMap[$columnName]['refTableClass'];

            if (!isset(self::$_relationship[$className][$this->{$column}])) {

                self::$_relationship[$className][$this->{$column}]
                    = $this->findParentRow($referenceMap[$columnName]['refTableClass'],
                                           $columnName);
            }
            return self::$_relationship[$className][$this->{$column}];
        }
        $dependentTables = $this->getTable()->info('dependentTables');
        if (isset($dependentTables[$columnName])) {
            if (!isset($this->_children[$columnName])) {
                $this->_children[$columnName] = $this->findDependentRowset($dependentTables[$columnName]);
            }

            return $this->_children[$columnName];
        }

        $manyDependentTables = $this->getTable()->info('manyDependentTables');
        if (isset($manyDependentTables[$columnName])) {
            if (!isset($this->_children[$columnName])) {
                Centurion_Db_Table_Abstract::setFiltersStatus(true);
                $this->_children[$columnName]
                    = $this->findManyToManyRowset($manyDependentTables[$columnName]['refTableClass'],
                                                  $manyDependentTables[$columnName]['intersectionTable'], 
                                                  substr($manyDependentTables[$columnName]['columns']['local'], 0, -3),
                                                  substr($manyDependentTables[$columnName]['columns']['foreign'], 0, -3)
                                                  );
                $this->_children[$columnName]->setIntersectionColumns($manyDependentTables[$columnName]['columns']);
                Centurion_Db_Table_Abstract::restoreFiltersStatus();
            }

            return $this->_children[$columnName];
        }

        throw new Zend_Db_Table_Row_Exception("Specified column \"$columnName\" is not in the row");
    }

    public function toArray()
    {
        $return = array();
        
        foreach ($this->_data as $key => $val) {
            $return[$key] = $this->_getRawData($key);
        }
        
        return $return;
    }
    
    public function getTable()
    {
        if (null === $this->_table && null !== $this->_tableClass) {
            $this->_table = $this->_getTableFromString($this->_tableClass);
            $this->_connected = true;
        }
        return $this->_table;
    }

    /**
     * Query a parent table to retrieve the single row matching the current row.
     *
     * @param string|Zend_Db_Table_Abstract $parentTable
     * @param string                        OPTIONAL $ruleKey
     * @param Zend_Db_Table_Select          OPTIONAL $select
     * @return Zend_Db_Table_Row_Abstract   Query result from $parentTable
     * @throws Zend_Db_Table_Row_Exception If $parentTable is not a table or is not loadable.
     */
    public function findParentRow($parentTable, $ruleKey = null, Zend_Db_Table_Select $select = null)
    {
        $db = $this->_getTable()->getAdapter();

        if (is_string($parentTable)) {
            $parentTable = $this->_getTableFromString($parentTable);
        }

        if (!$parentTable instanceof Zend_Db_Table_Abstract) {
            $type = gettype($parentTable);
            if ($type == 'object') {
                $type = get_class($parentTable);
            }
            require_once 'Zend/Db/Table/Row/Exception.php';
            throw new Zend_Db_Table_Row_Exception("Parent table must be a Zend_Db_Table_Abstract, but it is $type");
        }

        // even if we are interacting between a table defined in a class and a
        // table via extension, ensure to persist the definition
        if (($tableDefinition = $this->_table->getDefinition()) !== null
            && ($parentTable->getDefinition() == null)) {
            $parentTable->setOptions(array(Zend_Db_Table_Abstract::DEFINITION => $tableDefinition));
        }

        if ($select === null) {
            $select = $parentTable->select();
        } else {
            $select->setTable($parentTable);
        }

        $map = $this->_prepareReference($this->_getTable(), $parentTable, $ruleKey);

        // iterate the map, creating the proper wheres
        for ($i = 0; $i < count($map[Zend_Db_Table_Abstract::COLUMNS]); ++$i) {
            $dependentColumnName = $db->foldCase($map[Zend_Db_Table_Abstract::COLUMNS][$i]);
            $value = $this->_data[$dependentColumnName];
            // Use adapter from parent table to ensure correct query construction
            $parentDb = $parentTable->getAdapter();
            $parentColumnName = $parentDb->foldCase($map[Zend_Db_Table_Abstract::REF_COLUMNS][$i]);
            $parentColumn = $parentDb->quoteIdentifier($parentColumnName, true);
            $parentInfo = $parentTable->info();

            // determine where part
            $type     = $parentInfo[Zend_Db_Table_Abstract::METADATA][$parentColumnName]['DATA_TYPE'];
            $nullable = $parentInfo[Zend_Db_Table_Abstract::METADATA][$parentColumnName]['NULLABLE'];
            if ($value === null && $nullable == true) {
                $select->where("$parentColumn IS NULL");
            } elseif ($value === null && $nullable == false) {
                return null;
            } else {
                $select->where($parentInfo['name'] . '.' . "$parentColumn = ?", $value, $type);
            }

        }

        return $parentTable->fetchRow($select);
    }

    /**
     * Test existence of row field
     *
     * @param  string  $columnName  The column key.
     * @return boolean
     */
    public function __isset($columnName)
    {
        $columnName = $this->_transformColumn($columnName);
        if ($this->columnsExists($columnName)) {
            return true;
        }

        if (null !== ($pos = strpos($columnName, '+')) && $pos !== false) {
            $part1 = isset($this->{trim(substr($columnName, 0, $pos))});
            $part2 = isset($this->{trim(substr($columnName, $pos + 1))});

            return $part1 && $part2;
        }

        if (null !== ($pos = strpos($columnName, '__')) && $pos !== false) {
            $row = $this->{substr($columnName, 0, $pos)};
            if ($row == null)
                return false;

            return isset($row->{substr($columnName, $pos + 2)});
        }

        if (array_key_exists($columnName, $this->_specialGets)) {
            if (!method_exists($this, $this->_specialGets[$columnName])) {
                throw new Centurion_Db_Table_Exception(sprintf("Specified method \"%s\" does not exist", $this->_specialGets[$columnName]));
            }
            return true;
        }

        $referenceMap = $this->getTable()->info('referenceMap');
        if (isset($referenceMap[$columnName])) {
            return true;
        }
        $dependentTables = $this->getTable()->info('dependentTables');
        if (isset($dependentTables[$columnName])) {
            return true;
        }

        $manyDependentTables = $this->getTable()->info('manyDependentTables');
        if (isset($manyDependentTables[$columnName])) {
            return true;
        }

        return parent::__isset($columnName);
    }

    public function columnsExists($columnName)
    {
        return array_key_exists($columnName, $this->_data);
    }

    protected function _getRawData($columnName)
    {
        if ($this->columnsExists($columnName)) {
            list($found, $retVal) = Centurion_Traits_Common::checkTraitOverload($this, __METHOD__, $columnName);
            if (!$found)
                return $this->_data[$columnName];
            else
                return $retVal;
        } else {
            throw new Exception(sprintf('Column %s doesn\'t exists', $columnName));
        }
    }

    public function isAllowedContext($context, $ressource = null)
    {
        return in_array($context, iterator_to_array($this->_traitQueue), true);
    }

    /**
     *
     *
     * @return true|false
     */
    public function isNew()
    {
        return empty($this->_cleanData);
    }

    public function delegateGet($context, $column)
    {
        if (!$this->isAllowedContext($context, $column))
            throw new Centurion_Db_Exception(sprintf('Unauthorize property %s', $column));

        return $this->$column;
    }

    public function delegateSet($context, $column, $value)
    {
        if (!$this->isAllowedContext($context, $column))
            throw new Centurion_Db_Exception(sprintf('Unauthorize property %s', $column));

        $this->$column = $value;
    }

    public function delegateCall($context, $method, $args = array())
    {
        if (!$this->isAllowedContext($context, $method))
            throw new Centurion_Db_Exception(sprintf('Unauthorize method %s', $method));

        return call_user_func_array(array($this, $method), $args);
    }

    public function __call($method, array $args)
    {

        $lcMethod = strtolower($method);

        //TODO: change all substr by strncmp
        if (!strncmp($lcMethod, 'getnextby', 9)) {
            $by = substr($method, 9, strlen($method));
            $method = 'getNextBy';
        } else if (substr($lcMethod, 0, 13) == 'getpreviousby') {
            $by = substr($method, 13, strlen($method));
            $method = 'getPreviousBy';
        } else if (substr($lcMethod, 0, 10) == 'getfirstby') {
            $by = substr($method, 10, strlen($method));
            $method = 'getFirstBy';
        } else if (substr($lcMethod, 0, 9) == 'getlastby') {
            $by = substr($method, 9, strlen($method));
            $method = 'getLastBy';
        } else if (substr($lcMethod, 0, 15) == 'getdateobjectby') {
            $by = substr($method, 15, strlen($method));
            $method = 'getDateObjectBy';
        } else if (substr($lcMethod, 0, 16) == 'getpictureorpxby') {
            $by = substr($method, 16, strlen($method));
            $method = 'getPictureOrPxBy';
        } else if (substr($lcMethod, 0, 3) == 'get' && preg_match('`get((.+)Or(.+))+`', $method)) {
            $lcMethod = substr($lcMethod, 3);
            $columns = explode('or', $lcMethod);
            foreach ($columns as $column) {
                if (isset($this->$column) && null !== $this->$column) {
                    return $this->$column;
                }
            }

            return null;
        }

        if (isset($by)) {
            return call_user_func_array(array($this, $method), array_merge(array($by), $args));
        }

        try {
            $retVal = parent::__call($method, $args);
        } catch(Zend_Db_Table_Row_Exception $e) {
            list($found, $retVal) = Centurion_Traits_Common::checkTraitOverload($this, $method, $args);

            if (!$found) {
                throw $e;
            }
        }

        return $retVal;
    }

    public function init()
    {
        $this->_hydrateData($this->_data);
        parent::init();
    }

    protected function _hydrateData($data)
    {
        $referenceData = array();
        $lenSeparator = strlen(Centurion_Db_Table_Select::HYDRATE_SEPARATOR);

        $referenceMap = $this->getTable()->info('referenceMap');

        foreach ($data as $key => $cols) {
            if ($pos = strpos($key, Centurion_Db_Table_Select::HYDRATE_SEPARATOR)) {
                $reference = substr($key, 0, $pos);
                $referenceKey = substr($key, $pos + $lenSeparator);

                if (!isset($referenceMap[$reference])) {
                    continue;
                }

                if (!isset($referenceData[$reference])) {
                    $referenceData[$reference] = array();
                }
                $referenceData[$reference][$referenceKey] = $cols;
            }
        }

        foreach ($referenceData as $reference => $cols) {
            $column = $referenceMap[$reference]['columns'];
            $className = $referenceMap[$reference]['refTableClass'];
            if (!isset(self::$_relationship[$className][$this->{$column}])) {
                $table = Centurion_Db::getSingletonByClassName($className);

                $rowData = array();
                $rowData['table'] = $table;
                $rowData['data'] = $cols;
                $rowData['stored'] = true;

                $rowClassName = $table->getRowClass();

                self::$_relationship[$className][$this->{$column}] = new $rowClassName($rowData);
            }
        }
    }

    public function getCache($frontendOptions = null, $backendOptions = null)
    {
        if (null === $this->_cache) {
            if (null === $frontendOptions) {
                $frontendOptions = Centurion_Db_Table_Abstract::getDefaultFrontendOptions();
            }

            if (null === $backendOptions) {
                $backendOptions = Centurion_Db_Table_Abstract::getDefaultBackendOptions();
            }

            $frontendOptions['cache_id_prefix'] = get_class($this).'_'.md5(serialize($this->pk)).'_';

            $this->_cache = new Centurion_Db_Cache($this, $frontendOptions, $backendOptions);
        }

        return $this->_cache;
    }

    /**
     * @param  string|Zend_Db_Table_Abstract  $matchTable
     * @param  string|Zend_Db_Table_Abstract  $intersectionTable
     * @param  string                         OPTIONAL $callerRefRule
     * @param  string                         OPTIONAL $matchRefRule
     * @param  Zend_Db_Table_Select           OPTIONAL $select
     * @return Zend_Db_Table_Rowset_Abstract Query result from $matchTable
     * @throws Zend_Db_Table_Row_Exception If $matchTable or $intersectionTable is not a table class or is not loadable.
     */
    public function findManyToManyRowset($matchTable, $intersectionTable, $callerRefRule = null,
                                         $matchRefRule = null, Zend_Db_Table_Select $select = null)
    {
        if (is_string($matchTable)) {
            $matchTable = $this->_getTableFromString($matchTable);
        }

        if (is_string($intersectionTable)) {
            $intersectionTable = $this->_getTableFromString($intersectionTable);
        }

        // Use adapter from intersection table to ensure correct query construction
        $interInfo = $intersectionTable->info();
        $interDb   = $intersectionTable->getAdapter();
        $interName = $interInfo['name'];
        $interSchema = isset($interInfo['schema']) ? $interInfo['schema'] : null;
        $matchInfo = $matchTable->info();
        $matchName = $matchInfo['name'];
        $matchSchema = isset($matchInfo['schema']) ? $matchInfo['schema'] : null;
        
        if (null === $select && in_array('order', $intersectionTable->info('cols'))) {
            $select = $matchTable->select(false)->order($interName.'.order asc');
        }
        $db = $this->_getTable()->getAdapter();

        if (!$intersectionTable instanceof Zend_Db_Table_Abstract) {
            $type = gettype($intersectionTable);
            if ($type == 'object') {
                $type = get_class($intersectionTable);
            }
            require_once 'Zend/Db/Table/Row/Exception.php';
            throw new Zend_Db_Table_Row_Exception("Intersection table must be a Zend_Db_Table_Abstract, but it is $type");
        }

        // even if we are interacting between a table defined in a class and a
        // table via extension, ensure to persist the definition
        if (($tableDefinition = $this->_table->getDefinition()) !== null
            && ($intersectionTable->getDefinition() == null)) {
            $intersectionTable->setOptions(array(Zend_Db_Table_Abstract::DEFINITION => $tableDefinition));
        }

        if (is_string($matchTable)) {
            $matchTable = $this->_getTableFromString($matchTable);
        }

        if (! $matchTable instanceof Zend_Db_Table_Abstract) {
            $type = gettype($matchTable);
            if ($type == 'object') {
                $type = get_class($matchTable);
            }
            require_once 'Zend/Db/Table/Row/Exception.php';
            throw new Zend_Db_Table_Row_Exception("Match table must be a Zend_Db_Table_Abstract, but it is $type");
        }

        // even if we are interacting between a table defined in a class and a
        // table via extension, ensure to persist the definition
        if (($tableDefinition = $this->_table->getDefinition()) !== null
            && ($matchTable->getDefinition() == null)) {
            $matchTable->setOptions(array(Zend_Db_Table_Abstract::DEFINITION => $tableDefinition));
        }

        if ($select === null) {
            $select = $matchTable->select();
        } else {
            $select->setTable($matchTable);
        }

        $matchMap = $this->_prepareReference($intersectionTable, $matchTable, $matchRefRule);

        for ($i = 0; $i < count($matchMap[Zend_Db_Table_Abstract::COLUMNS]); ++$i) {
            $interCol = $interDb->quoteIdentifier($interName . '.' . $matchMap[Zend_Db_Table_Abstract::COLUMNS][$i], true);
            $matchCol = $interDb->quoteIdentifier('m_' . $matchName . '.' . $matchMap[Zend_Db_Table_Abstract::REF_COLUMNS][$i], true);
            $joinCond[] = "$interCol = $matchCol";
        }
        $joinCond = implode(' AND ', $joinCond);



        $select->from(array($interName), array(), $interSchema)
               ->joinInner(array('m_' . $matchName => $matchName), $joinCond, Zend_Db_Select::SQL_WILDCARD, $matchSchema)
               ->setIntegrityCheck(false);
               
        $callerMap = $this->_prepareReference($intersectionTable, $this->_getTable(), $callerRefRule);

        for ($i = 0; $i < count($callerMap[Zend_Db_Table_Abstract::COLUMNS]); ++$i) {
            $callerColumnName = $db->foldCase($callerMap[Zend_Db_Table_Abstract::REF_COLUMNS][$i]);
            $value = $this->_data[$callerColumnName];
            $interColumnName = $interDb->foldCase($callerMap[Zend_Db_Table_Abstract::COLUMNS][$i]);
            $interCol = $interDb->quoteIdentifier("$interName.$interColumnName", true);
            $interInfo = $intersectionTable->info();
            $type = $interInfo[Zend_Db_Table_Abstract::METADATA][$interColumnName]['DATA_TYPE'];
            $select->where($interDb->quoteInto("$interCol = ?", $value, $type));
        }

        $stmt = $select->query();

        $config = array(
            'table'    => $matchTable,
            'data'     => $stmt->fetchAll(Zend_Db::FETCH_ASSOC),
            'rowClass' => $matchTable->getRowClass(),
            'readOnly' => false,
            'stored'   => true
        );

        $rowsetClass = $matchTable->getRowsetClass();
        if (!class_exists($rowsetClass)) {
            try {
                require_once 'Zend/Loader.php';
                Zend_Loader::loadClass($rowsetClass);
            } catch (Zend_Exception $e) {
                require_once 'Zend/Db/Table/Row/Exception.php';
                throw new Zend_Db_Table_Row_Exception($e->getMessage(), $e->getCode(), $e);
            }
        }
        $rowset = new $rowsetClass($config);
        return $rowset->setRefRow($this)
                      ->setIntersectionTableClass($intersectionTable);
    }

    /**
     * Retrieve the previous row.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getFirstBy($by, $kwargs = null, $select = null)
    {
        return $this->_getFirstOrLastByField($by, true, $kwargs, $select);
    }

    /**
     * Retrieve the next row.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getLastBy($by, $kwargs = null, $select = null)
    {
        return $this->_getFirstOrLastByField($by, false, $kwargs, $select);
    }

    /**
     * Retrieve the previous row.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getPreviousBy($by, $kwargs = null, $select = null)
    {
        return $this->_getNextOrPreviousByField($by, false, $kwargs, $select);
    }

    /**
     * Retrieve the count of previous rows.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getPreviousCountBy($by, $kwargs = null, $select = null)
    {
        return $this->_getNextOrPreviousCountByField($by, false, $kwargs, $select);
    }

    /**
     * Retrieve the next row.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getNextBy($by, $kwargs = null, $select = null)
    {
        return $this->_getNextOrPreviousByField($by, true, $kwargs, $select);
    }

    /**
     * Retrieve the next row.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getPictureOrPxBy($by)
    {
        if (null !== $this->{$by}) {
            return $this->{$by};
        } else {
            return $this->getPx();
        }
    }

    public function getPx()
    {
        return Centurion_Db::getSingleton('media/file')->getPx();
    }
    /**
     * Retrieve the count of next rows.
     *
     * @param string $by                        Column name
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function getNextCountBy($by, $kwargs = null, $select = null)
    {
        return $this->_getNextOrPreviousCountByField($by, true, $kwargs, $select);
    }

    /**
     * Generate a Zend_Date object with a column name.
     *
     * @param string $by            Column name
     * @param string $dateFormat    Zend_Date format
     * @return Zend_Date
     */
    public function getDateObjectBy($by, $dateFormat = Zend_Date::ISO_8601)
    {
        return new Zend_Date($this->{Centurion_Inflector::tableize($by)}, $dateFormat);
    }

    public function __toString()
    {
        return sprintf('%s-%s', get_class($this), $this->getPrimaryKey());
    }

    /**
     * Retrieve the absolute url of the instance.
     *
     * @return string
     */
    protected function _getAbsoluteUrl()
    {
        //if (!isset($this->_data['permalink'])) {
            $result = $this->getAbsoluteUrl();
            if (null === $result)
                return '#';

            list($params, $route) = $result;

            if (is_string($params)) {
                $temp = $route;
                $route = $params;
                $params = $temp;
            }

            $this->_data['permalink'] = Zend_Controller_Front::getInstance()->getRouter()->assemble($params, $route);
        //}

        return $this->_data['permalink'];
    }

    protected function _getAdminUrl()
    {
        $route = 'default';
        $params = array();

        $tab = explode('_', $this->getTable()->info(Zend_Db_Table::NAME));

        $params['module'] = array_shift($tab);
        $params['controller'] = 'admin-' . implode('-', $tab);
        $params['id'] = $this->id;
        $params['action'] = 'get';

        $this->_data['admin_permalink'] = Zend_Controller_Front::getInstance()->getRouter()->assemble($params, $route, true);
        return $this->_data['admin_permalink'];
    }

    /**
     * Retrieve the absolute url of the instance.
     *
     * @return string
     */
    public function getAbsoluteUrl()
    {
        $router = Zend_Controller_Front::getInstance()->getRouter();
        $name = sprintf('%s_%s', $this->getTable()->info(Centurion_Db_Table_Abstract::NAME), 'get');

        if (!$router->hasRoute($name)) {
            $data = explode('_', $this->getTable()->info(Centurion_Db_Table_Abstract::NAME), 2);
            return array(array('module' => $data[0], 'controller' => str_replace('_', '-', $data[1]), 'action' => 'get', 'id' => $this->id), 'default');
        }
        $route = $router->getRoute($name);

        if (!($route instanceof Centurion_Controller_Router_Route_Object || $route instanceof Zend_Controller_Router_Route_Chain)) {
            throw new Centurion_Exception(sprintf('%s route is not a Centurion_Controller_Router_Route_Object. Please overload getAbsoluteUrl() function. ', $name));
        }

        return array(array('object' => $this), $name);
    }

    /**
     * Retrieve the primary key value.
     *
     * @return int
     */
    public function getPrimaryKey()
    {
        $primary = $this->_getPrimaryKey(true);
        if (count($primary) === 1) {
            return current($primary);
        }

        return $primary;
    }

    /**
     * Has selector, with a type, known if an object is a parent of another.
     * ex: if an user has a post.
     *
     * @param string $type
     * @param string $pk
     * @todo add manyDependentTables
     * @return void
     */
    public function has($type, $object)
    {
        if (!array_key_exists($type, $this->getTable()->getDependentTables())
            && !array_key_exists($type, $this->getTable()->getManyDependentTables())) {
            throw new Centurion_Db_Exception(sprintf('type "%s" does not belong to %s', $type, get_class($this->getTable())));
        }

        if ($object instanceof Centurion_Db_Table_Row_Abstract) {
            $object = $object->toArray();
        }

        return in_array($object, $this->{$type}->toArray());
    }

    public function findDependentRowsetOrdered($columnName, $order)
    {
        $dependentTables = $this->getTable()->info('dependentTables');
        if (isset($dependentTables[$columnName])) {
                $select = $this->_getTableFromString($dependentTables[$columnName])->select()->order($order);
                $rowset = $this->findDependentRowset($dependentTables[$columnName], null, $select);
                if (!isset($this->_children[$columnName])) {
                    $this->_children[$columnName] = $rowset;

                return $rowset;
            }

            return $this->_children[$columnName];
        }

        throw new Zend_Db_Table_Row_Exception(sprintf('%s is not in dependent table', $columnName));
    }

/**
     * Query a dependent table to retrieve rows matching the current row.
     *
     * @param string|Zend_Db_Table_Abstract  $dependentTable
     * @param string                         OPTIONAL $ruleKey
     * @param Zend_Db_Table_Select           OPTIONAL $select
     * @return Zend_Db_Table_Rowset_Abstract Query result from $dependentTable
     * @throws Zend_Db_Table_Row_Exception If $dependentTable is not a table or is not loadable.
     */
    public function findDependentRowset($dependentTable, $ruleKey = null, Zend_Db_Table_Select $select = null)
    {
        $db = $this->_getTable()->getAdapter();

        if (is_string($dependentTable)) {
            $dependentTable = $this->_getTableFromString($dependentTable);
        }

        if (!$dependentTable instanceof Zend_Db_Table_Abstract) {
            $type = gettype($dependentTable);
            if ($type == 'object') {
                $type = get_class($dependentTable);
            }
            require_once 'Zend/Db/Table/Row/Exception.php';
            throw new Zend_Db_Table_Row_Exception("Dependent table must be a Zend_Db_Table_Abstract, but it is $type");
        }

        // even if we are interacting between a table defined in a class and a
        // table via extension, ensure to persist the definition
        if (($tableDefinition = $this->_table->getDefinition()) !== null
            && ($dependentTable->getDefinition() == null)) {
            $dependentTable->setOptions(array(Zend_Db_Table_Abstract::DEFINITION => $tableDefinition));
        }

        if ($select === null) {
            $select = $dependentTable->select();
        } else {
            $select->setTable($dependentTable);
        }

        $map = $this->_prepareReference($dependentTable, $this->_getTable(), $ruleKey);

        for ($i = 0; $i < count($map[Zend_Db_Table_Abstract::COLUMNS]); ++$i) {
            $parentColumnName = $db->foldCase($map[Zend_Db_Table_Abstract::REF_COLUMNS][$i]);
            $value = $this->_data[$parentColumnName];
            // Use adapter from dependent table to ensure correct query construction
            $dependentDb = $dependentTable->getAdapter();
            $dependentColumnName = $dependentDb->foldCase($map[Zend_Db_Table_Abstract::COLUMNS][$i]);
            $dependentColumn = $dependentDb->quoteIdentifier($dependentColumnName, true);
            $dependentInfo = $dependentTable->info();
            $type = $dependentInfo[Zend_Db_Table_Abstract::METADATA][$dependentColumnName]['DATA_TYPE'];
            $select->where($dependentInfo['name'].'.'."$dependentColumn = ?", $value, $type);
        }

        return $dependentTable->fetchAll($select);
    }

    public function findDependentRowsetByColumnName($column, $where = null)
    {
        $dependentTables = $this->getTable()->info('dependentTables');
        if (isset($dependentTables[$column])) {

            $dependentTable = $dependentTables[$column];
            if (is_string($dependentTable)) {
                $dependentTable = $this->_getTableFromString($dependentTable);
            }

            if (null === $where || !($where instanceof Zend_Db_Table_Select)) {
                $select = $dependentTable->select();
                if (is_string($where) || $where instanceof Zend_Db_Expr) {
                    $select->where($where);
                }
            } else {
                $select = $where->setTable($dependentTable);
            }

            return $this->findDependentRowset($dependentTables[$column], null, $where)->setRefRow($this);
        }

        throw new Zend_Db_Table_Select_Exception(sprintf('Column %s is not in dependent list', $column));
    }

    public function save()
    {
        $this->_preSave();

        $result = parent::save();

        $this->_postSave();

        return $result;
    }

    /**
     * Set the read-only status of the row.
     *
     * @param boolean $flag
     * @return Centurion_Db_Table_Row_Abstract
     */
    public function setReadOnly($flag)
    {
        parent::setReadOnly($flag);

        return $this;
    }

    /**
     * override these methods to implement pre/post save logic
     */
    protected function _preSave()
    {
        Centurion_Signal::factory('pre_save')->send($this);
    }

    protected function _postSave()
    {
        Centurion_Signal::factory('post_save')->send($this);
    }

    /**
     * Allows pre-insert logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _insert()
    {
        Centurion_Signal::factory('pre_insert')->send($this);
    }

    /**
     * Allows post-insert logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _postInsert()
    {
        Centurion_Signal::factory('post_insert')->send($this);
    }

    /**
     * Allows pre-update logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _update()
    {
        Centurion_Signal::factory('pre_update')->send($this);
    }

    /**
     * Allows post-update logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _postUpdate()
    {
        Centurion_Signal::factory('post_update')->send($this);
    }

    /**
     * Allows pre-delete logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _delete()
    {
        Centurion_Signal::factory('pre_delete')->send($this);
    }

    /**
     * Allows post-delete logic to be applied to row.
     * Subclasses may override this method.
     *
     * @return void
     */
    protected function _postDelete()
    {
        Centurion_Signal::factory('post_delete')->send($this);
    }

    /**
     * Override the getter, if we get and related object of a related object.
     *
     * @param string $tableName
     * @return void
     */
    protected function _getTableFromString($tableName)
    {
        return Centurion_Db::getSingletonByClassName($tableName);
    }

    /**
     * Retrieve the next or previous row.
     *
     * @param string $by                        Column name
     * @param boolean $isNext                   Next row if true, previous instead
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    protected function _getFirstOrLastByField($by, $isFirst = true, $kwargs = null, $select = null)
    {
        $column = Centurion_Inflector::tableize($by);

        if (null === $select) {
            $select = $this->getTable()->select(true);
        }

        return $this->_getFirstOrLastSelectByField($column, $isFirst, $kwargs, $select)
                                                ->limit(1, 0)->fetchRow();
    }

    /**
     * Retrieve the next or previous row.
     *
     * @param string $by                        Column name
     * @param boolean $isNext                   Next row if true, previous instead
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Row_Abstract
     */
    protected function _getNextOrPreviousByField($by, $isNext = true, $kwargs = null, $select = null)
    {
        $column = Centurion_Inflector::tableize($by);

        if (null === $select) {
            $select = $this->getTable()->select(true);
        }

        $select = $this->_getNextOrPreviousSelectByField($column, $isNext, $kwargs, $select);

        return $select->limit(1, 0)->fetchRow();
    }

    /**
     * Retrieve the next or previous row count.
     *
     * @param string $by                        Column name
     * @param boolean $isNext                   Next row if true, previous instead
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return int
     */
    protected function _getNextOrPreviousCountByField($by, $isNext = true, $kwargs = null, $select = null)
    {
        $column = Centurion_Inflector::tableize($by);

        if (null === $select) {
            $select = $this->getTable()->select(true);
        }

        return $this->_getNextOrPreviousSelectByField($column, $isNext, $kwargs, $select)->count();
    }

    /**
     * Remove join in column name.
     * @param $column
     */
    public function _cleanColumn($column)
    {
        $tab = explode(Centurion_Db_Table_Select::RULES_SEPARATOR, $column);
        foreach ($tab as &$rules) {
            $temp = explode(Centurion_Db_Table_Select::JOIN_TYPE_SEPARATOR, $rules);
            if (count($temp) === 2) {
                $rules = $temp[1];
            }
        }

        return implode(Centurion_Db_Table_Select::RULES_SEPARATOR, $tab);
    }

    protected function _getFirstOrLastSelectByField($column, $isFirst = true, $kwargs = null, $select = null)
    {
        $order = $isFirst ? 'ASC' : 'DESC';

        $adapter = $select->getTable()->getAdapter();

        $tableName = $adapter->quoteIdentifier($select->getTable()->info(Zend_Db_Table_Abstract::NAME));

        if (strpos($column, Centurion_Db_Table_Select::RULES_SEPARATOR) !== false) {
            $column = new Zend_Db_Expr($select->addRelated($column));
        } else {
            $column = $adapter->quoteIdentifier($column);
        }

        $select->order(new Zend_Db_Expr(sprintf('%s %s', $column, $order)));

        foreach ($this->_primary as $primary)
            $select->order(sprintf('%s %s', $primary, $order));

        $select->filter($kwargs);

        return $select;
    }

    /**
     * Process the select query
     *
     * @param string $by                        Column name
     * @param boolean $isNext                   Next row if true, previous instead
     * @param array $kwargs                     Arguments passed to the table
     * @param Centurion_Db_Table_Select $select The select used to process the query
     * @return Centurion_Db_Table_Select
     */
    protected function _getNextOrPreviousSelectByField($column, $isNext = true, $kwargs = null, $select = null)
    {
        $op = $isNext ? '<' : '>';
        $order = $isNext ? 'DESC' : 'ASC';

        $adapter = $select->getTable()->getAdapter();

        $tableName = $adapter->quoteIdentifier($select->getTable()->info(Zend_Db_Table_Abstract::NAME));

        if (strpos($column, Centurion_Db_Table_Select::RULES_SEPARATOR) !== false) {
            $cleanColumn = $this->_cleanColumn($column);
            $solumnString = $select->addRelated($column);
            //$column = new Zend_Db_Expr($solumnString);
            list($columnSchema, $column) = explode('.', $solumnString);
            //list($columnSchema, ) = explode(".", $solumnString);
        } else {
            $cleanColumn = $column;
            $column = $adapter->quoteIdentifier($column);
            $columnSchema = $tableName;
        }

        $metadata = $select->getTable()->info(Zend_Db_Table_Abstract::METADATA);

        $dontQuote = false;

        if (isset($metadata[$cleanColumn])) {
            if ($metadata[$cleanColumn]['DATA_TYPE'] === Zend_Db::INT_TYPE || $metadata[$cleanColumn]['DATA_TYPE'] === Zend_Db::BIGINT_TYPE || $metadata[$cleanColumn]['DATA_TYPE'] === Zend_Db::FLOAT_TYPE ) {
                $dontQuote = true;
            }
        }

        $columnData = $this->{$cleanColumn};
        if (!$dontQuote) {
            $columnData = $adapter->quoteInto('?', $columnData);
        }

        if ($columnData === null) {
            $columnData = $adapter->quoteInto('?', $columnData);
        }

        $select->order(new Zend_Db_Expr(sprintf('%s.%s %s', $columnSchema, $column, $order)));

        $expr = sprintf('IFNULL(%s.%s, 0) %s %s', $columnSchema, $column, $op, $columnData);

        $previousColumn = $column;
        $previousColumnData = $columnData;

        foreach ($this->_primary as $primary) {
            $select->order(new Zend_Db_Expr(sprintf('%s.%s %s', $tableName, $primary, $order)));

            $pkData = $adapter->quoteInto('?', $this->{$primary});

            $primary = $adapter->quoteIdentifier($primary);

            $expr .= sprintf(' or (%s.%s = %s and (%s.%s %s %s', $columnSchema, $previousColumn, $previousColumnData, $tableName, $primary, $op, $pkData);
            $previousColumn = $tableName . '.' . $primary;
            $previousColumnData = $pkData;
        }

        $expr .= str_repeat(')', count($this->_primary) * 2);

        $select->where(new Zend_Db_Expr($expr));

        if (null !== $kwargs) {
            $select->filter($kwargs);
        }

        return $select;
    }

    public function getModifiedData()
    {
        return array_intersect_key($this->_data, $this->_modifiedFields);
    }

    protected function _refresh()
    {
        $initialStatus = Centurion_Db_Table_Abstract::getFiltersStatus();
        Centurion_Db_Table_Abstract::setFiltersStatus(Centurion_Db_Table_Abstract::FILTERS_OFF);
        parent::_refresh();
        Centurion_Db_Table_Abstract::setFiltersStatus($initialStatus);
    }

    public function reset()
    {
        $this->_data = $this->_cleanData;
        $this->_modifiedFields = array();
    }
}