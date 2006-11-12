<?php

require_once 'SwatDB/SwatDB.php';
require_once 'Swat/SwatHtmlTag.php';
require_once 'Swat/SwatString.php';
require_once 'Site/exceptions/SiteNotFoundException.php';
require_once 'Store/pages/StorePage.php';
require_once 'Store/dataobjects/StoreArticle.php';
require_once 'Store/dataobjects/StoreArticleWrapper.php';
require_once 'Store/StoreClassMap.php';

/**
 * A page for loading and displaying articles
 *
 * @package   Store
 * @copyright 2005-2006 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 * @see       StoreArticle
 */
class StoreArticlePage extends StorePage
{
	// {{{ protected properties

	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var StoreArticle
	 */
	protected $article;

	// }}}
	// {{{ public function setSource()

	public function setSource($source)
	{
		$this->source = $source;

		if ($this->path === null)
			$this->path = $source;
	}

	// }}}
	// {{{ public function setPath()

	public function setPath($path)
	{
		$this->path = $path;
	}

	// }}}

	// init phase
	// {{{ public function init()

	public function init()
	{
		parent::init();
		$this->initArticle();
		$this->layout->selected_article_id = $this->article->id;
	}

	// }}}
	// {{{ protected function initArticle()

	protected function initArticle()
	{
		// don't try to resolve articles that are deeper than the max depth
		if (count(explode('/', $this->path)) > StoreArticle::MAX_DEPTH)
			throw new SiteNotFoundException(
				sprintf('Article page not found for path ‘%s’', $this->path));

		if (($article_id = $this->findArticle()) === null)
			throw new SiteNotFoundException(
				sprintf('Article page not found for path ‘%s’', $this->path));

		if (($this->article = $this->queryArticle($article_id)) === null)
			throw new SiteNotFoundException(
				sprintf('Article dataobject failed to load for article id ‘%s’',
				$article_id));
	}

	// }}}

	// build phase
	// {{{ public function build()

	public function build()
	{
		parent::build();
		$this->buildArticle();
	}

	// }}}
	// {{{ protected function buildArticle()

	protected function buildArticle()
	{
		$sub_articles = $this->querySubArticles($this->article->id);
		$this->layout->data->title =
			SwatString::minimizeEntities((string)$this->article->title);

		$this->layout->startCapture('content');
		$this->displayArticle($this->article);
		$this->displaySubArticles($sub_articles);
		$this->layout->endCapture();

		$this->layout->navbar->addEntries($this->article->navbar_entries);
	}

	// }}}
	// {{{ protected function findArticle()

	/**
	 * Gets an article database identifier from this page's path
	 *
	 * @return integer the database identifier corresponding to this page's
	 *                  path or null if no such identifier exists.
	 */
	protected function findArticle()
	{
		// trim at 254 to prevent database errors
		$path = substr($this->path, 0, 254);
		$sql = sprintf('select findArticle(%s)',
			$this->app->db->quote($path, 'text'));

		$article_id = SwatDB::queryOne($this->app->db, $sql);
		return $article_id;
	}

	// }}}
	// {{{ protected function queryArticle()

	/**
	 * Gets an article object from the database
	 *
	 * @param integer $id the database identifier of the article to get.
	 *
	 * @return StoreArticle the specified article or null if no such article
	 *                       exists.
	 */
	protected function queryArticle($article_id)
	{
		$sql = 'select * from Article where id = %s and id in
			(select id from EnabledArticleView where region = %s)';

		$sql = sprintf($sql,
			$this->app->db->quote($article_id, 'integer'),
			$this->app->db->quote($this->app->getRegion()->id, 'integer'));

		$class_map = StoreClassMap::instance();
		$wrapper = $class_map->resolveClass('StoreArticleWrapper');
		$articles = SwatDB::query($this->app->db, $sql, $wrapper);
		return $articles->getFirst();
	}

	// }}}
	// {{{ protected function displayArticle()

	/**
	 * Displays an article
	 *
	 * @param StoreArticle $article the article to display.
	 */
	protected function displayArticle(StoreArticle $article)
	{
		if (strlen($article->bodytext) > 0) {
			echo '<div id="article-bodytext">',
				(string)$article->bodytext, '</div>';
		}
	}

	// }}}
	// {{{ protected function displaySubArticles()

	/**
	 * Displays a set of articles as sub-articles
	 *
	 * @param StoreArticleWrapper $articles the set of articles to display.
	 * @param string $path an optional string containing the path to the
	 *                      article being displayed.
	 *
	 * @see StoreArticlePage::displaySubArticle()
	 */
	protected function displaySubArticles(StoreArticleWrapper $articles,
		$path = null)
	{
		if (count($articles) == 0)
			return;

		echo '<ul class="sub-articles">';

		foreach($articles as $article) {
			echo '<li>';
			$this->displaySubArticle($article, $path);
			echo '</li>';
		}

		echo '</ul>';
	}

	// }}}
	// {{{ protected function displaySubArticle()

	/**
	 * Displays an article as a sub-article
	 *
	 * @param StoreArticle $article the article to display.
	 * @param string $path an optional string containing the path to the
	 *                      article being displayed. If no path is provided,
	 *                      the path of the current page is used.
	 */
	protected function displaySubArticle(StoreArticle $article, $path = null)
	{
		if ($path === null)
			$path = $this->path;

		$anchor_tag = new SwatHtmlTag('a');
		$anchor_tag->href = $path.'/'.$article->shortname;
		$anchor_tag->class = 'sub-article';
		$anchor_tag->setContent($article->title);
		$anchor_tag->display();

		if (strlen($article->description) > 0)
			echo ' - ', $article->description;
	}

	// }}}
	// {{{ protected function querySubArticles()

	/**
	 * Gets sub-articles of an article
	 *
	 * @param integer $id the database identifier of the article from which to
	 *                     get sub-articles.
	 *
	 * @return StoreArticleWrapper a recordset of sub-articles of the
	 *                              specified article.
	 */
	protected function querySubArticles($article_id)
	{
		$sql = 'select id, title, shortname, description from Article
			where parent %s %s and id in
				(select id from VisibleArticleView where region = %s)
			order by displayorder, title';

		$sql = sprintf($sql,
			SwatDB::equalityOperator($article_id),
			$this->app->db->quote($article_id, 'integer'),
			$this->app->db->quote($this->app->getRegion()->id, 'integer'));

		$class_map = StoreClassMap::instance();
		$wrapper = $class_map->resolveClass('StoreArticleWrapper');
		return SwatDB::query($this->app->db, $sql, $wrapper);
	}

	// }}}
}

?>
