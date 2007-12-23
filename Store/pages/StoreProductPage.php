<?php

require_once 'Swat/SwatString.php';
require_once 'Swat/SwatTableStore.php';
require_once 'Swat/SwatDetailsStore.php';
require_once 'Swat/SwatYUI.php';
require_once 'Swat/SwatUI.php';
require_once 'Store/pages/StorePage.php';
require_once 'Store/dataobjects/StoreCartEntry.php';
require_once 'Store/dataobjects/StoreProduct.php';
require_once 'Store/dataobjects/StoreCategory.php';
require_once 'Store/StoreItemsView.php';
require_once 'Store/StoreMessage.php';
require_once 'Store/StoreMessageDisplay.php';

/**
 * A product page
 *
 * @package   Store
 * @copyright 2005-2007 silverorange
 */
class StoreProductPage extends StorePage
{
	// {{{ public properties

	public $product_id;
	public $product = null;

	// }}}
	// {{{ protected properties

	protected $items_view;
	protected $cart_ui;
	protected $cart_ui_xml = 'Store/pages/product-cart.xml';
	protected $message_display;
	protected $cart_message;
	protected $item_removed = false;
	protected $added_entry_ids = array();
	protected $saved_entry_ids = array();
	protected $default_quantity = 0;

	/**
	 * @var SiteArticleWrapper
	 */
	protected $related_articles;

	// }}}

	// init phase
	// {{{ public function init()

	public function init()
	{
		parent::init();

		$this->message_display = new StoreMessageDisplay();
		$this->message_display->id = 'cart_message_display';
		$this->message_display->init();

		$this->initProduct();
		$this->initItemsView();
		$this->initCart();
	}

	// }}}
	// {{{ public function isVisibleInRegion()

	public function isVisibleInRegion(StoreRegion $region)
	{
		$sql = sprintf('select product from VisibleProductCache
			where product = %s and  region = %s',
			$this->app->db->quote($this->product_id, 'integer'),
			$this->app->db->quote($region->id, 'integer'));

		$product = SwatDB::queryOne($this->app->db, $sql);

		return ($product !== null);
	}

	// }}}
	// {{{ protected function initProduct()

	protected function initProduct()
	{
		$this->loadProduct($this->product_id);
	}

	// }}}
	// {{{ protected function initItemsView()

	protected function initItemsView()
	{
		$this->items_view = $this->getItemsView();
		$this->items_view->setDatabase($this->app->db);
		$this->items_view->setProduct($this->product);
		$this->items_view->setSource($this->source);
		$this->items_view->setRegion($this->app->getRegion());

		$this->items_view->init();
	}

	// }}}
	// {{{ protected function getItemsView()

	protected function getItemsView()
	{
		return new StoreItemsView();
	}

	// }}}
	// {{{ protected function initCart()

	protected function initCart()
	{
		$this->cart_ui = new SwatUI();
		$this->cart_ui->loadFromXML($this->cart_ui_xml);
		$this->cart_ui->getRoot()->addStyleSheet(
			'packages/store/styles/store-cart.css', Store::PACKAGE_ID);

		if ($this->cart_ui->hasWidget('cart_form')) {
			$cart_form = $this->cart_ui->getWidget('cart_form');
			$cart_form->action = $this->source;
		}

		$this->cart_ui->init();
	}

	// }}}
	// {{{ protected function loadProduct()

	protected function loadProduct($id)
	{
		$product_class = SwatDBClassMap::get('StoreProduct');
		$this->product = new $product_class();
		$this->product->setDatabase($this->app->db);
		$this->product->setRegion($this->app->getRegion());
		$this->product->load($id);
	}

	// }}}

	// process phase
	// {{{ public function process()

	public function process()
	{
		parent::process();
		$this->message_display->process();
		$this->processProduct();
		$this->processCart();
	}

	// }}}
	// {{{ protected function processProduct()

	protected function processProduct()
	{
		$this->items_view->process();

		if ($this->items_view->hasMessage()) {
			$message = new SwatMessage(Store::_('There is a problem with '.
				'one or more of the items you requested.'),
				SwatMessage::ERROR);

			$message->secondary_content = Store::_('Please address the '.
				'fields highlighted below and re-submit the form.');

			$this->message_display->add($message);
		}

		$items = $this->items_view->getItemsToAdd();
		$num_items_added = 0;

		foreach ($items as $item) {
			$cart_entry = $this->createCartEntry($item->item_id);
			$cart_entry->quantity = $item->quantity;

			$added_entry = $this->app->cart->checkout->addEntry($cart_entry);

			if ($added_entry !== null) {
				$this->added_entry_ids[] = $added_entry->id;
				$num_items_added++;
			}

			if ($num_items_added > 0) {
				$this->cart_message = new StoreMessage(
					Store::_('Your cart has been updated.'),
					StoreMessage::CART_NOTIFICATION);
			}

			// add cart messages
			$messages = $this->app->cart->checkout->getMessages();
			foreach ($messages as $message)
				$this->message_display->add($message);
		}
	}

	// }}}
	// {{{ protected function createCartEntry()

	protected function createCartEntry($item_id)
	{
		$cart_entry_class = SwatDBClassMap::get('StoreCartEntry');
		$cart_entry = new $cart_entry_class();

		$this->app->session->activate();

		if ($this->app->session->isLoggedIn())
			$cart_entry->account =
				$this->app->session->getAccountId();
		else
			$cart_entry->sessionid =
				$this->app->session->getSessionId();

		// load item manually here so we can specify region
		$item_class = SwatDBClassMap::get('StoreItem');
		$item = new $item_class();
		$item->setDatabase($this->app->db);
		$item->setRegion($this->app->getRegion());
		$item->load($item_id);

		$cart_entry->item = $item;
		$cart_entry->quick_order = false;

		return $cart_entry;
	}

	// }}}
	// {{{ protected function processCart()

	protected function processCart()
	{
		$this->cart_ui->process();

		if (!$this->cart_ui->hasWidget('cart_view'))
			return;

		$view = $this->cart_ui->getWidget('cart_view');

		// check for removed items
		$remove_column = $view->getColumn('remove_column');
		$remove_renderer = $remove_column->getRendererByPosition();
		foreach ($remove_renderer->getClonedWidgets() as $id => $widget) {
			if ($widget->hasBeenClicked()) {
				$this->item_removed = true;
				$this->app->cart->checkout->removeEntryById($id);
				$this->message_display->add(new StoreMessage(
					Store::_('An item has been removed from your cart.'),
						StoreMessage::CART_NOTIFICATION));
				break;
			}
		}
	}

	// }}}

	// build phase
	// {{{ public function build()

	public function build()
	{
		parent::build();

		$this->buildCart();
		$this->buildProduct();
		$this->buildNavBar();

		$this->layout->startCapture('content');
		$this->message_display->display();
		$this->displayProduct();
		Swat::displayInlineJavaScript($this->getProductInlineJavaScript());
		Swat::displayInlineJavaScript($this->getCartInlineJavaScript());
		$this->layout->endCapture();
	}

	// }}}
	// {{{ protected function buildProduct()

	protected function buildProduct()
	{
		$this->layout->data->title =
			SwatString::minimizeEntities($this->product->title);

		$this->layout->data->meta_description =
			SwatString::minimizeEntities(SwatString::condense(
			SwatString::stripXHTMLTags($this->product->bodytext, 400)));
	}

	// }}}
	// {{{ protected function buildCart()

	protected function buildCart()
	{
		$cart_view = $this->cart_ui->getWidget('cart_view');
		$cart_view->model = $this->getCartTableStore();
		$count = count($cart_view->model);

		if ($count > 0) {
			if ($this->cart_message === null) {
				$this->cart_message = new StoreMessage(null,
					StoreMessage::CART_NOTIFICATION);

				$this->cart_message->primary_content = Store::ngettext(
					'The following item on this page is in your cart:',
					'The following items on this page are in your cart:',
					$count);

				$this->cart_message->secondary_content =
					Store::_('You may continue shopping by following any of '.
						'the links on this page.');
			}

			ob_start();
			$this->cart_ui->display();

			echo '<div class="cart-message-links">';
			$this->displayCartLinks();
			echo '</div>';

			$this->cart_message->secondary_content = ob_get_clean();
			$this->cart_message->content_type = 'text/xml';
			$this->message_display->add($this->cart_message);

		} elseif ($this->cart_message !== null) {
			$this->message_display->add($this->cart_message);
		}
	}

	// }}}
	// {{{ protected function displayCartLinks()

	protected function displayCartLinks()
	{
		printf(Store::_(
			'%sView your shopping cart%s or %sproceed to the checkout%s.'),
			'<a href="cart">', '</a>',
			'<a href="checkout">', '</a>');
	}

	// }}}
	// {{{ protected function buildNavBar()

	protected function buildNavBar()
	{
		$link = 'store';

		foreach ($this->path as $path_entry) {
			$link .= '/'.$path_entry->shortname;
			$this->layout->navbar->createEntry($path_entry->title, $link);
		}

		if ($this->product !== null)
			$this->layout->navbar->createEntry($this->product->title);
	}

	// }}}
	// {{{ protected function getCartTableStore()

	/**
	 * Gets the cart data-store for the product on this page
	 */
	protected function getCartTableStore()
	{
		$store = new SwatTableStore();

		$entries = $this->app->cart->checkout->getEntries();
		foreach ($entries as $entry) {
			// filter entries by item
			if ($this->isOnThisPage($entry->item)) {
				$ds = $this->getCartDetailsStore($entry);
				$store->add($ds);
			}
		}

		return $store;
	}

	// }}}
	// {{{ protected function getCartDetailsStore()

	protected function getCartDetailsStore(StoreCartEntry $entry)
	{
		$ds = new SwatDetailsStore($entry);

		$ds->quantity = $entry->getQuantity();
		$ds->description = $entry->getDescription();
		$ds->price = $entry->getCalculatedItemPrice();
		$ds->extension = $entry->getExtension();
		$ds->discount = $entry->getDiscount();
		$ds->discount_extension = $entry->getDiscountExtension();

		return $ds;
	}

	// }}}
	// {{{ protected function isOnThisPage()

	protected function isOnThisPage(StoreItem $item)
	{
		return ($item->product->id === $this->product_id);
	}

	// }}}
	// {{{ protected function displayRelatedArticles()

	/**
	 * Displays related articles from the parent category on this product page
	 */
	protected function displayRelatedArticles()
	{
		$related_articles = $this->getRelatedArticles();
		if (count($related_articles) > 0) {
			$dl_tag = new SwatHtmlTag('dl');
			$dl_tag->id = 'related_articles';
			$dl_tag->open();

			$anchor_tag = new SwatHtmlTag('a');
			$dd_tag = new SwatHtmlTag('dd');
			$dt_tag = new SwatHtmlTag('dt');
			foreach ($related_articles as $article) {
				$dt_tag->open();

				$this->displayRelatedArticlesTitle();
				$anchor_tag->href = $article->path;
				$anchor_tag->setContent($article->title);
				$anchor_tag->display();

				$dt_tag->close();

				// spaces are non-breaking
				$anchor_tag->setContent('read more »');

				$bodytext = SwatString::condense($article->bodytext, null);

				// spaces are non-breaking
				$bodytext = SwatString::ellipsizeRight($bodytext, 200,
					' … '.$anchor_tag->toString());

				$dd_tag->setContent($bodytext, 'text/xml');

				$dd_tag->display();
			}

			$dl_tag->close();
		}
	}

	// }}}
	// {{{ protected function displayRelatedArticleLinks()

	/**
	 * Displays related articles links from the parent category on this product
	 * page
	 */
	protected function displayRelatedArticleLinks()
	{
		$related_articles = $this->getRelatedArticles();
		if (count($related_articles) > 0) {
			$div_tag = new SwatHtmlTag('div');
			$div_tag->id = 'related_article_links';
			$div_tag->open();

			$this->displayRelatedArticlesTitle();

			$anchor_tag = new SwatHtmlTag('a');
			$first = true;
			foreach ($related_articles as $article) {
				if ($first)
					$first = false;
				else
					echo ', ';

				$anchor_tag->href = $article->path;
				$anchor_tag->setContent($article->title);
				$anchor_tag->display();
			}

			$div_tag->close();
		}
	}

	// }}}
	// {{{ protected function displayRelatedArticlesTitle()

	protected function displayRelatedArticlesTitle()
	{
		echo Store::_('Related Articles: ');
	}

	// }}}
	// {{{ protected function displayProduct()

	protected function displayProduct()
	{
		$this->displayImages();

		echo '<div id="product_contents">';

		$this->displayBodyText();

		$this->displayRelatedArticleLinks();

		$this->displayItems();

		$this->displayRelatedProducts();

		$this->displayPopularProducts();

		echo '</div>';
	}

	// }}}
	// {{{ protected function displayImages()

	protected function displayImages()
	{
		if ($this->product->primary_image !== null) {
			echo '<div id="product_images">';
			$this->displayImage();

			if (count($this->product->images) > 1)
				$this->displaySecondaryImages();

			echo '</div>';
		}

	}

	// }}}
	// {{{ protected function displayImage()

	protected function displayImage()
	{
		$image = $this->product->primary_image;
		$div = new SwatHtmlTag('div');
		$div->id = 'product_image';

		$img_tag = $image->getImgTag('small');

		if ($img_tag->alt === '')
			$img_tag->alt = sprintf(Store::_('Photo of %s'),
				$this->product->title);

		$link_to_large = true;
		if ($image->small_width > 0) {
			$percentage_larger = ($image->large_width / $image->small_width) - 1;
			// large must be at least 10% larger
			if ($percentage_larger < 0.10)
				$link_to_large = false;
		}

		if ($link_to_large) {
			$anchor = new SwatHtmlTag('a');
			$anchor->href = $this->source.'/image';
			$anchor->class = 'large-image-wrapper';
			$anchor->title = Store::_('View Larger Image');
		}

		$div->open();

		if ($link_to_large) {
			$anchor->open();
			$img_tag->display();
			echo '<span>', Store::_('View Larger Image'), '</span>';
			$anchor->close();
		} else {
			echo '<span class="large-image-wrapper">';
			$img_tag->display();
			echo '</span>';
		}

		$div->close();
	}

	// }}}
	// {{{ protected function displaySecondaryImages()

	protected function displaySecondaryImages()
	{
		echo '<ul id="product_secondary_images" class="clearfix">';

		foreach ($this->product->images as $image)
			if ($this->product->primary_image->id !== $image->id)
				$this->displaySecondaryImage($image);

		echo '</ul>';
	}

	// }}}
	// {{{ protected function displaySecondaryImage()

	protected function displaySecondaryImage($image)
	{
		$li_tag = new SwatHtmlTag('li');
		$img_tag = new SwatHtmlTag('img');
		$img_tag = $image->getImgTag('thumb');

		if (strlen($img_tag->alt) == 0)
			$img_tag->alt = sprintf(Store::_('Additional Photo of %s'),
				$this->product->title);

		$anchor = new SwatHtmlTag('a');
		$anchor->href = sprintf('%s/image%s', $this->source, $image->id);
		$anchor->title = Store::_('View Larger Image');

		$li_tag->open();
		$anchor->open();
		$img_tag->display();
		echo '<span>', Store::_('View Larger Image'), '</span>';
		$anchor->close();
		$li_tag->close();
	}

	// }}}
	// {{{ protected function displayBodyText()

	protected function displayBodyText()
	{
		$div = new SwatHtmlTag('div');
		$div->id = 'product_bodytext';
		$div->setContent(
			SwatString::toXHTML($this->product->bodytext), 'text/xml');

		$div->display();
	}

	// }}}
	// {{{ protected function displayItems()

	protected function displayItems()
	{
		$this->items_view->display();
	}

	// }}}
	// {{{ protected function displayRelatedProducts()

	protected function displayRelatedProducts()
	{
		$related_products = $this->product->getVisibleRelatedProducts();

		if (count($related_products) == 0)
			return;

		$div = new SwatHtmlTag('div');
		$div->id = 'related_products';

		$header_tag = new SwatHtmlTag('h4');
		$header_tag->setContent(Store::_('You might also be interested in...'));

		$ul_tag = new SwatHtmlTag('ul');
		$ul_tag->class = 'store-product-list clearfix';

		$li_tag = new SwatHtmlTag('li');
		$li_tag->class = 'store-product-icon';

		$div->open();
		$header_tag->display();
		$ul_tag->open();

		foreach ($related_products as $product) {
			$li_tag->open();
			$path = 'store/'.$product->path;
			$product->displayAsIcon($path);
			$li_tag->close();
		}

		$ul_tag->close();
		$div->close();
	}

	// }}}
	// {{{ protected function displayPopularProducts()

	protected function displayPopularProducts()
	{
		$popular_products = $this->product->getVisiblePopularProducts(2, 5);

		if (count($popular_products) == 0)
			return;

		$div = new SwatHtmlTag('div');
		$div->id = 'popular_products';

		$header_tag = new SwatHtmlTag('h4');
		$header_tag->setContent(sprintf(Store::_('Customers who bought %s, '.
			'also bought...'),
			$this->product->title));

		$ul_tag = new SwatHtmlTag('ul');
		$ul_tag->class = 'store-product-list clearfix';

		$li_tag = new SwatHtmlTag('li');
		$li_tag->class = 'store-product-icon';

		$div->open();
		$header_tag->display();
		$ul_tag->open();

		foreach ($popular_products as $product) {
			$li_tag->open();
			$path = 'store/'.$product->path;
			$product->displayAsIcon($path);
			$li_tag->close();
		}

		$ul_tag->close();
		$div->close();
	}

	// }}}
	// {{{ protected function getProductInlineJavaScript()

	protected function getProductInlineJavaScript()
	{
		static $translations_displayed = false;

		$item_ids = array();
		foreach ($this->product->items as $item)
			if ($item->isEnabled())
				$item_ids[] = $item->id;

		$item_ids = "'".implode("', '", $item_ids)."'";

		$javascript = '';
		if (!$translations_displayed) {
			$javascript.= sprintf(
				"StoreProductPage.enter_quantity_message = '%s';\n",
				Store::_('Please enter a quantity.'));

			$translations_displayed = true;
		}

		$javascript.= sprintf(
			"var product_page = new StoreProductPage([%s]);",
			$item_ids);

		return $javascript;
	}

	// }}}
	// {{{ protected function getCartInlineJavaScript()

	/**
	 * @see StoreProductPage::getCartAnimationFrames()
	 */
	protected function getCartInlineJavaScript()
	{
		$javascript = '';

		$frames = $this->getCartAnimationFrames();

		// only show animation if some animation frames are defined
		if (count($frames) > 0) {
			$frames_list = "['".implode("', '", $frames)."']";

			foreach ($this->added_entry_ids as $id) {
				$javascript.= sprintf(
					"var animation_%1\$s = new StoreBackgroundImageAnim(".
					"'entry_%1\$s', { frames: { from: 1, to: %2\$s } }, 2);\n".
					"animation_%1\$s.addFrameImages(%3\$s);\n".
					"animation_%1\$s.animate();\n",
					$id, count($frames), $frames_list);
			}
		}

		return $javascript;
	}

	// }}}
	// {{{ protected function getCartAnimationFrames()

	/**
	 * Gets the animation frames to use for animating the background cells of
	 * added cart entries on the product page cart display.
	 *
	 * By default, no animation frames are defined. Subclasses should define
	 * a set of animation frames by extending this method if they want to use
	 * the background image animation effect on added cart entries.
	 *
	 * @return array an array of image URL fragments for the animation frames.
	 */
	protected function getCartAnimationFrames()
	{
		return array();
	}

	// }}}
	// {{{ protected function getRelatedArticles()

	/**
	 * Gets related articles from the direct parent category of the product
	 * on this page
	 */
	protected function getRelatedArticles()
	{
		if ($this->related_articles === null) {
			$last_entry = $this->path->getLast();
			if ($last_entry !== null) {
				$category_class = SwatDBClassMap::get('StoreCategory');
				$category = new $category_class();
				$category->id = $last_entry->id;
				$category->setDatabase($this->app->db);
				$this->related_articles = $category->related_articles;
			}
		}
		return $this->related_articles;
	}

	// }}}

	// finalize phase
	// {{{ public function finalize()

	public function finalize()
	{
		parent::finalize();
		$yui = new SwatYUI(array('event'));
		$this->layout->addHtmlHeadEntrySet($yui->getHtmlHeadEntrySet());
		$this->layout->addHtmlHeadEntry(new SwatJavaScriptHtmlHeadEntry(
			'packages/store/javascript/store-product-page.js',
			Store::PACKAGE_ID));

		if (isset($this->items_view))
			$this->layout->addHtmlHeadEntrySet(
				$this->items_view->getHtmlHeadEntrySet());

		$this->layout->addHtmlHeadEntry(new SwatStyleSheetHtmlHeadEntry(
			'packages/store/styles/store-product-page.css',
			Store::PACKAGE_ID));

		$this->layout->addHtmlHeadEntry(new SwatJavaScriptHtmlHeadEntry(
			'packages/store/javascript/store-background-image-animation.js',
			Store::PACKAGE_ID));

		$this->layout->addHtmlHeadEntrySet(
			$this->message_display->getHtmlHeadEntrySet());

		$this->layout->addHtmlHeadEntrySet(
			$this->cart_ui->getRoot()->getHtmlHeadEntrySet());
	}

	// }}}
}

?>
