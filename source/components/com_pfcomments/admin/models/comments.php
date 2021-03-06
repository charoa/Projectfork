<?php
/**
 * @package      Projectfork
 * @subpackage   Comments
 *
 * @author       Tobias Kuhn (eaxs)
 * @copyright    Copyright (C) 2006-2012 Tobias Kuhn. All rights reserved.
 * @license      http://www.gnu.org/licenses/gpl.html GNU/GPL, see LICENSE.txt
 */

defined('_JEXEC') or die();


jimport('joomla.application.component.modellist');


/**
 * Methods supporting a list of comments.
 *
 */
class PFcommentsModelComments extends JModelList
{
    /**
     * Constructor
     *
     * @param     array    An optional associative array of configuration settings.
     *
     * @return    void
     */
    public function __construct($config = array())
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = array(
                'a.id', 'a.project_id', 'project_title',
                'a.title', 'a.description', 'a.created',
                'a.created_by', 'a.modified',
                'a.modified_by', 'a.checked_out',
                'a.checked_out_time', 'a.attribs',
                'a.access', 'access_level',
                'a.state, a.context, a.lft'
            );
        }

        parent::__construct($config);
    }


    /**
     * Build an SQL query to load the list data.
     *
     * @return    jdatabasequery
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);
        $user  = JFactory::getUser();

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'a.id, a.project_id, a.title, a.description, a.checked_out, '
                . 'a.context, a.checked_out_time, a.state, a.created, a.created_by, '
                . 'a.parent_id, a.lft, a.rgt, a.level'
            )
        );
        $query->from('#__pf_comments AS a');

        // Do not include the root node
        $query->where('a.alias != ' . $db->quote('root'));

        // Join over the users for the checked out user.
        $query->select('uc.name AS editor');
        $query->join('LEFT', '#__users AS uc ON uc.id = a.checked_out');

        // Join over the users for the author.
        $query->select('ua.name AS author_name');
        $query->join('LEFT', '#__users AS ua ON ua.id = a.created_by');

        // Join over the projects for the project title.
        $query->select('p.title AS project_title');
        $query->join('LEFT', '#__pf_projects AS p ON p.id = a.project_id');

        // Implement View Level Access
        if (!$user->authorise('core.admin')) {
            $groups = implode(',', $user->getAuthorisedViewLevels());
            $query->where('a.access IN (' . $groups . ')');
        }

        // Filter by project
        $project = $this->getState('filter.project');
        if (is_numeric($project) && $project != 0) {
            $query->where('a.project_id = ' . (int) $project);
        }

        // Filter by published state
        $published = $this->getState('filter.published');
        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        }
        elseif ($published === '') {
            $query->where('(a.state = 0 OR a.state = 1)');
        }

        // Filter by author
        $author_id = $this->getState('filter.author_id');
        if (is_numeric($author_id)) {
            $type = $this->getState('filter.author_id.include', true) ? '= ' : '<>';
            $query->where('a.created_by ' . $type . (int) $author_id);
        }

        // Filter by context
        $context = $this->getState('filter.context');
        if (!empty($context)) {
            $query->where('a.context = ' . $db->quote($context));
        }

        // Filter by item_id
        $item_id = $this->getState('filter.item_id');
        if (is_numeric($item_id)) {
            $query->where('a.item_id = ' . $db->quote($item_id));
        }

        // Filter by search in title.
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = '.(int) substr($search, 3));
            }
            elseif (stripos($search, 'author:') === 0) {
                $search = $db->Quote('%' . $db->escape(substr($search, 7), true).'%');
                $query->where('(ua.name LIKE ' . $search . ' OR ua.username LIKE ' . $search . ')');
            }
            else {
                $search = $db->Quote('%' . $db->escape($search, true).'%');
                $query->where('(a.title LIKE ' . $search . ' OR a.alias LIKE ' . $search . ')');
            }
        }

        // Add the list ordering clause.
        $order_col = $this->state->get('list.ordering', 'a.created');
        $order_dir = $this->state->get('list.direction', 'desc');

        if ($order_col != 'a.lft') {
            $order_col = $order_col .  ' ' . $order_dir . ', a.lft';
        }

        $query->order($db->escape($order_col . ' ' . $order_dir));

        // Group by topic id
        $query->group('a.id');

        return $query;
    }


    /**
     * Build a list of project authors
     *
     * @return    jdatabasequery
     */
    public function getAuthors()
    {
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);

        if ((int) $this->getState('filter.project') == 0) {
            return array();
        }

        // Construct the query
        $query->select('u.id AS value, u.name AS text')
              ->from('#__users AS u')
              ->join('INNER', '#__pf_comments AS a ON a.created_by = u.id')
              ->group('u.id')
              ->order('u.name');

        // Setup the query
        $db->setQuery((string) $query);

        // Return the result
        return $db->loadObjectList();
    }


    /**
     * Build a list of context options
     *
     * @return    jdatabasequery
     */
    public function getContexts()
    {
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);

        // Construct the query
        $query->select('DISTINCT a.context')
              ->from('#__pf_comments AS a')
              ->where('a.alias != ' . $db->quote('root'))
              ->order('a.context ASC');

        // Setup the query
        $db->setQuery((string) $query);
        $items = (array) $db->loadResultArray();

        $options = array();

        foreach($items AS $value)
        {
            $obj     = new stdClass();
            $context = str_replace('.', '_', strtoupper($value)) . '_TITLE';

            $obj->value = $value;
            $obj->text  = JText::_($context);

            $options[] = $obj;
        }

        // Return the result
        return $options;
    }


    /**
     * Build a list of context options
     *
     * @return    jdatabasequery
     */
    public function getContextItems()
    {
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);

        $context = $this->getState('filter.context');
        $project = $this->getState('filter.project');

        // Context and project filters must be set.
        if (empty($context) || intval($project) == 0) {
            return array();
        }

        // Construct the query
        $query->select('a.item_id AS value, a.title AS text')
              ->from('#__pf_comments AS a')
              ->where('a.context = ' . $db->quote($context))
              ->where('a.project_id = ' . $db->quote($project))
              ->where('a.alias != ' . $db->quote('root'))
              ->group('a.item_id')
              ->order('a.title ASC');

        // Setup the query
        $db->setQuery((string) $query);
        $options = (array) $db->loadObjectList();

        // Return the result
        return $options;
    }


    /**
     * Method to auto-populate the model state.
     * Note: Calling getState in this method will result in recursion.
     *
     * @return    void
     */
    protected function populateState($ordering = 'a.created', $direction = 'desc')
    {
        // Initialise variables.
        $app = JFactory::getApplication();

        // Adjust the context to support modal layouts.
        if ($layout = JRequest::getVar('layout')) $this->context .= '.' . $layout;

        $search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
        $this->setState('filter.search', $search);

        $author_id = $app->getUserStateFromRequest($this->context . '.filter.author_id', 'filter_author_id');
        $this->setState('filter.author_id', $author_id);

        $published = $this->getUserStateFromRequest($this->context . '.filter.published', 'filter_published', '');
        $this->setState('filter.published', $published);

        $context = $this->getUserStateFromRequest($this->context . '.filter.context', 'filter_context', '');
        $this->setState('filter.context', $context);

        $project = PFApplicationHelper::getActiveProjectId('filter_project');
        $this->setState('filter.project', $project);

        PFApplicationHelper::setActiveProject($project);

        $item_id = $this->getUserStateFromRequest($this->context . '.filter.item_id', 'filter_item_id', '');
        $this->setState('filter.item_id', $item_id);

        // Do no allow to filter by item id if no context or project is given
        if (empty($context) || intval($project) == 0) {
            $item_id = '';
            $this->setState('filter.item_id', $item_id);
        }

        // List state information.
        parent::populateState($ordering, $direction);
    }


    /**
     * Method to get a store id based on model configuration state.
     *
     * This is necessary because the model is used by the component and
     * different modules that might need different sets of data or different
     * ordering requirements.
     *
     * @param     string    $id    A prefix for the store id.
     *
     * @return    string           A store id.
     */
    protected function getStoreId($id = '')
    {
        // Compile the store id.
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.published');
        $id .= ':' . $this->getState('filter.access');
        $id .= ':' . $this->getState('filter.author_id');
        $id .= ':' . $this->getState('filter.project');

        return parent::getStoreId($id);
    }
}
