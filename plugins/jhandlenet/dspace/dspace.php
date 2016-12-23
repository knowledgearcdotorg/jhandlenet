<?php
defined( '_JEXEC' ) or die( 'Restricted access' );
/**
* A plugin for creating handles from DSpace.
 *
 * @package     JHandleNet.Plugins
 * @copyright   Copyright (C) 2013 Wijiti Pty Ltd. All rights reserved.
 * @license     This file is part of the JHandleNet DSpace plugin for Joomla!.
 *
 * The JHandleNet DSpace plugin for Joomla! is free software: you can 
 * redistribute it and/or modify it under the terms of the GNU General Public 
 * License as published by the Free Software Foundation, either version 3 of 
 * the License, or (at your option) any later version.
 *
 * The JHandleNet DSpace plugin for Joomla! is distributed in the hope 
 * that it will be useful, but WITHOUT ANY WARRANTY; without even the implied 
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with the JHandleNet DSpace plugin for Joomla!.  If not, see 
 * <http://www.gnu.org/licenses/>.
 *
 * Contributors
 * Please feel free to add your name and email (optional) here if you have 
 * contributed any source code changes.
 * 
 */

jimport('joomla.error.log');
jimport('jspace.factory');

class PlgJHandleNetDSpace extends JPlugin
{
	private $_na = null;
	
	private $_connector = null;
	
	protected static $chunk;
	
	public function __construct(&$subject, $config = array())
	{	
		parent::__construct($subject, $config);
		
		Jlog::addLogger(array('text_file'=>'jhandlenet.php'), JLog::ALL, 'jhandlenet');
		
		static::$chunk = 5;
	}
	
	private function _getNA()
	{
		if ($this->_na === null) {
			$params = JComponentHelper::getParams('com_jhandlenet');
			
			$option['driver']   = 'mysqli';
			$option['host']     = $params->get('host').':'.$params->get('port');
			$option['user']     = $params->get('username');
			$option['password'] = $params->get('password');
			$option['database'] = $params->get('database');
			$option['prefix']   = '';
			
			$db = JDatabaseDriver::getInstance($option);
			
			JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_jhandlenet/tables');
			$this->_na = JTable::getInstance('NA', 'JHandleNetTable', array('dbo'=>$db));
		}
		
		return $this->_na;
	}

	private function _setConnector($params)
	{	
		$options = array();
		$options['driver'] = 'DSpace';
		$options['url'] = $params->get('archive_endpoint');
		$options['username'] = $params->get('archive_username');
		$options['password'] = $params->get('archive_password');

		$this->_connector = JSpaceFactory::getConnector($options);
	}
	
	private function _getConnector()
	{
		return $this->_connector;
	}
	
	public function onHandlesPurge()
	{
		
	}
	
	public function onHandlesCreate($na)
	{
		if (!$this->_getNA()->load($na)) {
			throw new Exception('No such naming authority.');
		}
		
		if (!class_exists('JSpaceFactory')) {
			throw new Exception('JSpace library not installed.');
		}

		$this->_setConnector($this->_getNA());
		
		$db = $this->_getNA()->getDbo();
		
		$this->_insert($this->getItems());
	}
	
	public function onHandlesUpdate($na)
	{
		if (!$this->_getNA()->load($na)) {
			throw new Exception('No such naming authority.');
		}
		
		if (!class_exists('JSpaceFactory')) {
			throw new Exception('JSpace library not installed.');
		}
		
		$this->_setConnector($this->_getNA());
		
		$db = $this->_getNA()->getDbo();
		
		$query = $db->getQuery(true);
		$query
			->select('MAX(data)')
			->from('handles');
		
		$db->setQuery($query);

		$filters = array();

		if (($max = $db->loadResult()) !== null) {
			$filters[] = "search.resourceid:[".($max+1)." TO *]";
		}

		$this->_insert($this->getItems(0, null, $filters));
	}
	
	public function onHandlesClean($na)
	{
		if (!$this->_getNA()->load($na)) {
			throw new Exception('No such naming authority.');
		}
		
		if (!class_exists('JSpaceFactory')) {
			throw new Exception('JSpace library not installed.');
		}
		
		$this->_setConnector($this->_getNA());
		
		$table = $this->_getNA();
		
		$db = $table->getDbo();
		
		$array = array();
		
		$query = $db->getQuery(true);
		$query
		->select('na, handle')
		->from('handles')
		->order('handle asc');
		
		$db->setQuery($query);
		
		$filters = array();
		
		$items = $this->getItems();
		
		// @todo Need a better search algorithm?
		foreach ($db->loadObjectList() as $row) {
			$found = false;
			$handle = $row->handle;			
			
			reset($items);
			
			while (($item = current($items)) && !$found) {
				$found = ($handle == $item->handle);					
				next($items);
			}
			
			if (!$found) {
				$query = $db->getQuery(true);
				$query
				->delete('handles')
				->where("handle = '".$handle."'");
			}
		}	
	}
	
	/**
	 * Gets all DSpace items using the JSpace component and DSpace REST API.
	 *
	 * @return array A list of DSpace items.
	 */
	protected function getItems($start = 0, $limit = null, $filters = array())
	{
		$items = array();
	
		try {
			$items = array();
				
			$connector = $this->_getConnector();
			
			$fq = array(); 
			$fq[] = 'search.resourcetype:2';
			$fq = array_merge($fq, $filters);

			$vars = array();
			$vars['q'] = '*:*';
			$vars['fl'] = 'search.resourceid,handle';
			$vars['start'] = $start;
			
			if ($limit) {
				$vars['rows'] = $limit;
			} else {
				$vars['rows'] = '2147483647';
			}

			// for some reason we have to url encode here. Looks like the JSpace connector has some bugs.
			$vars['sort'] = rawurlencode('handle asc');
		
			$vars['fq'] = rawurlencode(implode(' AND ', $fq));

			$response = json_decode($connector->get(JSpaceFactory::getEndpoint('/discover.json', $vars), false));

			if (isset($response->response->docs)) {
				$items = $response->response->docs;
			}			
		} catch (Exception $e) {
			JLog::add($e->getMessage(), JLog::ERROR);
		}
	
		return $items;
	}
	
	private function _insert($items)
	{
		$na = $this->_getNA();
		
		$db = $na->getDbo();
		
		$array = array();
		
		foreach ($items as $item) {
			$handle = $item->handle;
			$handle = JArrayHelper::getValue(explode('/', $handle), 1);
			$handle = $na->na.'/'.$handle;
				
			$id = $item->{"search.resourceid"};
				
			$row = array();
				
			$row[] = "'$handle'";	// handle
			$row[] = "1";			// idx (always 1 for this type of handle)
			$row[] = "'URL'";		// type (always URL)
			$row[] = "'$id'"; 		// data (should only be the id of the actual item)
			$row[] = '0';			// ttl_type (always 0)
			$row[] = '86400';		// ttl
			$row[] = 'NOW()';		// timestamp (NOW())
			$row[] = "''";			// refs (empty string)
			$row[] = '1';			// admin_read (always 1)
			$row[] = '0'; 			// admin_write (should be 0 to stop handle.net writing to the db)
			$row[] = '1'; 			// pub_read (always 1)
			$row[] = '0';			// pub_write (always 0)
			$row[] = "'".$na->na."'";	// na
				
			$array[] = implode(',',$row);
				
			if (count($array) == count($items) || count($array) == static::$chunk) {
				$query = $db->getQuery(true);
				$query
				->insert('handles')
				->values($array);
		
				$db->setQuery($query);
				$db->execute();
		
				$array = array();
			}
		}
	}
}
