<?php

/**
 * A recordset wrapper class for StoreOrder objects
 *
 * @package   Store
 * @copyright 2006-2016 silverorange
 * @see       StoreOrder
 */
class StoreOrderWrapper extends SwatDBRecordsetWrapper
{
	// {{{ protected function init()

	protected function init()
	{
		parent::init();
		$this->row_wrapper_class = SwatDBClassMap::get('StoreOrder');
		$this->index_field = 'id';
	}

	// }}}
}

?>
