<?php

require_once "DataTable/Array.php";

class Piwik_Archive_Array extends Piwik_Archive
{	
	protected $archives = array();
	protected $idArchiveToTimestamp = array();
	protected $idArchives = array();
	
	
	function __construct(Piwik_Site $oSite, $strPeriod, $strDate)
	{
		$rangePeriod = new Piwik_Period_Range($strPeriod, $strDate);
		
		// CAREFUL this class wouldnt work as is if handling archives from multiple websites
		// works only when managing archives from multiples dates/periods
		foreach($rangePeriod->getSubperiods() as $subPeriod)
		{
			$startDate = $subPeriod->getDateStart();
			$archive = Piwik_Archive::build($oSite->getId(), $strPeriod, $startDate );
			$archive->prepareArchive();
		
			$timestamp = $archive->getTimestampStartDate();
			$this->archives[$timestamp] = $archive;
		}
		ksort( $this->archives );
	}
	

	protected function sortArchiveByTimestamp($a, $b)
	{
		return $this->idArchiveToTimestamp[$a] > $this->idArchiveToTimestamp[$b];  
	}
	
	protected function getNewDataTableArray()
	{
		$table = new Piwik_DataTable_Array;
		$table->setNameKey('date');
		return $table;
	}

	protected function loadMetaData($table, $archive)
	{
		$table->metaData[$archive->getPrettyDate()] = array( 
				'timestamp' => $archive->getTimestampStartDate(),
				'site' => $archive->getSite(),
			);
	}
	
	/**
	 * Returns the value of the element $name from the current archive 
	 * The value to be returned is a numeric value and is stored in the archive_numeric_* tables
	 *
	 * @param string $name For example Referers_distinctKeywords 
	 * @return float|int|false False if no value with the given name
	 */
	public function getNumeric( $name )
	{
		require_once "DataTable/Simple.php";
		$table = $this->getNewDataTableArray();
		
		foreach($this->archives as $archive)
		{
			$numeric = $archive->getNumeric( $name ) ;
			$subTable = new Piwik_DataTable_Simple();
			$subTable->loadFromArray( array( $numeric ) );
			$table->addTable($subTable, $archive->getPrettyDate());
			
			$this->loadMetaData($table, $archive);
		}
		
		return $table;
	}
	
	/**
	 * Returns the value of the element $name from the current archive
	 * 
	 * The value to be returned is a blob value and is stored in the archive_numeric_* tables
	 * 
	 * It can return anything from strings, to serialized PHP arrays or PHP objects, etc.
	 *
	 * @param string $name For example Referers_distinctKeywords 
	 * @return mixed False if no value with the given name
	 */
	public function getBlob( $name )
	{
		require_once "DataTable/Simple.php";
		$table = $this->getNewDataTableArray();
		
		foreach($this->archives as $archive)
		{
			$blob = $archive->getBlob( $name ) ;
			$subTable = new Piwik_DataTable_Simple();
			$subTable->loadFromArray( array('blob' => $blob));
			$table->addTable($subTable, $archive->getPrettyDate());
			
			$this->loadMetaData($table, $archive);
		}
		return $table;
	}
	
	/**
	 * Given a list of fields defining numeric values, it will return a Piwik_DataTable_Array
	 * which is an array of Piwik_DataTable_Simple, ordered by chronological order
	 *
	 * @param array $fields array( fieldName1, fieldName2, ...)
	 * @return Piwik_DataTable_Array
	 */
	public function getDataTableFromNumeric( $fields )
	{
		// Simple algorithm not efficient
//		$table = new Piwik_DataTable_Array;
//		foreach($this->archives as $archive)
//		{
//			$subTable =  $archive->getDataTableFromNumeric( $fields ) ;
//			$table->addTable($subTable, $archive->getPrettyDate());
//		}
//		return $table;

//		$fields = $fields[1];
		require_once "DataTable/Simple.php";
		if(!is_array($fields))
		{
			$fields = array($fields);
		}
		
		$inName = "'" . implode("', '",$fields) . "'";
		
		
		// we select in different shots
		// one per distinct table (case we select last 300 days, maybe we will  select from 10 different tables)
		$queries = array();
		foreach($this->archives as $archive) 
		{		
			if(!$archive->isThereSomeVisits)
			{
				continue;
			}
			
			$table = $archive->archiveProcessing->getTableArchiveNumericName();

			// for every query store IDs
			$queries[$table][] = $archive->getIdArchive();
		}

		// we select the requested value
		$db = Zend_Registry::get('db');
		
		// date => array( 'field1' =>X, 'field2'=>Y)
		// date2 => array( 'field1' =>X2, 'field2'=>Y2)		
		
		$idarchiveToName = array();
		foreach($queries as $table => $aIds)
		{
			$inIds = implode(', ', $aIds);
			$sql = "SELECT value, name, idarchive, UNIX_TIMESTAMP(date1) as timestamp
									FROM $table
									WHERE idarchive IN ( $inIds )
										AND name IN ( $inName )";

			$values = $db->fetchAll($sql);
			
			foreach($values as $value)
			{
				$idarchiveToName[$value['timestamp']][$value['name']] = $value['value'];
			}			
		}
		
		// we add empty tables so that every requested date has an entry, even if there is nothing
		// example: <result date="2007-01-01" />
		$emptyTable = new Piwik_DataTable_Simple;
		foreach($this->archives as $timestamp => $archive)
		{
			$strDate = $this->archives[$timestamp]->getPrettyDate();
			$contentArray[$timestamp]['table'] = clone $emptyTable;
			$contentArray[$timestamp]['prettyDate'] = $strDate;
		}
		
		foreach($idarchiveToName as $timestamp => $aNameValues)
		{
			$contentArray[$timestamp]['table']->loadFromArray($aNameValues);
		}
		
		ksort( $contentArray );
				
		$tableArray = $this->getNewDataTableArray();
		foreach($contentArray as $timestamp => $aData)
		{
			$tableArray->addTable($aData['table'], $aData['prettyDate']);
			
			$this->loadMetaData($tableArray, $this->archives[$timestamp]);
		}
		
//		echo $tableArray;exit;
		return $tableArray;
	}

	/**
	 * Given a BLOB field name (eg. 'Referers_searchEngineByKeyword'), it will return a Piwik_DataTable_Array
	 * which is an array of Piwik_DataTable, ordered by chronological order
	 * 
	 * @param string $name
	 * @param int $idSubTable
	 * @return Piwik_DataTable
	 * @throws exception If the value cannot be found
	 */
	public function getDataTable( $name, $idSubTable = null )
	{		
		$table = $this->getNewDataTableArray();		
		foreach($this->archives as $archive)
		{
			$subTable =  $archive->getDataTable( $name, $idSubTable ) ;
			$table->addTable($subTable, $archive->getPrettyDate());
			
			$this->loadMetaData($table, $archive);
		}
		return $table;
	}
	
	
	/**
	 * Same as getDataTable() except that it will also load in memory
	 * all the subtables for the DataTable $name. 
	 * You can then access the subtables by using the Piwik_DataTable_Manager getTable() 
	 *
	 * @param string $name
	 * @param int $idSubTable
	 * @return Piwik_DataTable
	 */
	public function getDataTableExpanded($name, $idSubTable = null)
	{
		$table = $this->getNewDataTableArray();
		foreach($this->archives as $archive)
		{
			$subTable =  $archive->getDataTableExpanded( $name, $idSubTable ) ;
			$table->addTable($subTable, $archive->getPrettyDate());
			
			$this->loadMetaData($table, $archive);
		}
		return $table;
	}
}
