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

class plgFinderVmproducts extends FinderIndexerAdapter
{
	protected $context = 'Vm Product';
	protected $extension = 'com_virtuemart';
	protected $layout = 'product';
	protected $type_title = 'VM Product';
	protected $table = '#__virutemart_products';
	protected $state_field = 'published';

	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	public function onFinderCategoryChangeState($extension, $pks, $value)
	{
		// Make sure we're handling com_content categories
		if ($extension == 'com_virtuemart')
		{
			$this->categoryStateChange($pks, $value);
		}
	}

	public function onFinderAfterDelete($context, $table)
	{
		if ($context == 'com_virtuemart.product')
		{
			$id = $table->id;
		}
		else if ($context == 'com_finder.index')
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

		return true;
	}

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

		return true;
	}

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
		$item->body    = FinderIndexerHelper::prepareContent($item->body, $item->params);

		// Build the necessary route and path information.
		$item->url   = "index.php?option=com_virtuemart&view=productdetails&virtuemart_category_id=" . $item->catid . "&virtuemart_product_id=" . $item->id;
		$item->route = "index.php?option=com_virtuemart&view=productdetails&virtuemart_category_id=" . $item->catid . "&virtuemart_product_id=" . $item->id;
		$item->path  = FinderIndexerHelper::getContentPath($item->route);

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
		$item->state      = 1;
		$item->cat_state  = 1;
		$item->cat_access = 1;
		$item->access     = 1;

		// Add the type taxonomy data.
		$item->addTaxonomy('Type', 'VM Product');

		// Add the category taxonomy data.
		$item->addTaxonomy('VM Category', 	$item->category, 		1, 1);
		
		// Add the category taxonomy data.
		$item->addTaxonomy('VM Manufacturer', $item->manufacturer, 	1, 1);

		// Add the language taxonomy data.
		$item->addTaxonomy('Language', 	$item->language);

		// Get content extras.
		FinderIndexerHelper::getContentExtras($item);

		// Index the item.
		$this->indexer->index($item);
	}

	protected function setup()
	{
		// Load com_content route helper as it is the fallback for routing in the indexer in this instance.
		include_once JPATH_SITE . '/components/com_content/helpers/route.php';

		return true;
	}

protected function getListQuery($sql = null)
	{

		// Get VirtueMart Language config
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('config');
		$query->from('#__virtuemart_configs');
		$query->where('virtuemart_config_id = 1');
		$db->setQuery($query);
		$config           = $db->loadResult();
		$active_languages = substr($config, strpos($config, 'active_languages='));
		$active_languages = substr($active_languages, 0, strpos($active_languages, '|'));

		if (preg_match('/"([^"]+)"/', $active_languages, $match))
		{
			$language = str_replace('-', '_', strtolower($match[1]));
		}
		else
		{
			$language = 'en_gb';
		}

		$db = JFactory::getDbo();
		// Check if we can use the supplied SQL query.
		$sql = is_a($sql, 'JDatabaseQuery') ? $sql : $db->getQuery(true);
		$sql->select('p.virtuemart_product_id 			AS id, p.published');
		$sql->select('
					  p_lang.product_name 			AS title 
					, p_lang.slug 				AS alias
					, p_lang.product_desc 			AS summary
		');
		$sql->select('
					  p_lang.metakey
					, p_lang.metadesc
					, p.metaauthor
					, p.metarobot
		');
		$sql->select('p.created_on 					AS start_date, p.published AS state');
		$sql->select('
					  c.virtuemart_category_id 		AS catid
					, c.category_name 			AS category
		');
		$sql->select('
					  m.virtuemart_manufacturer_id 		AS manid
					, m.mf_name				AS manufacturer
		');

		// Handle the alias CASE WHEN portion of the query
		$sql->join('LEFT', '#__virtuemart_products_' . $language . ' 		AS p_lang 	ON p.virtuemart_product_id 			= p_lang.virtuemart_product_id');
		$sql->join('LEFT', '#__virtuemart_product_categories 			AS xref 	ON xref.virtuemart_product_id 		= p.virtuemart_product_id');
		$sql->join('LEFT', '#__virtuemart_categories				AS cat 		ON cat.virtuemart_category_id 		= xref.virtuemart_category_id');
		$sql->join('LEFT', '#__virtuemart_categories_' . $language . ' 		AS c 		ON c.virtuemart_category_id 			= xref.virtuemart_category_id');
		$sql->join('LEFT', '#__virtuemart_product_manufacturers			AS mxref 	ON mxref.virtuemart_product_id 		= p.virtuemart_product_id');
		$sql->join('LEFT', '#__virtuemart_manufacturers_' . $language . ' 	AS m 		ON p.virtuemart_product_id 			= mxref.virtuemart_product_id');
		$sql->from('#__virtuemart_products AS p');
		$sql->where($db->quoteName('p.virtuemart_product_id') . ' > 1');
		$sql->where( $db->quoteName('p.published') . ' = 1' );
		$sql->where( $db->quoteName('cat.published') . ' = 1' );

		return $sql;
	}

	protected function getStateQuery()
	{
		$sql = $this->db->getQuery(true);
		$sql->select($this->db->quoteName('p.virtuemart_product_id AS id'));
		$sql->join('LEFT', '#__virtuemart_category 				AS p_lang ON p.virtuemart_product_id = p_lang.virtuemart_product_id');
		$sql->select($this->db->quoteName('p.published') . ' 			AS cat_state');
		$sql->select($this->db->quoteName('a.access') . ' 			AS cat_access');
		$sql->from($this->db->quoteName('#__virtuemart_products') . ' AS p');

		return $sql;
	}
}
