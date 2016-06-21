<?php

namespace Cgit;

class AcfMetaSearch
{
    /**
     * Singleton instance.
     *
     * @var null
     */
    private static $instance;

    /**
     * Available ACF fields.
     *
     * @var array
     */
    private $fields = [];

    /**
     * Searchable ACF fields.
     *
     * @var array
     */
    private $searchable_fields = [];

    /**
     * Searchable meta_key values.
     *
     * @var array
     */
    private $meta_keys = [];

    /**
     * Constructor.
     *
     * @return void
     */
    private function __construct()
    {
        $this->buildFieldList();
        $this->buildMetaKeyList();
        $this->applyFilters();
    }

    /**
     * Return instance
     *
     * return Cgit\AcfMetaSearch
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Build a list of all searchable fields.
     *
     * @return void
     */
    private function buildFieldList()
    {
        // Ensure the ACF functions are available.
        if (!function_exists('acf_local') || !isset(acf_local()->fields)) {
            return;
        }

        // Available fields.
        $this->fields = acf_local()->fields;

        // Filter down to searchable fields only.
        $this->searchable_fields = array_filter(
            $this->fields,
            function ($a) {
                return isset($a['searchable']) && $a['searchable'];
            }
        );
    }

    /**
     * Takes the ACF field array and constructs a regular expression to match
     * each searchable field in the postmeta table. ACF stores each field name
     * individually, however repeaters and nested repeaters create meta_keys
     * which combine the field key, and parent keys such as:
     *
     * parent_name_0_subfield_name_0_another_subfield.
     *
     * A regular expression is created to match such meta_keys and all unique
     * searchable meta keys are queried from the database.
     *
     * @return void
     */
    private function buildMetaKeyList()
    {
        // Build the regular expressions for each field.
        foreach ($this->searchable_fields as $key => $field) {
            $this->searchable_fields[$key]['regex'] = '^'.$this->getMetaKeyRegex($field) . '$';
        }

        //Find a true list of all available searchable meta_key values.
        global $wpdb;

        $sql = "SELECT DISTINCT meta_key FROM " . $wpdb->prefix . "postmeta WHERE ";

        foreach ($this->searchable_fields as $field) {
            $sql.= "meta_key REGEXP '" . $field['regex'] . "' OR ";
        }

        $sql = substr($sql, 0, -4);

        $results = $wpdb->get_results($sql);

        // Take the query results and populate an of searchable meta_keys
        $this->meta_keys = array_map(
            function ($a) {
                return $a->meta_key;
            },
            $results
        );
    }

    /**
     * Applies any necessary filters.
     *
     * @return void
     */
    private function applyFilters()
    {
        $this->filterSearchQuery();
        $this->filterMetaWhere();
    }

    /**
     * Filters the WordPress search query to include meta_values.
     *
     * @return void
     */
    private function filterSearchQuery()
    {
        $func = function ($query) {
            if (!is_admin() && $query->is_search) {
                $meta = ['relation' => 'OR'];

                // Add each field
                foreach ($this->meta_keys as $field) {
                    $meta[] = [
                        'key' => $field,
                        'value' => $query->query_vars['s'],
                        'compare' => 'LIKE',
                        'type' => 'CHAR',
                    ];
                }

                $query->set('meta_query', $meta);
            }

            return $query;
        };

        add_filter('pre_get_posts', $func);
    }

    /**
     * Filters the search meta query SQL to change the 'AND' clause into an 'OR'
     * clause. By default any meta query is appended using 'AND'.
     *
     * @return void
     */
    private function filterMetaWhere()
    {
        $func = function ($sql) {
            if (is_search()) {
                $sql['where'] = preg_replace('/^\s+AND\s+/', ' OR ', $sql['where']);
            }
            return $sql;
        };

        add_filter('get_meta_sql', $func);
    }

    /**
     * Recursively build a meta_key regular expression by appending parent
     * field names to each field.
     *
     * @param array $field
     *
     * @return string
     */
    private function getMetaKeyRegex($field)
    {
        $parent = $field['parent'];

        if (array_key_exists($parent, $this->fields) && $this->fields[$parent]['type'] == 'repeater') {
            return $this->getMetaKeyRegex($this->fields[$parent]) . '_[0-9]+_' . $field['key'];
        } else {
            return $field['key'];
        }
    }
}
