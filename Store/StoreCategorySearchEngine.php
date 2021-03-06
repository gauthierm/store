<?php

/**
 * A category search engine
 *
 * @package   Store
 * @copyright 2007-2016 silverorange
 */
class StoreCategorySearchEngine extends SiteSearchEngine
{
	// {{{ protected function getResultWrapperClass()

	protected function getResultWrapperClass()
	{
		$wrapper_class = SwatDBClassMap::get('StoreCategoryWrapper');

		return $wrapper_class;
	}

	// }}}
	// {{{ protected function getSelectClause()

	protected function getSelectClause()
	{
		$clause = 'select Category.id, Category.title, Category.shortname,
				Category.image, c.product_count, c.region as region_id';

		return $clause;
	}

	// }}}
	// {{{ protected function getFromClause()

	protected function getFromClause()
	{
		$clause = sprintf('from Category
			inner join CategoryVisibleProductCountByRegionCache as c
				on c.category = Category.id and c.region = %s',
			$this->app->db->quote($this->app->getRegion()->id, 'integer'));

		if ($this->fulltext_result !== null)
			$clause.= ' '.
				$this->fulltext_result->getJoinClause('Category.id', 'category');

		return $clause;
	}

	// }}}
	// {{{ protected function getOrderByClause()

	protected function getOrderByClause()
	{
		if ($this->fulltext_result === null)
			$clause = sprintf('order by Category.title');
		else
			$clause =
				$this->fulltext_result->getOrderByClause('Category.title');

		return $clause;
	}

	// }}}
	// {{{ protected function getWhereClause()

	protected function getWhereClause()
	{
		$clause = sprintf('where id in
			(select Category from VisibleCategoryView
			where region = %s or region is null)',
			$this->app->db->quote($this->app->getRegion()->id, 'integer'));

		return $clause;
	}

	// }}}
	// {{{ protected function getMemcacheNs()

	protected function getMemcacheNs()
	{
		return 'product';
	}

	// }}}
}

?>
