<?php

/**
 * A recordset wrapper class for StoreRegion objects
 *
 * @package   Store
 * @copyright 2006-2016 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class StoreRegionWrapper extends SwatDBRecordsetWrapper
{
	// {{{ public static function loadSetFromDB()

	public static function loadSetFromDB($db, $id_set)
	{
		$sql = 'select * from Region where id in (%s)';

		$sql = sprintf($sql, $id_set);
		return SwatDB::query($db, $sql, 'RegionWrapper');
	}

	// }}}
	// {{{ protected function init()

	protected function init()
	{
		parent::init();

		$this->row_wrapper_class = SwatDBClassMap::get('StoreRegion');

		$this->index_field = 'id';
	}

	// }}}
}

?>
