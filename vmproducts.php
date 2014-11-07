<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.Content
 *
 * @copyright   Copyright (C) 2005 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_BASE') or die;

jimport('joomla.application.component.helper');

// Load the base adapter.
require_once JPATH_ADMINISTRATOR . '/components/com_finder/helpers/indexer/adapter.php';

/**
 * Finder adapter for com_content.
 *
 * @package     Joomla.Plugin
 * @subpackage  Finder.Content
 * @since       2.5
 */
class plgFinderVmproducts extends FinderIndexerAdapter
{
	/**
	 * The plugin identifier.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $context = 'Vm Product';

	/**
	 * The extension name.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $extension = 'com_virtuemart';

	/**
	 * The sublayout to use when rendering the results.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $layout = 'product';

	/**
	 * The type of content that the adapter indexes.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $type_title = 'VM Product';

	/**
	 * The table name.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $table = '#__virutemart_products';

	/**
	 * Constructor
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An array that holds the plugin configuration
	 *
	 * @since   2.5
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	/**
	 * Method to update the item link information when the item category is
	 * changed. This is fired when the item category is published or unpublished
	 * from the list view.
	 *
	 * @param   string   $extension  The extension whose category has been updated.
	 * @param   array    $pks        A list of primary key ids of the content that has changed state.
	 * @param   integer  $value      The value of the state that the content has been changed to.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onFinderCategoryChangeState($extension, $pks, $value)
	{
		// Make sure we're handling com_content categories
		if ($extension == 'com_virtuemart')
		{
			$this->categoryStateChange($pks, $value);
		}
	}

	/**
	 * Method to remove the link information for items that have been deleted.
	 *
	 * @param   string  $context  The context of the action being performed.
	 * @param   JTable  $table    A JTable object containing the record to be deleted
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterDelete($context, $table)
	{
		if ($context == 'com_virtuemart.product')
		{
			$id = $table->id;
		}
		elseif ($context == 'com_finder.index')
		{
			$id = $table->link_id;
		}
		else
		{
			return true;
		}
		// Remove the items.
		return $this->remove($id);
	}

	/**
	 * Method to determine if the access level of an item changed.
	 *
	 * @param   string   $context  The context of the content passed to the plugin.
	 * @param   JTable   $row      A JTable object
	 * @param   boolean  $isNew    If the content has just been created
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterSave($context, $row, $isNew)
	{
		// We only want to handle articles here
		if ($context == 'com_virtuemart.product')
		{
			// Check if the access levels are different
			if (!$isNew && $this->old_access != $row->access)
			{
				// Process the change.
				$this->itemAccessChange($row);
			}

			// Reindex the item
			$this->reindex($row->id);
		}

		// Check for access changes in the category
		/*
		if ($context == 'com_categories.category')
		{
			// Check if the access levels are different
			if (!$isNew && $this->old_cataccess != $row->access)
			{
				$this->categoryAccessChange($row);
			}
		}
		*/

		return true;
	}

	/**
	 * Method to reindex the link information for an item that has been saved.
	 * This event is fired before the data is actually saved so we are going
	 * to queue the item to be indexed later.
	 *
	 * @param   string   $context  The context of the content passed to the plugin.
	 * @param   JTable   $row     A JTable object
	 * @param   boolean  $isNew    If the content is just about to be created
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderBeforeSave($context, $row, $isNew)
	{
		// We only want to handle articles here
		if ($context == 'com_virtuemart.product')
		{
			// Query the database for the old access level if the item isn't new
			if (!$isNew)
			{
				$this->checkItemAccess($row);
			}
		}

		// Check for access levels from the category
		/*
		if ($context == 'com_categories.category')
		{
			// Query the database for the old access level if the item isn't new
			if (!$isNew)
			{
				$this->checkCategoryAccess($row);
			}
		}
		*/

		return true;
	}

	/**
	 * Method to update the link information for items that have been changed
	 * from outside the edit screen. This is fired when the item is published,
	 * unpublished, archived, or unarchived from the list view.
	 *
	 * @param   string   $context  The context for the content passed to the plugin.
	 * @param   array    $pks      A list of primary key ids of the content that has changed state.
	 * @param   integer  $value    The value of the state that the content has been changed to.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onFinderChangeState($context, $pks, $value)
	{
		// We only want to handle articles here
		if ($context == 'com_virtuemart.product')
		{
			$this->itemStateChange($pks, $value);
		}
		// Handle when the plugin is disabled
		if ($context == 'com_plugins.plugin' && $value === 0)
		{
			$this->pluginDisable($pks);
		}
	}

	/**
	 * Method to index an item. The item must be a FinderIndexerResult object.
	 *
	 * @param   FinderIndexerResult  $item    The item to index as an FinderIndexerResult object.
	 * @param   string               $format  The item format
	 *
	 * @return  void
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	protected function index(FinderIndexerResult $item, $format = 'html')
	{
		// Check if the extension is enabled
		if (JComponentHelper::isEnabled($this->extension) == false)
		{
			return;
		}

		// Initialize the item parameters.
		$registry = new JRegistry;
		$registry->loadString($item->params);
		$item->params = JComponentHelper::getParams('com_virtuemart', true);
		$item->params->merge($registry);

		$registry = new JRegistry;
		$registry->loadString($item->metadata);
		$item->metadata = $registry;

		// Trigger the onContentPrepare event.
		$item->summary = FinderIndexerHelper::prepareContent($item->summary, $item->params);
		$item->body = FinderIndexerHelper::prepareContent($item->body, $item->params);

		// Build the necessary route and path information.
		$item->url 	= "index.php?option=com_virtuemart&view=productdetails&virtuemart_category_id=206&virtuemart_product_id=".$item->id;
		$item->route 	= "index.php?option=com_virtuemart&view=productdetails&virtuemart_category_id=206&virtuemart_product_id=".$item->id;
		$item->path 	= FinderIndexerHelper::getContentPath($item->route);

		// Get the menu title if it exists.
		$title = $this->getItemMenuTitle($item->url);

		// Adjust the title if necessary.
		if (!empty($title) && $this->params->get('use_menu_title', true))
		{
			$item->title = $title;
		}

		// Add the meta-author.
		$item->metaauthor = $item->metadata->get('author');

		// Add the meta-data processing instructions.
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'metakey');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'metadesc');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'metaauthor');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'author');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'created_by_alias');

		// Translate the state. Articles should only be published if the category is published.
		$item->state 		= 1;
		$item->cat_state 	= 1;
		$item->cat_access 	= 1;
		$item->access 		= 1;

		// Add the type taxonomy data.
		$item->addTaxonomy('Type', 'VM Product');

		// Add the category taxonomy data.
		$item->addTaxonomy('Category', $item->category, 1, 1);

		// Add the language taxonomy data.
		$item->addTaxonomy('Language', $item->language);

		// Get content extras.
		FinderIndexerHelper::getContentExtras($item);

		// Index the item.
		FinderIndexer::index($item);
	}

	/**
	 * Method to setup the indexer to be run.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 */
	protected function setup()
	{
		// Load dependent classes.
		include_once JPATH_SITE . '/components/com_virtuemart/route.php';

		return true;
	}

	/**
	 * Method to get the SQL query used to retrieve the list of content items.
	 *
	 * @param   mixed  $sql  A JDatabaseQuery object or null.
	 *
	 * @return  JDatabaseQuery  A database object.
	 *
	 * @since   2.5
	 */
	protected function getListQuery($sql = null) {
		$db = JFactory::getDbo();
		// Check if we can use the supplied SQL query.
		$sql = $sql instanceof JDatabaseQuery ? $sql : $db->getQuery(true);
		$sql->select('p.virtuemart_product_id AS id, p.published');
		$sql->select('p_eng.product_name AS title, p_eng.slug AS alias, p_eng.product_desc AS summary');
		// $sql->select('p.created_user_id AS created_by, a.modified_time AS modified, a.modified_user_id AS modified_by');
		$sql->select('p_eng.metakey, p_eng.metadesc, p.metaauthor, p.metarobot');
		$sql->select('p.created_on AS start_date, p.published AS state');
		// $sql->select('a.access');

		// Handle the alias CASE WHEN portion of the query
		$sql->join('LEFT', '#__virtuemart_products_en_gb AS p_eng ON p.virtuemart_product_id = p_eng.virtuemart_product_id');
		//$sql->select($case_when_item_alias);
		$sql->from('#__virtuemart_products AS p');
		$sql->where( $db->quoteName('p.virtuemart_product_id') . ' > 1' );
		
		//print_r($sql);
		//die();
		
		return $sql;
	}
	protected function getStateQuery() {
		$sql = $this->db->getQuery(true);
		$sql->select($this->db->quoteName('p.virtuemart_product_id AS id'));
		$sql->join('LEFT', '#__virtuemart_category AS p_eng ON p.virtuemart_product_id = p_eng.virtuemart_product_id');
		$sql->select($this->db->quoteName('p.published') . ' AS cat_state');
		$sql->select($this->db->quoteName('a.access') . ' AS cat_access');
		$sql->from($this->db->quoteName('#__virtuemart_products') . ' AS p');

		return $sql;
	}
}
